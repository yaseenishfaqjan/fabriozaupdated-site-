<?php
/**
 * FABRIOZA CRM - Gmail inbox -> CRM importer (CLI only).
 *
 * Uses PHP's built-in curl over IMAPS (no imap extension needed).
 * Every run scans the Gmail INBOX with the same App Password as SMTP:
 *   - Sender matches an existing lead -> logs an "Inbox email" note on the
 *     lead, pauses sequences, promotes new -> quoted (same effect as the
 *     manual "Log reply received" button), audits reply_received_auto.
 *   - Unknown sender -> creates a new lead (form_type "Inbox Email").
 *   - Own/automated senders skipped; each message processed once
 *     (mail_seen keyed by Message-ID).
 *
 * Usage:
 *   php import-inbox.php            # incremental: last 3 days
 *   php import-inbox.php --all      # historic backfill: whole inbox
 *   php import-inbox.php --dry-run  # preview, change nothing
 *
 * Cron: every 30 minutes - exact line documented in CRM-HANDOFF.md
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
require __DIR__ . '/db.php';

$user = getenv('SMTP_USER') ?: '';
$pass = getenv('SMTP_PASS') ?: '';
if ($user === '' || $pass === '') { fwrite(STDERR, "SMTP_USER / SMTP_PASS not configured\n"); exit(1); }

$all = in_array('--all', $argv, true);
$dry = in_array('--dry-run', $argv, true);

$SKIP_SENDERS = ['fabriozadotcom@gmail.com', 'info@fabrioza.com', 'sales@fabrioza.com'];
$SKIP_PATTERNS = ['noreply', 'no-reply', 'no_reply', 'mailer-daemon', 'postmaster', 'notification',
    'newsletter', 'donotreply', 'automated', '@google.com', '@youtube.com', '@facebookmail.com',
    '@linkedin.com', '@amazonses.com', '@sendgrid', '@mailchimp', 'support@', 'billing@', 'accounts@',
    // bulk-mail subdomains (send.calendly.com, mail.consensus.app, email.heygen.com ...)
    '@send.', '@mail.', '@email.', '@e.', '@news.', '@marketing.', '@updates.', '@notify.',
    '@info.', '@go.', '@hello.', '@reply.', '@post.', '@em.', '@mailer.', '@newsletter.',
    // known SaaS/marketing senders + own other businesses
    'calendly', 'heygen', 'consensus.app', '@cal.com', 'nodevant',
    'stripe.com', 'paypal.com', 'payoneer', 'shopify', 'wix.com', 'godaddy', 'namecheap',
    'hostinger', 'contabo', 'cloudflare', 'semrush', 'canva', 'figma', 'openai', 'anthropic',
    'pinterest', 'instagram', 'facebook', 'twitter.com', 'x.com', 'tiktok', 'meta.com', 'snapchat',
    'upwork', 'fiverr', 'freelancer.com', 'alibaba', 'aliexpress', 'made-in-china', 'dhgate',
    'indeed', 'glassdoor', 'zoom.us', 'slack.com', 'notion.so', 'dropbox', 'adobe.com',
    'microsoft.com', 'apple.com', 'amazon.com', 'ebay.com', 'etsy.com', 'reddit.com', 'quora.com',
    'medium.com', 'substack', 'mailchimp', 'hubspot', 'salesforce', 'zendesk', 'intercom',
    'marketing@', 'promo', 'deals@', 'offers@', 'digest@', 'team@', 'community@', 'events@',
    'webinar', 'invoice', 'receipt', 'welcome@', 'hello@tradingview', 'tradingview',
    'shotstack', 'cloudinary', 'supabase', 'columbia.edu', 'coursera', 'udemy', 'vercel',
    'netlify', 'github', 'gitlab', 'digitalocean', 'aws.amazon', 'railway.app', 'render.com'];
// extra skips without code changes: CRM_INBOX_SKIP=comma,separated,patterns in .env
foreach (array_filter(array_map('trim', explode(',', getenv('CRM_INBOX_SKIP') ?: ''))) as $extra) {
    $SKIP_PATTERNS[] = strtolower($extra);
}

/** One IMAP command via curl. $urlPath is appended to the mailbox URL. */
function imap_curl(string $user, string $pass, string $urlPath = '', ?string $customRequest = null): ?string {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => 'imaps://imap.gmail.com:993/' . $urlPath,
        CURLOPT_USERPWD => $user . ':' . $pass,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_CONNECTTIMEOUT => 15,
    ]);
    if ($customRequest !== null) { curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $customRequest); }
    $res = curl_exec($ch);
    if ($res === false) {
        fwrite(STDERR, 'IMAP curl error: ' . curl_error($ch) . "\n");
        curl_close($ch);
        return null;
    }
    curl_close($ch);
    return (string)$res;
}

/** Decode a MIME-encoded header value to UTF-8, best effort. */
function hdr_decode(string $v): string {
    $d = @iconv_mime_decode($v, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
    return trim($d !== false ? $d : $v);
}

/** Parse "Name <addr>" / bare addr out of a From: header. Returns [name, addr]. */
function parse_from(string $from): array {
    if (preg_match('/<([^>]+)>/', $from, $m)) {
        $addr = strtolower(trim($m[1]));
        $name = trim(str_replace($m[0], '', $from), " \t\"'");
        return [hdr_decode($name), $addr];
    }
    return ['', strtolower(trim($from, " \t<>\"'"))];
}

$db = crm_db();

/* --prune: delete previously imported Inbox Email leads whose sender
   matches the (now stricter) skip list. Safe to run repeatedly; pruned
   messages stay in mail_seen so they are never re-imported. */
if (in_array('--prune', $argv, true)) {
    $pruned = 0;
    foreach ($db->query("SELECT id, email FROM leads WHERE form_type = 'Inbox Email'")->fetchAll() as $r) {
        $addr = strtolower($r['email']);
        $bad = in_array($addr, $SKIP_SENDERS, true);
        foreach ($SKIP_PATTERNS as $p) { if (!$bad && strpos($addr, $p) !== false) { $bad = true; } }
        if ($bad) {
            $db->prepare('DELETE FROM leads WHERE id = ?')->execute([$r['id']]);
            $db->prepare('INSERT INTO audit_log (action, lead_id, actor) VALUES (?,?,?)')
               ->execute(['lead_pruned_junk', $r['id'], 'inbox-import']);
            echo "pruned junk lead #{$r['id']} <{$r['email']}>
";
            $pruned++;
        }
    }
    echo "prune done: $pruned junk lead(s) removed
";
    exit(0);
}

/* 1. UID SEARCH */
$criteria = $all ? 'UID SEARCH ALL' : 'UID SEARCH SINCE ' . date('j-M-Y', strtotime('-3 days'));
$out = imap_curl($user, $pass, 'INBOX', $criteria);
if ($out === null) { exit(1); }
preg_match('/\* SEARCH([\d ]*)/i', $out, $m);
$uids = array_filter(array_map('intval', preg_split('/\s+/', trim($m[1] ?? ''))));
echo 'scanning ' . count($uids) . " message(s) [$criteria]\n";

$replies = $created = $skipped = 0;
foreach ($uids as $uid) {
    /* 2. headers */
    $raw = imap_curl($user, $pass, "INBOX;UID=$uid;SECTION=HEADER.FIELDS%20(FROM%20SUBJECT%20MESSAGE-ID)");
    if ($raw === null || trim($raw) === '') { continue; }
    $raw = preg_replace("/\r\n[ \t]+/", ' ', $raw);          // unfold headers
    $H = [];
    foreach (explode("\r\n", $raw) as $line) {
        if (preg_match('/^([A-Za-z-]+):\s*(.*)$/', $line, $hm)) { $H[strtolower($hm[1])] = $hm[2]; }
    }
    [$fromName, $fromAddr] = parse_from($H['from'] ?? '');
    if ($fromAddr === '' || strpos($fromAddr, '@') === false) { continue; }
    $subject = hdr_decode($H['subject'] ?? '(no subject)');
    $msgId = trim($H['message-id'] ?? '') ?: ('uid-' . $uid . '-' . md5($fromAddr . $subject));

    $seen = $db->prepare('SELECT 1 FROM mail_seen WHERE message_id = ?');
    $seen->execute([$msgId]);
    if ($seen->fetchColumn()) { continue; }

    $skip = in_array($fromAddr, $SKIP_SENDERS, true);
    foreach ($SKIP_PATTERNS as $p) { if (!$skip && strpos($fromAddr, $p) !== false) { $skip = true; } }
    if ($skip) {
        if (!$dry) { $db->prepare('INSERT OR IGNORE INTO mail_seen (message_id) VALUES (?)')->execute([$msgId]); }
        $skipped++;
        continue;
    }

    /* 3. body snippet (first MIME part, then whole text fallback) */
    $body = imap_curl($user, $pass, "INBOX;UID=$uid;SECTION=1") ?? '';
    if (trim($body) === '') { $body = imap_curl($user, $pass, "INBOX;UID=$uid;SECTION=TEXT") ?? ''; }
    $body = quoted_printable_decode($body);
    if (preg_match('/^[A-Za-z0-9+\/=\r\n]+$/', trim($body)) && strlen(trim($body)) > 40) {
        $maybe = base64_decode(trim($body), true);
        if ($maybe !== false && mb_check_encoding($maybe, 'UTF-8')) { $body = $maybe; }
    }
    $body = trim(preg_replace('/\s+/', ' ', strip_tags($body)));
    $snippet = mb_substr($body, 0, 500);

    /* 4. route */
    $lead = $db->prepare('SELECT id, status FROM leads WHERE lower(email) = ? ORDER BY id DESC LIMIT 1');
    $lead->execute([$fromAddr]);
    $L = $lead->fetch();

    if ($dry) {
        echo 'DRY: ' . ($L ? "reply -> lead #{$L['id']}" : 'NEW lead') . " from $fromAddr | $subject\n";
        continue;
    }

    if ($L) {
        $db->prepare('INSERT INTO notes (lead_id, author, body) VALUES (?,?,?)')
           ->execute([$L['id'], 'inbox-import', mb_substr("Inbox email: \"$subject\" - $snippet", 0, 4000)]);
        $db->prepare("UPDATE leads SET sequences_paused = 1,
                status = CASE WHEN status = 'new' THEN 'quoted' ELSE status END WHERE id = ?")
           ->execute([$L['id']]);
        $db->prepare('INSERT INTO audit_log (action, lead_id, actor) VALUES (?,?,?)')
           ->execute(['reply_received_auto', $L['id'], 'inbox-import']);
        echo "reply logged -> lead #{$L['id']} <$fromAddr>\n";
        $replies++;
    } else {
        $name = $fromName !== '' ? mb_substr($fromName, 0, 120) : ucfirst(explode('@', $fromAddr)[0]);
        $score = crm_lead_score(['name' => $name, 'email' => $fromAddr, 'company' => '',
            'quantity' => '', 'form_type' => 'Inbox Email', 'utm_medium' => 'email']);
        $db->prepare('INSERT INTO leads (name, email, message, form_type, source_page, lead_score, status, gdpr_consent, ip_hash)
                      VALUES (?,?,?,?,?,?,?,0,?)')
           ->execute([$name, $fromAddr, mb_substr("\"$subject\" - $snippet", 0, 5000),
                      'Inbox Email', 'gmail-inbox', $score, 'new', '']);
        echo 'NEW lead #' . $db->lastInsertId() . " <$fromAddr> | $subject\n";
        $created++;
    }
    $db->prepare('INSERT OR IGNORE INTO mail_seen (message_id) VALUES (?)')->execute([$msgId]);
}
crm_file_log("INBOX-IMPORT replies=$replies new=$created skipped=$skipped scanned=" . count($uids));
echo "done: $replies replies logged, $created leads created, $skipped skipped\n";
