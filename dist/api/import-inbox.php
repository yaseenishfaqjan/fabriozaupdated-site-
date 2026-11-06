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
 *   php import-inbox.php --all --limit 50   # first 50 only, in batches
 *   php import-inbox.php --all --dry-run --reconsider
 *                                   # preview INCLUDING messages earlier
 *                                   # runs skipped (audit what was dropped)
 *
 * Mailbox: IMAP_USER / IMAP_PASS / IMAP_HOST, falling back to the SMTP
 * pair. Set those three to read a different mailbox (e.g. Gmail with an
 * app password at imap.gmail.com) without disturbing SMTP.
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
/* --reconsider: ignore mail_seen, so a dry run can show what EARLIER runs
   skipped.
 *
 * A skip is permanent by design - the Message-ID goes into mail_seen and
 * the message is never looked at again - which is correct for a newsletter
 * and wrong if the rules that judged it were too broad. mail_seen holds 917
 * ids while only a handful of leads and reply notes came out of them, and
 * most of those judgements were made before the subject rules and the skip
 * logging existed. So "what did we silently drop?" is a real question, and
 * without this flag it cannot even be asked: the loop skips a seen message
 * before it prints anything.
 *
 * DRY RUN ONLY, enforced below. Re-processing for real would append a
 * second copy of every reply note to leads that already have them. */
$reconsider = in_array('--reconsider', $argv, true);
/* --limit N: stop after N messages have been CONSIDERED (skips included).
   A 962-message mailbox is not something to walk blind the first time, and
   it is how the connection-reuse fix above was measured rather than
   assumed. 0 means no limit. */
$limit = 0;
foreach ($argv as $i => $a) {
    if ($a === '--limit' && isset($argv[$i + 1])) { $limit = max(0, (int) $argv[$i + 1]); }
    elseif (strpos($a, '--limit=') === 0) { $limit = max(0, (int) substr($a, 8)); }
}
if ($reconsider && !$dry) {
    fwrite(STDERR, "--reconsider is dry-run only (it would duplicate notes on leads that already have them).
"
                 . "Run: php import-inbox.php --all --dry-run --reconsider
");
    exit(1);
}

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

/**
 * One IMAP command via curl. $urlPath is appended to the mailbox URL.
 *
 * ONE CONNECTION FOR THE WHOLE RUN, REUSED. This used to curl_init() and
 * curl_close() per command, so every message cost two or three fresh TLS
 * handshakes and IMAP LOGINs - roughly 2,900 logins to walk a
 * 962-message mailbox. Measured against Gmail at about ONE MESSAGE PER
 * MINUTE, a 16-hour run, because Gmail throttles repeated logins hard;
 * it also risks the account being rate-limited outright. Holding the
 * handle lets curl keep the authenticated connection open and reuse it.
 *
 * The handle is deliberately never closed: PHP frees it at exit, and
 * closing it after a failure would throw away a connection that is
 * usually still good.
 */
function imap_curl(string $user, string $pass, string $urlPath = '', ?string $customRequest = null): ?string {
    static $ch = null;
    if ($ch === null) { $ch = curl_init(); }
    curl_setopt_array($ch, [
        CURLOPT_URL => 'imaps://' . (getenv('IMAP_HOST') ?: 's99.veladns.com') . ':993/' . $urlPath,
        CURLOPT_USERPWD => $user . ':' . $pass,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_FORBID_REUSE => false,
        CURLOPT_FRESH_CONNECT => false,
    ]);
    /* CUSTOMREQUEST STICKS on a reused handle. Left set, the previous
       command's FETCH would be re-sent against the next call's URL, which
       is a silent wrong-data bug rather than an error. Passing null here
       restores the default, so it is cleared on every call that does not
       supply one. */
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $customRequest);
    $res = curl_exec($ch);
    if ($res === false) {
        fwrite(STDERR, 'IMAP curl error: ' . curl_error($ch) . "\n");
        return null;
    }
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
$considered = 0;
$t0 = microtime(true);
foreach ($uids as $uid) {
    if ($limit > 0 && $considered >= $limit) {
        printf("stopping at --limit %d\n", $limit);
        break;
    }
    $considered++;
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

    if (!$reconsider) {
        $seen = $db->prepare('SELECT 1 FROM mail_seen WHERE message_id = ?');
        $seen->execute([$msgId]);
        if ($seen->fetchColumn()) { continue; }
    }

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
$secs = max(0.001, microtime(true) - $t0);
crm_file_log("INBOX-IMPORT replies=$replies new=$created skipped=$skipped scanned=" . count($uids));
printf("done: %d replies logged, %d leads created, %d skipped | %d considered in %.1fs (%.1f msg/s)\n",
    $replies, $created, $skipped, $considered, $secs, $considered / $secs);
