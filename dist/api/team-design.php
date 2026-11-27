<?php
/**
 * Team Uniform Designer submission endpoint (/team-designer).
 *
 * Creates an ordinary CRM lead so a kit enquiry appears in admin/leads.php
 * beside every other enquiry, then attaches the design and the squad list.
 *
 * WHAT IS DELIBERATELY NOT STORED: the SVG the browser rendered. The client
 * sends it, and we throw it away. The design is persisted as PARAMETERS
 * (garment, colours, font, sizes) and re-rendered server-side in admin, so no
 * browser-supplied markup is ever written into an admin page. A design is
 * attacker-controlled input like any other form field; treating it as markup
 * would be stored XSS behind the login.
 *
 * The logo IS stored, through the existing crm_store_upload(), which validates
 * by magic bytes and writes outside the web root. admin/file.php serves it as
 * an attachment with nosniff and CSP default-src 'none'.
 */
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/uploads.php';
require_once __DIR__ . '/mailer.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

function td_fail(string $msg, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['success' => false, 'message' => $msg]);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    td_fail('POST only', 405);
}

crm_session_start();

$raw  = file_get_contents('php://input') ?: '';
$data = json_decode($raw, true);
if (!is_array($data)) { td_fail('We could not read that submission.'); }

/* ---- CSRF, same contract as the contact form -------------------------- */
$csrf = (string)($data['csrf'] ?? '');
if (empty($_SESSION['csrf']) || $csrf === '' || !hash_equals($_SESSION['csrf'], $csrf)) {
    td_fail('Your session expired. Please reload the page and send again.');
}

$name    = trim((string)($data['name'] ?? ''));
$email   = trim((string)($data['email'] ?? ''));
$company = trim((string)($data['company'] ?? ''));
$country = trim((string)($data['country'] ?? ''));
$message = trim((string)($data['message'] ?? ''));

if ($name === '' || $email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    td_fail('Please give us a name and a valid email address.');
}

$db     = crm_db();
$ipHash = crm_ip_hash();

if (!crm_rate_limit_ok($db, $ipHash)) {
    td_fail('That is a lot of submissions from one place. Please email info@fabrioza.com.', 429);
}
if (($why = crm_spam_reason($name, $email, $message, $company)) !== null) {
    crm_file_log("TEAM-DESIGN rejected as spam: $why");
    // Answer as if accepted. Telling a bot which rule caught it only helps it.
    echo json_encode(['success' => true, 'reference' => 'TD-' . strtoupper(bin2hex(random_bytes(3)))]);
    exit;
}

/* ---- design parameters, all whitelisted ------------------------------- */
$design  = is_array($data['design'] ?? null) ? $data['design'] : [];
$garment = (string)($design['garment'] ?? '');
if (!in_array($garment, ['soccer', 'basketball', 'polo'], true)) {
    td_fail('Unknown garment.');
}
$font = (string)($design['font'] ?? 'block');
if (!in_array($font, ['block', 'classic', 'rounded'], true)) { $font = 'block'; }

/** Hex colours only: these end up inside an SVG fill attribute in admin. */
function td_hex($v, string $fallback): string {
    $v = is_string($v) ? trim($v) : '';
    return preg_match('/^#[0-9a-fA-F]{6}$/', $v) ? strtolower($v) : $fallback;
}

$colourIn = is_array($design['colours'] ?? null) ? $design['colours'] : [];
$colours  = [];
foreach (['body', 'sleeves', 'collar', 'panels', 'trim', 'placket'] as $zone) {
    $colours[$zone] = td_hex($colourIn[$zone] ?? null, '#ffffff');
}

$textColour    = td_hex($design['textColour'] ?? null, '#ffffff');
$outlineColour = td_hex($design['outlineColour'] ?? null, '#000000');
$outlineWidth  = (int)($design['outlineWidth'] ?? 0);
if ($outlineWidth < 0 || $outlineWidth > 6) { $outlineWidth = 0; }
$logoWidthCm   = (float)($design['logoWidthCm'] ?? 8);
if ($logoWidthCm < 4 || $logoWidthCm > 12) { $logoWidthCm = 8.0; }

/* ---- roster ------------------------------------------------------------ */
const TD_SIZES = ['YXS','YS','YM','YL','XS','S','M','L','XL','2XL','3XL'];
const TD_MAX_PLAYERS = 300;

$rosterIn = is_array($data['roster'] ?? null) ? $data['roster'] : [];
if (!$rosterIn) { td_fail('The squad list was empty.'); }
if (count($rosterIn) > TD_MAX_PLAYERS) {
    td_fail('That is more than ' . TD_MAX_PLAYERS . ' players. Please email the list to info@fabrioza.com.');
}

$roster = [];
$seen   = [];
foreach ($rosterIn as $i => $p) {
    if (!is_array($p)) { continue; }
    $pn = trim((string)($p['name'] ?? ''));
    $nu = trim((string)($p['number'] ?? ''));
    $sz = strtoupper(trim((string)($p['size'] ?? '')));
    if ($pn === '' && $nu === '' && $sz === '') { continue; }
    if (mb_strlen($pn) > 24) { $pn = mb_substr($pn, 0, 24); }
    if ($nu !== '' && !preg_match('/^\d{1,3}$/', $nu)) {
        td_fail('Player ' . ($i + 1) . ': the number must be 1 to 3 digits.');
    }
    if ($nu !== '') {
        if (isset($seen[$nu])) { td_fail('Two players share number ' . $nu . '.'); }
        $seen[$nu] = true;
    }
    if ($sz !== '' && !in_array($sz, TD_SIZES, true)) {
        td_fail('Player ' . ($i + 1) . ': "' . htmlspecialchars($sz) . '" is not a size we make.');
    }
    $roster[] = ['name' => $pn, 'number' => $nu, 'size' => $sz];
}
if (!$roster) { td_fail('The squad list was empty.'); }

/* ---- create the lead --------------------------------------------------- */
$reference  = 'TD-' . strtoupper(bin2hex(random_bytes(3)));
$players    = count($roster);
$garmentLbl = ['soccer' => 'Soccer jersey', 'basketball' => 'Basketball jersey', 'polo' => 'Polo shirt'][$garment];

$summary = [];
foreach ($roster as $p) { if ($p['size'] !== '') { $summary[$p['size']] = ($summary[$p['size']] ?? 0) + 1; } }
$sizeLine = [];
foreach (TD_SIZES as $s) { if (!empty($summary[$s])) { $sizeLine[] = $s . ' x ' . $summary[$s]; } }
$sizeLine = $sizeLine ? implode(', ', $sizeLine) : 'no sizes given';

$fullMessage = "Team Uniform Designer submission ($reference)\n"
    . "Garment: $garmentLbl\n"
    . "Players: $players\n"
    . "Sizes: $sizeLine\n"
    . ($message !== '' ? "\nFrom the customer:\n$message\n" : '');

$score = crm_lead_score([
    'name' => $name, 'email' => $email, 'company' => $company,
    'country' => $country, 'quantity' => (string)$players, 'message' => $fullMessage,
]);

try {
    $db->beginTransaction();

    $stmt = $db->prepare('INSERT INTO leads
        (name, email, company, country, product_type, quantity, message, form_type,
         source_page, lead_score, gdpr_consent, gdpr_consent_ts, ip_hash)
        VALUES (?,?,?,?,?,?,?,?,?,?,1,CURRENT_TIMESTAMP,?)');
    $stmt->execute([$name, $email, $company, $country, $garmentLbl, (string)$players,
        $fullMessage, 'team_designer',
        (string)($data['source_page'] ?? '/team-designer'), $score, $ipHash]);
    $leadId = (int)$db->lastInsertId();

    $d = $db->prepare('INSERT INTO team_designs
        (lead_id, reference, garment, colours, font, text_colour, outline_colour,
         outline_width, logo_width_cm, logo_name)
        VALUES (?,?,?,?,?,?,?,?,?,?)');
    $d->execute([$leadId, $reference, $garment, json_encode($colours), $font,
        $textColour, $outlineColour, $outlineWidth, $logoWidthCm,
        (string)($design['logoName'] ?? '')]);
    $designId = (int)$db->lastInsertId();

    $r = $db->prepare('INSERT INTO team_roster (design_id, position, player_name, player_number, size)
                       VALUES (?,?,?,?,?)');
    foreach ($roster as $i => $p) {
        $r->execute([$designId, $i + 1, $p['name'], $p['number'], $p['size']]);
    }

    $db->commit();
} catch (Throwable $e) {
    if ($db->inTransaction()) { $db->rollBack(); }
    crm_file_log('TEAM-DESIGN db error: ' . $e->getMessage());
    crm_failed_lead_dump(['form' => 'team_designer', 'name' => $name, 'email' => $email,
                          'roster' => $roster, 'design' => $design]);
    td_fail('We could not save that. Please email info@fabrioza.com and we will pick it up.', 500);
}

/* ---- logo ---------------------------------------------------------------
   Best effort on purpose: the enquiry is already saved, and losing a logo must
   not lose the lead. A rejection is recorded on the design row so admin can
   see that a file was sent and why it did not stick. */
$logoNote = '';
if (is_array($data['logo'] ?? null) && !empty($data['logo']['data'])) {
    $res = crm_store_upload($db, $leadId, [
        'name' => (string)($data['logo']['name'] ?? 'logo'),
        'data' => (string)$data['logo']['data'],
    ]);
    if (!empty($res['ok'])) {
        $db->prepare('UPDATE team_designs SET logo_upload_id = ? WHERE id = ?')
           ->execute([(int)$res['id'], $designId]);
    } else {
        $logoNote = ' (logo not attached: ' . (string)($res['error'] ?? 'rejected') . ')';
        crm_file_log("TEAM-DESIGN $reference logo rejected: " . (string)($res['error'] ?? '?'));
    }
}

/* ---- answer the customer BEFORE sending anything ------------------------
   fab_send() retries SMTP three times with an 8 second timeout, so a provider
   that is slow or misconfigured keeps the browser on "Sending..." for up to
   half a minute. Measured in a test container with no credentials: two failed
   authentications, 14 seconds apart, and the button still spinning.

   The lead and the roster are already committed at this point, so nothing is
   lost by closing the response now and doing delivery afterwards. The customer
   sees their reference immediately; a mail failure is recorded in email_log
   exactly as before. */
$payloadOut = json_encode(['success' => true, 'reference' => $reference, 'players' => $players]);
ignore_user_abort(true);
header('Content-Length: ' . strlen($payloadOut));
header('Connection: close');
echo $payloadOut;
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
} else {
    // mod_php: flush what we have and let Apache close on Content-Length.
    while (ob_get_level() > 0) { @ob_end_flush(); }
    @flush();
}

/* ---- confirmation to the customer -------------------------------------- */
$safeName = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
$rows = '';
foreach ($roster as $p) {
    $rows .= '<tr><td style="padding:3px 10px 3px 0">' . htmlspecialchars($p['name'], ENT_QUOTES, 'UTF-8')
          . '</td><td style="padding:3px 10px 3px 0">' . htmlspecialchars($p['number'], ENT_QUOTES, 'UTF-8')
          . '</td><td style="padding:3px 0">' . htmlspecialchars($p['size'], ENT_QUOTES, 'UTF-8')
          . '</td></tr>';
}
$html = '<div style="font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.6;color:#1a1a1a">'
    . '<p>Hello ' . $safeName . ',</p>'
    . '<p>Thank you &mdash; your kit design and squad list have reached the factory. '
    . 'Your reference is <strong>' . $reference . '</strong>.</p>'
    . '<p><strong>' . htmlspecialchars($garmentLbl, ENT_QUOTES, 'UTF-8') . '</strong> &middot; '
    . $players . ' players &middot; ' . htmlspecialchars($sizeLine, ENT_QUOTES, 'UTF-8') . '</p>'
    . '<table style="border-collapse:collapse;font-size:14px"><tr>'
    . '<th align="left" style="padding:3px 10px 3px 0">Player</th>'
    . '<th align="left" style="padding:3px 10px 3px 0">No.</th>'
    . '<th align="left" style="padding:3px 0">Size</th></tr>' . $rows . '</table>'
    . '<p>We reply with a quotation within one working day, Monday to Saturday. '
    . 'Nothing goes into production until you have approved a physical sample.</p>'
    . '<p>FABRIOZA<br>Saro Street, near Fateh Garh Road, Sialkot 51310, Punjab, Pakistan<br>'
    . '<a href="mailto:info@fabrioza.com">info@fabrioza.com</a> &middot; +92 312 4815601</p></div>';

// retrySmtp = false on purpose. fab_send's three attempts with an 8 second
// timeout each are right for a notification nobody is waiting on, but mod_php
// buffers the response until the script ends, so the customer watches
// "Sending..." for the whole retry loop. Measured: 15.8 s in a container with
// no credentials. One attempt caps it, and a failure is still written to
// email_log, where the daily digest picks it up.
$sent = fab_send($email, $name, 'Your kit design — ' . $reference, $html,
                 'info@fabrioza.com', 'FABRIOZA', false);
logEmail($db, $leadId, $email, 'Your kit design — ' . $reference, !empty($sent['ok']),
         (string)($sent['error'] ?? ''));

crm_notify("New KIT DESIGN $reference\n$name" . ($company !== '' ? " ($company)" : '')
    . "\n$garmentLbl, $players players\n$sizeLine$logoNote\nLead #$leadId");

