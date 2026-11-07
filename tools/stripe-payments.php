<?php
/**
 * Pull the Stripe payment notifications out of a mailbox and report who
 * paid what. READ-ONLY: it writes nothing, to the mailbox or the database.
 *
 * The importer skips these, correctly - they come from Stripe, not from
 * the customer, so they are not an enquiry and must not become a lead.
 * But the CRM records ZERO won deals and has an empty revenue column,
 * while this mailbox plainly contains real payments. Matching a payment
 * to the lead who made it is what turns that column on.
 */
$host = getenv('IMAP_HOSTINGER_HOST') ?: 'imap.hostinger.com';
$user = getenv('IMAP_HOSTINGER_USER') ?: '';
$pass = getenv('IMAP_HOSTINGER_PASS') ?: '';
if ($user === '' || $pass === '') { fwrite(STDERR, "mailbox not configured\n"); exit(1); }

function ic($user, $pass, $host, $path = '', $req = null) {
    static $ch = null;
    if ($ch === null) { $ch = curl_init(); }
    curl_setopt_array($ch, [
        CURLOPT_URL => 'imaps://' . $host . ':993/' . $path,
        CURLOPT_USERPWD => $user . ':' . $pass,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 60,
    ]);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $req);
    $r = curl_exec($ch);
    if ($r === false) { fwrite(STDERR, 'IMAP: ' . curl_error($ch) . "\n"); return null; }
    return (string) $r;
}

$out = ic($user, $pass, $host, 'INBOX', 'UID SEARCH FROM "stripe"');
preg_match('/\* SEARCH ([\d\s]+)/i', (string) $out, $m);
$uids = array_filter(array_map('intval', preg_split('/\s+/', trim($m[1] ?? ''))));
printf("Stripe messages in the mailbox: %d%s%s", count($uids), PHP_EOL, PHP_EOL);

$rows = [];
foreach ($uids as $uid) {
    $raw = ic($user, $pass, $host, "INBOX;UID=$uid;SECTION=HEADER.FIELDS%20(SUBJECT%20DATE)");
    if ($raw === null) continue;
    $raw = preg_replace("/\r\n[ \t]+/", ' ', $raw);
    $subject = ''; $date = '';
    foreach (explode("\r\n", $raw) as $line) {
        if (stripos($line, 'Subject:') === 0) $subject = trim(substr($line, 8));
        if (stripos($line, 'Date:') === 0)    $date = trim(substr($line, 5));
    }
    $subject = iconv_mime_decode($subject, 2, 'UTF-8');

    /* Only the notifications that state a payment. Stripe sends plenty of
       onboarding and product mail that carries no money at all. */
    if (!preg_match('/payment|paid|invoice|payout/i', $subject)) continue;

    $amount = null;
    if (preg_match('/\$([0-9][0-9,]*\.?[0-9]*)/', $subject, $am)) $amount = $am[1];
    $who = null;
    if (preg_match('/from\s+([A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+)/i', $subject, $wm)) $who = strtolower($wm[1]);

    $rows[] = [
        'date'    => $date === '' ? '' : date('Y-m-d', strtotime($date)),
        'amount'  => $amount,
        'who'     => $who,
        'subject' => $subject,
    ];
}

usort($rows, fn($a, $b) => strcmp($a['date'], $b['date']));
$total = 0.0;
$payers = [];
foreach ($rows as $r) {
    printf("  %-10s %-10s %-34s %s%s",
        $r['date'],
        $r['amount'] === null ? '' : '$' . $r['amount'],
        $r['who'] ?? '',
        mb_substr($r['subject'], 0, 60), PHP_EOL);
    /* Count each payment once: Stripe sends several notifications per
       payment ("Payment of X from Y", "you just got paid X"), so summing
       every line would multiply the revenue by three. Key on who+amount. */
    if ($r['amount'] !== null && $r['who'] !== null) {
        $k = $r['who'] . '|' . $r['amount'] . '|' . $r['date'];
        if (!isset($payers[$k])) {
            $payers[$k] = true;
            $total += (float) str_replace(',', '', $r['amount']);
        }
    }
}
printf("%s  %d payment-related message(s); %d distinct payment(s) identified, total $%s%s",
    PHP_EOL, count($rows), count($payers), number_format($total, 2), PHP_EOL);
echo PHP_EOL, "  NOTE: identified from SUBJECT LINES only. Stripe's dashboard is the", PHP_EOL,
     "  authority on what was actually collected - treat this as a list to", PHP_EOL,
     "  check against it, not as an accounting figure.", PHP_EOL;
