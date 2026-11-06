<?php
/**
 * FABRIOZA CRM - mailbox -> CRM importer (CLI only).
 *
 * Reads the info@fabrioza.com mailbox on the cPanel server (IMAP_HOST, default
 * s99.veladns.com) using the same credentials as SMTP.
 *
 * Uses PHP's built-in curl over IMAPS (no imap extension needed).
 * Every run scans the INBOX with the same credentials as SMTP:
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

require_once __DIR__ . '/lib/inbox_extract.php';
require_once __DIR__ . '/lib/lead_tags.php';

/* IMAP credentials are SEPARATE from SMTP, falling back to it.
 *
 * They used to be the same pair, which made a second mailbox impossible.
 * Sending goes through Resend now - mailer.php prefers it and keeps SMTP
 * only as a fallback - so SMTP_USER/SMTP_PASS describe the cPanel mailbox
 * and nothing else. Pointing the importer at Gmail meant overwriting the
 * SMTP fallback config, and reading BOTH mailboxes could not be done.
 *
 * With IMAP_USER / IMAP_PASS / IMAP_HOST set independently, one run reads
 * Gmail and another reads cPanel, SMTP untouched either way. mail_seen is
 * keyed on Message-ID, which survives the fetch between the two, so a
 * message present in both is imported exactly once - and that is what
 * makes it safe to point this at Gmail when Gmail collects the cPanel mail.
 */
$user = getenv('IMAP_USER') ?: (getenv('SMTP_USER') ?: '');
$pass = getenv('IMAP_PASS') ?: (getenv('SMTP_PASS') ?: '');
if ($user === '' || $pass === '') {
    fwrite(STDERR, "No mailbox credentials: set IMAP_USER/IMAP_PASS (or SMTP_USER/SMTP_PASS)\n");
    exit(1);
}

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
    'netlify', 'github', 'gitlab', 'digitalocean', 'aws.amazon', 'railway.app', 'render.com',
    // ESP sending domains. 'hello@9186062.brevosend.com' got through because
    // the bulk-subdomain pattern is '@send.' and this one is '@9186062.',
    // so match the ESP itself rather than the shape of the subdomain.
    'brevosend', 'brevo.com', 'sendinblue', 'mailgun', 'klaviyo', 'constantcontact',
    'activecampaign', 'convertkit', 'aweber', 'getresponse', 'mailerlite',
    'campaign-archive', 'mcsv.net', 'rsgsv.net', 'sparkpostmail', 'postmarkapp'];
/* Subject rules. These exist because the sender list CANNOT catch a
   calendar RSVP: an "Accepted: ..." notification arrives from the
   attendee's own Gmail account, which is indistinguishable from a
   prospect's address.

   ANCHORED AT THE START on purpose, and that anchor is load-bearing. One
   real prospect in the table has a subject reading "Re: Invitation from an
   unknown sender: ..." - a contains-match on "Invitation" would have
   deleted a genuine customer. A "Re:" or "Fwd:" prefix means a human typed
   something, so anchoring keeps every one of those by construction.
   (Customer names are deliberately not quoted here; the repo keeps PII out
   of source. The row itself is in the CRM.) */
$SKIP_SUBJECTS = '/^\\s*(Accepted|Declined|Tentative|Invitation|Updated invitation|'
               . 'Cancell?ed|Rescheduled|Automatic reply|Out of office|'
               . 'Delivery Status Notification|Undelivered Mail Returned):/i';

/** True for machine-generated mail that no address list can recognise. */
function subject_is_junk(string $subject, string $rx): bool {
    return (bool) preg_match($rx, trim($subject));
}

// extra skips without code changes: CRM_INBOX_SKIP=comma,separated,patterns in .env
foreach (array_filter(array_map('trim', explode(',', getenv('CRM_INBOX_SKIP') ?: ''))) as $extra) {
    $SKIP_PATTERNS[] = strtolower($extra);
}

/** One IMAP command via curl. $urlPath is appended to the mailbox URL. */
function imap_curl(string $user, string $pass, string $urlPath = '', ?string $customRequest = null): ?string {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => 'imaps://' . (getenv('IMAP_HOST') ?: 's99.veladns.com') . ':993/' . $urlPath,
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
    foreach ($db->query("SELECT id, email, message, status FROM leads WHERE form_type = 'Inbox Email'")->fetchAll() as $r) {
        $addr = strtolower($r['email']);
        $bad = in_array($addr, $SKIP_SENDERS, true);
        foreach ($SKIP_PATTERNS as $p) { if (!$bad && strpos($addr, $p) !== false) { $bad = true; } }
        // the stored message begins with the subject in quotes
        if (!$bad && preg_match('/^"([^"]*)"/', (string)$r['message'], $sm)
            && subject_is_junk($sm[1], $SKIP_SUBJECTS)) { $bad = true; }
        /* A lead ACCUMULATES, and the rules above only ever see its first
           imported message. Lead #11 was flagged as a calendar acceptance -
           which its first message genuinely is - while having since gathered
           4 notes and reached 'quoted', one of only six deals that ever got
           that far. Deleting it would have taken the conversation with it.
           So: classify on the first message, but never delete a row that has
           accumulated anything. */
        if ($bad) {
            $hn = $db->prepare('SELECT COUNT(*) FROM notes WHERE lead_id = ?');
            $hn->execute([$r['id']]);
            $notes = (int)$hn->fetchColumn();
            if ($notes > 0 || ($r['status'] ?? 'new') !== 'new') {
                printf("  keeping #%s <%s> - junk first message, but %d note(s) and status '%s'
",
                       $r['id'], $r['email'], $notes, $r['status'] ?? '?');
                $bad = false;
            }
        }
        if ($bad && $dry) {
            echo "DRY: would prune junk lead #{$r['id']} <{$r['email']}>\n";
            $pruned++;
            continue;
        }
        if ($bad) {
            $db->prepare('DELETE FROM leads WHERE id = ?')->execute([$r['id']]);
            $db->prepare('INSERT INTO audit_log (action, lead_id, actor) VALUES (?,?,?)')
               ->execute(['lead_pruned_junk', $r['id'], 'inbox-import']);
            echo "pruned junk lead #{$r['id']} <{$r['email']}>
";
            $pruned++;
        }
    }
    echo ($dry ? "prune dry-run: $pruned junk lead(s) WOULD be removed\n"
               : "prune done: $pruned junk lead(s) removed\n");
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
    $skipReason = $skip ? 'sender' : '';
    foreach ($SKIP_PATTERNS as $p) {
        if (!$skip && strpos($fromAddr, $p) !== false) { $skip = true; $skipReason = "sender ~ $p"; }
    }
    // the sender can be a perfectly real human and the mail still be machine-sent
    if (!$skip && subject_is_junk($subject, $SKIP_SUBJECTS)) { $skip = true; $skipReason = 'subject'; }
    if ($skip) {
        // A skip is PERMANENT - the id goes into mail_seen and the message is
        // never looked at again - so print what was dropped and why. Without
        // this a false positive on the subject rule silently loses a customer
        // and nothing anywhere would show it.
        printf("  skip (%s): %s | %s
", $skipReason, $fromAddr, mb_substr($subject, 0, 70));
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
    /* ORDER IS LOAD-BEARING. inbox_strip_quoted() needs line breaks, and so
       does signature extraction: a brand is only recoverable while the
       lines still exist. The old code flattened whitespace here, first
       thing, which is why no brand can be recovered from the rows imported
       earlier. Strip, extract, THEN flatten. */
    $body  = strip_tags($body);
    $clean = inbox_strip_quoted($body);
    list($brand, $brandHow) = inbox_brand($clean, $fromAddr, $subject);
    $country = inbox_country($clean);
    $lang    = lead_reply_lang($clean);

    /* 2000, not 500. The point of importing is to read what they asked,
       and real enquiries run past 500 characters - the longest already in
       the table is over 600 and was being cut off mid-question. */
    $snippet = inbox_query($clean, 2000);
    if ($snippet === '') { $snippet = trim(preg_replace('/\s+/', ' ', $body)); }

    /* 4. route */
    $lead = $db->prepare('SELECT id, status FROM leads WHERE lower(email) = ? ORDER BY id DESC LIMIT 1');
    $lead->execute([$fromAddr]);
    $L = $lead->fetch();

    if ($dry) {
        printf("DRY: %-24s %s | %s\n     brand=%s (%s) country=%s lang=%s wants=%s\n",
            $L ? "reply -> lead #" . $L['id'] : 'NEW lead',
            $fromAddr, mb_substr($subject, 0, 58),
            $brand === null ? '-' : $brand, $brandHow,
            $country === null ? '-' : $country,
            $lang === null ? '-' : $lang,
            lead_interests($snippet, '') === null ? '-' : lead_interests($snippet, ''));
        continue;
    }

    if ($L) {
        $db->prepare('INSERT INTO notes (lead_id, author, body) VALUES (?,?,?)')
           ->execute([$L['id'], 'inbox-import', mb_substr("Inbox email: \"$subject\" - $snippet", 0, 4000)]);
        $db->prepare("UPDATE leads SET sequences_paused = 1,
                status = CASE WHEN status = 'new' THEN 'quoted' ELSE status END WHERE id = ?")
           ->execute([$L['id']]);
        /* Backfill only what is MISSING. A reply often carries the brand and
           country the original form never asked for, but a value a human
           typed - or an earlier mail established - outranks anything
           inferred from this one, so every guard requires the column to be
           empty first. Never overwrite. */
        foreach (array('company' => $brand, 'country' => $country, 'reply_lang' => $lang) as $col => $val) {
            if ($val === null) continue;
            $db->prepare("UPDATE leads SET $col = ? WHERE id = ?
                          AND ($col IS NULL OR trim($col) = '')")
               ->execute(array($val, $L['id']));
        }
        $db->prepare('INSERT INTO audit_log (action, lead_id, actor) VALUES (?,?,?)')
           ->execute(['reply_received_auto', $L['id'], 'inbox-import']);
        echo "reply logged -> lead #{$L['id']} <$fromAddr>\n";
        $replies++;
    } else {
        $parts = explode('@', $fromAddr);
        $name = $fromName !== '' ? mb_substr($fromName, 0, 120) : ucfirst($parts[0]);
        /* crm_lead_score() reads `company`, so give it the brand we found
           instead of the empty string the old call always passed - a
           business enquiry was scored as though it had no company at all. */
        $score = crm_lead_score(['name' => $name, 'email' => $fromAddr,
            'company' => $brand === null ? '' : $brand, 'quantity' => '',
            'form_type' => 'Inbox Email', 'utm_medium' => 'email']);
        $message = mb_substr('"' . $subject . '" - ' . $snippet, 0, 5000);
        $db->prepare('INSERT INTO leads
                      (name, email, company, country, message, form_type, source_page,
                       lead_score, status, gdpr_consent, ip_hash, interests, reply_lang)
                      VALUES (?,?,?,?,?,?,?,?,?,0,?,?,?)')
           ->execute([$name, $fromAddr, $brand, $country, $message,
                      'Inbox Email', 'mailbox-import', $score, 'new', '',
                      lead_interests($snippet, ''), $lang]);
        printf("NEW lead #%s <%s> | %s%s\n", $db->lastInsertId(), $fromAddr,
            mb_substr($subject, 0, 58),
            $brand === null ? '' : '  [brand: ' . $brand . ' via ' . $brandHow . ']');
        $created++;
    }
    $db->prepare('INSERT OR IGNORE INTO mail_seen (message_id) VALUES (?)')->execute([$msgId]);
}
crm_file_log("INBOX-IMPORT replies=$replies new=$created skipped=$skipped scanned=" . count($uids));
echo "done: $replies replies logged, $created leads created, $skipped skipped\n";
