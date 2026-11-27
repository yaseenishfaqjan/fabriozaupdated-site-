<?php
/**
 * Custom Fabric Development enquiry (/custom-fabric-development).
 *
 * Creates an ordinary CRM lead with form_type = "custom-fabric", so these
 * appear in admin/leads.php beside every other enquiry and carry a BULK 300+
 * badge there.
 *
 * MULTIPART, not base64-in-JSON like the contact form. This page accepts up to
 * five tech packs at 20 MB each; as base64 that is ~133 MB held in memory per
 * request, and PHP would have to decode all of it before anything is saved.
 * Multipart lets PHP stream each file to a temp file and keeps memory flat.
 * crm_store_uploaded_file() in uploads.php is the matching storage half.
 */
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/uploads.php';
require_once __DIR__ . '/mailer.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

function cf_fail(string $msg, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['success' => false, 'message' => $msg]);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { cf_fail('POST only', 405); }

crm_session_start();

/* ---- CSRF, same contract as every other form here ---------------------- */
$csrf = (string)($_POST['csrf'] ?? '');
if (empty($_SESSION['csrf']) || $csrf === '' || !hash_equals($_SESSION['csrf'], $csrf)) {
    cf_fail('Your session expired. Please reload the page and send again.');
}

/* ---- honeypot ----------------------------------------------------------
   A hidden field a person never sees and never fills. Answer 200 as if it
   worked: telling a bot which rule caught it is free tuning for the bot. */
if (trim((string)($_POST['company_url'] ?? '')) !== '') {
    crm_file_log('CUSTOM-FABRIC honeypot tripped');
    echo json_encode(['success' => true, 'reference' => 'CF-' . strtoupper(bin2hex(random_bytes(3)))]);
    exit;
}

$name    = trim((string)($_POST['name'] ?? ''));
$brand   = trim((string)($_POST['brand'] ?? ''));
$email   = trim((string)($_POST['email'] ?? ''));
$country = trim((string)($_POST['country'] ?? ''));
$whats   = trim((string)($_POST['whatsapp'] ?? ''));
$website = trim((string)($_POST['website'] ?? ''));
$comp    = trim((string)($_POST['composition'] ?? ''));
$gsm     = trim((string)($_POST['gsm'] ?? ''));
$colours = trim((string)($_POST['colours'] ?? ''));
$target  = trim((string)($_POST['target_date'] ?? ''));
$message = trim((string)($_POST['message'] ?? ''));

if ($name === '' || $brand === '' || $country === ''
    || $email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    cf_fail('Please give us a name, brand, valid email and country.');
}

/* ---- whitelists --------------------------------------------------------
   Everything below reaches an email body and the admin screen, so each
   value is matched against a fixed list rather than escaped and hoped for. */
const CF_PRODUCTS = ['T-shirts','Hoodies','Sweatshirts','Joggers','Tracksuits',
                     'Polos','Sportswear','Other'];
const CF_FINISH   = ['Pre-shrunk and compacted','Brushed','Peached',
                     'Enzyme or garment-washed','Vintage or pigment wash'];
const CF_QTY      = ['300-500'   => '300 to 500 pieces',
                     '500-1000'  => '500 to 1,000 pieces',
                     '1000-2500' => '1,000 to 2,500 pieces',
                     '2500-5000' => '2,500 to 5,000 pieces',
                     '5000+'     => '5,000+ pieces'];

function cf_pick(array $in, array $allowed): array {
    $out = [];
    foreach ($in as $v) {
        if (is_string($v) && in_array($v, $allowed, true) && !in_array($v, $out, true)) {
            $out[] = $v;
        }
    }
    return $out;
}

$products = cf_pick((array)($_POST['products'] ?? []), CF_PRODUCTS);
$finish   = cf_pick((array)($_POST['finish'] ?? []), CF_FINISH);
if (!$products) { cf_fail('Please choose at least one product type.'); }

$qtyKey = (string)($_POST['quantity'] ?? '');
if (!isset(CF_QTY[$qtyKey])) {
    // There is no sub-300 option in the form, and there must not be one here
    // either: 300 is the floor for developing fabric, not a soft preference.
    cf_fail('Custom fabric development starts at 300 pieces. For smaller orders, '
          . 'please use the standard enquiry form.');
}
$qtyLabel = CF_QTY[$qtyKey];

if ($target !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $target)) { $target = ''; }

$db     = crm_db();
$ipHash = crm_ip_hash();

if (!crm_rate_limit_ok($db, $ipHash)) {
    cf_fail('That is a lot of submissions from one place. Please email info@fabrioza.com.', 429);
}
if (($why = crm_spam_reason($name, $email, $message, $brand)) !== null) {
    crm_file_log("CUSTOM-FABRIC rejected as spam: $why");
    echo json_encode(['success' => true, 'reference' => 'CF-' . strtoupper(bin2hex(random_bytes(3)))]);
    exit;
}

/* ---- the lead ----------------------------------------------------------- */
$reference = 'CF-' . strtoupper(bin2hex(random_bytes(3)));

$lines = [
    "Custom Fabric Development request ($reference)",
    "Brand: $brand",
    "Quantity: $qtyLabel",
    "Products: " . implode(', ', $products),
];
if ($comp !== '')    { $lines[] = "Composition: $comp"; }
if ($gsm !== '')     { $lines[] = "GSM: $gsm"; }
if ($colours !== '') { $lines[] = "Colours / Pantone: $colours"; }
if ($finish)         { $lines[] = "Finish: " . implode(', ', $finish); }
if ($target !== '')  { $lines[] = "Target delivery: $target"; }
if ($whats !== '')   { $lines[] = "WhatsApp: $whats"; }
if ($website !== '') { $lines[] = "Website / Instagram: $website"; }
if ($message !== '') { $lines[] = "\nFrom the customer:\n$message"; }
$fullMessage = implode("\n", $lines);

$score = crm_lead_score([
    'name' => $name, 'email' => $email, 'company' => $brand,
    'country' => $country, 'quantity' => $qtyLabel, 'message' => $fullMessage,
]);

try {
    $stmt = $db->prepare('INSERT INTO leads
        (name, email, company, country, product_type, quantity, message, form_type,
         source_page, lead_score, interests, gdpr_consent, gdpr_consent_ts, ip_hash)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,1,CURRENT_TIMESTAMP,?)');
    $stmt->execute([$name, $email, $brand, $country, implode(', ', $products), $qtyLabel,
        $fullMessage, 'custom-fabric', '/custom-fabric-development', $score,
        'custom-fabric,bulk-300', $ipHash]);
    $leadId = (int)$db->lastInsertId();
} catch (Throwable $e) {
    crm_file_log('CUSTOM-FABRIC db error: ' . $e->getMessage());
    crm_failed_lead_dump(['form' => 'custom-fabric', 'name' => $name, 'email' => $email,
                          'brand' => $brand, 'qty' => $qtyLabel]);
    cf_fail('We could not save that. Please email info@fabrioza.com and we will pick it up.', 500);
}

/* ---- attachments -------------------------------------------------------
   Best effort: the enquiry is saved, and a rejected tech pack must not lose
   the lead. $_FILES for files[] arrives column-wise, so it is transposed
   before anything touches it. */
$saved = $rejected = [];
$f = $_FILES['files'] ?? null;
if (is_array($f) && isset($f['name']) && is_array($f['name'])) {
    $count = min(count($f['name']), CRM_UPLOAD_MAX_FILES);
    for ($i = 0; $i < $count; $i++) {
        $one = [
            'name'     => $f['name'][$i]     ?? '',
            'tmp_name' => $f['tmp_name'][$i] ?? '',
            'size'     => $f['size'][$i]     ?? 0,
            'error'    => $f['error'][$i]    ?? UPLOAD_ERR_NO_FILE,
        ];
        if ((int)$one['error'] === UPLOAD_ERR_NO_FILE) { continue; }
        $res = crm_store_uploaded_file($db, $leadId, $one);
        if (!empty($res['ok'])) {
            $saved[] = $res['name'] . ' (' . crm_format_bytes($res['size']) . ')';
        } else {
            $rejected[] = (string)$one['name'] . ' - ' . (string)($res['error'] ?? 'rejected');
            crm_file_log("CUSTOM-FABRIC $reference attachment rejected: "
                . (string)$one['name'] . ' - ' . (string)($res['error'] ?? '?'));
        }
    }
}

/* ---- notify the office -------------------------------------------------- */
$esc = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$rows = '';
foreach ([
    'Reference' => $reference, 'Name' => $name, 'Brand' => $brand, 'Email' => $email,
    'WhatsApp' => $whats, 'Country' => $country, 'Website' => $website,
    'Products' => implode(', ', $products), 'Quantity' => $qtyLabel,
    'Composition' => $comp, 'GSM' => $gsm, 'Colours' => $colours,
    'Finish' => implode(', ', $finish), 'Target delivery' => $target,
    'Message' => $message,
    'Files' => ($saved ? implode('; ', $saved) : 'none')
               . ($rejected ? ' | REJECTED: ' . implode('; ', $rejected) : ''),
] as $k => $v) {
    if ($v === '' || $v === null) { continue; }
    $rows .= '<tr><td style="padding:3px 12px 3px 0;vertical-align:top"><strong>' . $esc($k)
           . '</strong></td><td style="padding:3px 0">' . nl2br($esc($v)) . '</td></tr>';
}
$internal = '<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.6">'
    . '<p style="font-size:16px"><strong>BULK 300+ &mdash; custom fabric request</strong></p>'
    . '<table style="border-collapse:collapse">' . $rows . '</table>'
    . '<p>Lead #' . $leadId . ' &middot; score ' . $score . ' &middot; '
    . '<a href="https://fabrioza.com/admin/lead.php?id=' . $leadId . '">open in CRM</a></p></div>';

foreach (fab_mail_to() as $to) {
    $r = fab_send($to, 'FABRIOZA', "BULK 300+ custom fabric request - $brand ($qtyLabel)",
                  $internal, $email, $name, false);
    logEmail($db, $leadId, $to, "custom fabric - $reference", !empty($r['ok']),
             (string)($r['error'] ?? ''));
}

/* ---- confirm to the customer -------------------------------------------- */
$client = '<div style="font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.6;color:#1a1a1a">'
    . '<p>Hello ' . $esc($name) . ',</p>'
    . '<p>Thank you &mdash; <strong>we will confirm your fabric specification within 24 '
    . 'hours.</strong> Your reference is <strong>' . $reference . '</strong>.</p>'
    . '<p>What happens next: once you approve that specification, your fabric is prepared in '
    . '4 to 6 working days, and we send photographs and video of it for your approval before '
    . 'anything is cut.</p>'
    . '<table style="border-collapse:collapse;font-size:14px">' . $rows . '</table>'
    . '<p>If anything above is wrong, just reply to this email.</p>'
    . '<p>FABRIOZA<br>Saro Street, near Fateh Garh Road, Sialkot 51310, Punjab, Pakistan<br>'
    . '<a href="mailto:info@fabrioza.com">info@fabrioza.com</a> &middot; +92 312 4815601</p></div>';

$sent = fab_send($email, $name, 'Your custom fabric request - ' . $reference, $client,
                 'info@fabrioza.com', 'FABRIOZA', false);
logEmail($db, $leadId, $email, 'custom fabric confirmation - ' . $reference,
         !empty($sent['ok']), (string)($sent['error'] ?? ''));

crm_notify("BULK 300+ custom fabric\n$brand ($name)\n$qtyLabel\n"
    . implode(', ', $products) . "\nLead #$leadId");

echo json_encode(['success' => true, 'reference' => $reference, 'lead' => $leadId]);
