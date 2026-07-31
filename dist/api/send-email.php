<?php
/**
 * FABRIOZA Form Handler - CRM edition (Phase A).
 *
 * Order of operations (the point of this rewrite):
 *   validate -> honeypot -> CSRF -> GDPR consent -> rate limit
 *   -> INSERT LEAD INTO SQLITE (never lost again)
 *   -> then best-effort SMTP notification + auto-reply, logged to email_log.
 * An SMTP failure returns success to the visitor: the lead is already saved.
 *
 * Env (VPS .env / docker-compose): SMTP_HOST, SMTP_PORT, SMTP_USER,
 * SMTP_PASS, MAIL_TO, CRM_DATA_DIR, CRM_IP_SALT.
 */

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require __DIR__ . '/lib/Exception.php';
require __DIR__ . '/lib/PHPMailer.php';
require __DIR__ . '/lib/SMTP.php';
require __DIR__ . '/db.php';

header('Content-Type: application/json');
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowedOrigins = ['https://fabrioza.com', 'https://www.fabrioza.com', 'http://localhost:8080', 'http://localhost:8085'];
if (in_array($origin, $allowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Credentials: true');
}
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success' => false, 'message' => 'Method not allowed']); exit; }

$data = json_decode(file_get_contents('php://input'), true) ?: $_POST;
if (empty($data)) { http_response_code(400); echo json_encode(['success' => false, 'message' => 'No data received']); exit; }

/* ---- 1. Honeypot: hidden "website" field. Bots fill it; humans never see it.
        Respond as if successful so bots learn nothing. No side effects. ---- */
if (!empty($data['website'])) {
    crm_file_log('SPAM(honeypot) form=' . substr((string)($data['form_type'] ?? '?'), 0, 40)
        . ' email=' . substr((string)($data['email'] ?? '?'), 0, 60));
    echo json_encode(['success' => true, 'message' => 'Thank you! We will get back to you within 24 hours.']);
    exit;
}

/* ---- 2. Validate + sanitize ---- */
$clientEmail = mb_substr(sanitize($data['email'] ?? ''), 0, 190);
$clientName  = mb_substr(sanitize($data['name'] ?? ''), 0, 120);
$formType    = mb_substr(sanitize($data['form_type'] ?? 'General Inquiry'), 0, 60);
$company     = mb_substr(sanitize($data['company'] ?? ''), 0, 190);
$country     = mb_substr(sanitize($data['country'] ?? ''), 0, 80);
$productType = mb_substr(sanitize($data['product_type'] ?? ''), 0, 190);
$quantity    = mb_substr(sanitize($data['quantity'] ?? ''), 0, 190);
$message     = mb_substr(sanitize($data['message'] ?? ''), 0, 5000);
$source      = mb_substr(sanitize($data['source'] ?? ''), 0, 120);
$utmSource   = mb_substr(sanitize($data['utm_source'] ?? ''), 0, 120);
$utmMedium   = mb_substr(sanitize($data['utm_medium'] ?? ''), 0, 120);
$utmCampaign = mb_substr(sanitize($data['utm_campaign'] ?? ''), 0, 120);
$sourcePage  = mb_substr(sanitize($data['source_page'] ?? ($_SERVER['HTTP_REFERER'] ?? '')), 0, 300);

if ($clientName === '') { http_response_code(400); echo json_encode(['success' => false, 'message' => 'Your name is required']); exit; }
if ($clientEmail === '' || !filter_var($clientEmail, FILTER_VALIDATE_EMAIL)) { http_response_code(400); echo json_encode(['success' => false, 'message' => 'A valid email is required']); exit; }

/* ---- 2b. Email domain must actually receive mail (MX / A record) ---- */
if (!crm_email_domain_ok($clientEmail)) {
    crm_file_log("REJECT(no-mx) form=$formType email=$clientEmail");
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'That email domain does not appear to accept mail - please double-check the address.']);
    exit;
}

/* ---- 2c. Bot heuristics (gibberish names, numeric messages, disposable
        domains). Fake success so bots learn nothing; nothing is stored. ---- */
$spamReason = crm_spam_reason($clientName, $clientEmail, $message, $company);
if ($spamReason !== null) {
    crm_file_log("SPAM($spamReason) form=$formType name=$clientName email=$clientEmail");
    echo json_encode(['success' => true, 'message' => 'Thank you! We will get back to you within 24 hours.']);
    exit;
}

/* ---- 2d. reCAPTCHA v3 (active only once RECAPTCHA_SECRET is set) ---- */
if (!crm_recaptcha_ok($data['recaptcha_token'] ?? null, 0.5)) {
    crm_file_log("SPAM(recaptcha) form=$formType email=$clientEmail");
    echo json_encode(['success' => true, 'message' => 'Thank you! We will get back to you within 24 hours.']);
    exit;
}

/* ---- 3. CSRF: token issued by /api/csrf.php, bound to the session ---- */
crm_session_start();
$csrf = (string)($data['csrf'] ?? '');
if (empty($_SESSION['csrf']) || $csrf === '' || !hash_equals($_SESSION['csrf'], $csrf)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Session expired - please reload the page and try again.']);
    exit;
}

/* ---- 4. GDPR consent is mandatory ---- */
$consent = $data['gdpr_consent'] ?? false;
$consentGiven = ($consent === true || $consent === 'true' || $consent === 1 || $consent === '1' || $consent === 'on');
if (!$consentGiven) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Please accept the privacy consent so we can process your enquiry.']);
    exit;
}

/* ---- 5. Rate limit: 5 submissions per IP hash per hour ---- */
try {
    $db = crm_db();
    $ipHash = crm_ip_hash();
    if (!crm_rate_limit_ok($db, $ipHash, (int)(getenv('CRM_RATE_MAX') ?: 3), 3600)) {
        http_response_code(429);
        echo json_encode(['success' => false, 'message' => 'Too many requests. Please try again later.']);
        exit;
    }
} catch (Throwable $e) {
    // DB unavailable: fall through - we would rather send the email than drop the lead entirely.
    error_log('FABRIOZA CRM db error (pre-insert): ' . $e->getMessage());
    $db = null;
    $ipHash = '';
}

/* ---- 6. INSERT THE LEAD FIRST ---- */
$leadId = null;
if ($db) {
    try {
        $payload = [
            'name' => $clientName, 'email' => $clientEmail, 'company' => $company,
            'country' => $country, 'product_type' => $productType, 'quantity' => $quantity,
            'message' => $message, 'form_type' => $formType, 'source_page' => $sourcePage,
            'utm_source' => $utmSource, 'utm_medium' => $utmMedium, 'utm_campaign' => $utmCampaign,
        ];
        $score = crm_lead_score($payload);
        $stmt = $db->prepare('INSERT INTO leads
            (name, email, company, country, product_type, quantity, message, form_type,
             source_page, utm_source, utm_medium, utm_campaign, lead_score,
             gdpr_consent, gdpr_consent_ts, ip_hash)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,1,CURRENT_TIMESTAMP,?)');
        $stmt->execute([$clientName, $clientEmail, $company, $country, $productType, $quantity,
            $message, $formType, $sourcePage, $utmSource, $utmMedium, $utmCampaign, $score, $ipHash]);
        $leadId = (int)$db->lastInsertId();
    } catch (Throwable $e) {
        error_log('FABRIOZA CRM lead insert failed: ' . $e->getMessage());
        crm_failed_lead_dump($payload ?? ['name' => $clientName, 'email' => $clientEmail,
            'form_type' => $formType, 'message' => $message]);
    }
} else {
    crm_failed_lead_dump(['name' => $clientName, 'email' => $clientEmail,
        'form_type' => $formType, 'message' => $message, 'reason' => 'db unavailable']);
}

/* ---- 7. Best-effort email (notification + auto-reply), fully logged ---- */
$SMTP_HOST = getenv('SMTP_HOST') ?: 'smtp.hostinger.com';
$SMTP_PORT = (int)(getenv('SMTP_PORT') ?: 465);
$SMTP_USER = getenv('SMTP_USER') ?: 'sales@fabrioza.com';
$SMTP_PASS = getenv('SMTP_PASS') ?: '';
$TO_EMAILS  = array_filter(array_map('trim', explode(',', getenv('MAIL_TO') ?: $SMTP_USER)));
$TO_EMAIL   = $TO_EMAILS[0];
$FROM_EMAIL = $SMTP_USER;

$notifSubject = "New Lead" . ($leadId ? " #$leadId" : "") . ": $formType - $clientName";
$notifBody = buildNotificationEmail($leadId, $formType, $clientName, $clientEmail, $company, $country, $productType, $quantity, $message, $source, $sourcePage);

$notifSent = false;
if ($SMTP_PASS !== '') {
    foreach ($TO_EMAILS as $to) {
        [$sent, $err] = smtpSend($SMTP_HOST, $SMTP_PORT, $SMTP_USER, $SMTP_PASS, $FROM_EMAIL,
            $to, 'FABRIOZA Leads', $notifSubject, $notifBody, $clientEmail, $clientName);
        logEmail($db, $leadId, $to, $notifSubject, $sent, $err);
        $notifSent = $notifSent || $sent;
    }
    $autoSubject = 'Thank you for contacting FABRIOZA - We will respond within 24 hours';
    [$autoSent, $autoErr] = smtpSend($SMTP_HOST, $SMTP_PORT, $SMTP_USER, $SMTP_PASS, $FROM_EMAIL,
        $clientEmail, $clientName ?: 'there', $autoSubject,
        getAutoReplyTemplate($clientName, $formType), $TO_EMAIL, 'FABRIOZA');
    logEmail($db, $leadId, $clientEmail, $autoSubject, $autoSent, $autoErr);
} else {
    logEmail($db, $leadId, implode(',', $TO_EMAILS), $notifSubject, false, 'SMTP_PASS not configured');
}

/* ---- 8. Respond. The lead is stored; email failure is an internal problem. ---- */
crm_file_log('LEAD' . ($leadId ? " #$leadId" : ' (DB-FAILED)') . " form=$formType email=$clientEmail notif=" . ($notifSent ? 'sent' : 'FAILED'));
if ($leadId !== null || $notifSent) {
    echo json_encode(['success' => true, 'message' => 'Thank you! We will get back to you within 24 hours.']);
} else {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Something went wrong - please email us directly at info@fabrioza.com']);
}

/* ================= helpers ================= */

function logEmail(?PDO $db, ?int $leadId, string $recipient, string $subject, bool $ok, string $err = ''): void {
    if (!$db) { return; }
    try {
        $db->prepare('INSERT INTO email_log (lead_id, recipient, subject, status, error) VALUES (?,?,?,?,?)')
           ->execute([$leadId, $recipient, mb_substr($subject, 0, 200), $ok ? 'sent' : 'failed', mb_substr($err, 0, 500)]);
    } catch (Throwable $e) {
        error_log('FABRIOZA CRM email_log failed: ' . $e->getMessage());
    }
}

function smtpSend($host, $port, $user, $pass, $from, $to, $toName, $subject, $htmlBody, $replyTo = '', $replyToName = ''): array {
    // Retry up to 3 attempts. Delay is 3s (not 5s) and SMTP timeout 8s so the
    // absolute worst case (3x8s + 2x3s = 30s) stays inside PHP's request
    // budget - and the lead is already saved in SQLite before we ever get here.
    $attempts = 3; $delay = 3; $lastErr = '';
    for ($i = 1; $i <= $attempts; $i++) {
        [$ok, $err] = smtpSendOnce($host, $port, $user, $pass, $from, $to, $toName, $subject, $htmlBody, $replyTo, $replyToName);
        if ($ok) { return [true, $i > 1 ? "succeeded on attempt $i" : '']; }
        $lastErr = $err;
        if ($i < $attempts) { sleep($delay); }
    }
    return [false, "after $attempts attempts: $lastErr"];
}

function smtpSendOnce($host, $port, $user, $pass, $from, $to, $toName, $subject, $htmlBody, $replyTo = '', $replyToName = ''): array {
    try {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host       = $host;
        $mail->SMTPAuth   = true;
        $mail->Username   = $user;
        $mail->Password   = $pass;
        $mail->Port       = $port;
        $mail->SMTPSecure = ($port === 587) ? PHPMailer::ENCRYPTION_STARTTLS : PHPMailer::ENCRYPTION_SMTPS;
        $mail->Timeout    = 8;
        $mail->CharSet    = 'UTF-8';
        $mail->setFrom($from, 'FABRIOZA');
        $mail->addAddress($to, $toName);
        if ($replyTo !== '') { $mail->addReplyTo($replyTo, $replyToName); }
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $htmlBody;
        $mail->AltBody = strip_tags(preg_replace('/<br\s*\/?>/i', "\n", $htmlBody));
        return [$mail->send(), ''];
    } catch (Exception $e) {
        error_log('FABRIOZA mailer: ' . $e->getMessage());
        return [false, $e->getMessage()];
    }
}

function sanitize($str) {
    $str = preg_replace('/[\r\n]+/', ' ', (string)$str);
    return htmlspecialchars(strip_tags(trim($str)), ENT_QUOTES, 'UTF-8');
}
function h($str) {
    return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
}

function buildNotificationEmail($leadId, $formType, $name, $email, $company, $country, $productType, $quantity, $message, $source, $sourcePage): string {
    $rows = '';
    foreach ([
        'Lead ID' => $leadId ? "#$leadId (saved in CRM)" : 'not saved - check server logs',
        'Form Type' => $formType, 'Name' => $name, 'Email' => $email,
        'Company' => $company, 'Country' => $country, 'Product Type' => $productType,
        'Quantity' => $quantity, 'Message' => nl2br(h($message)), 'Source' => $source,
        'Page' => $sourcePage, 'Date' => date('Y-m-d H:i:s'),
    ] as $label => $val) {
        if ($val === '' || $val === null) { continue; }
        $safe = ($label === 'Message') ? $val : h((string)$val);
        $rows .= "<div class='field'><div class='label'>$label:</div><div>$safe</div></div>";
    }
    return "<!DOCTYPE html>
<html><head><style>
body{font-family:Arial,sans-serif;line-height:1.6;color:#333}
.container{max-width:600px;margin:0 auto;padding:20px}
.header{background:#4A7C59;color:white;padding:20px;text-align:center}
.content{background:#f9f9f9;padding:20px;border:1px solid #ddd}
.field{margin-bottom:15px}
.label{font-weight:bold;color:#4A7C59}
.footer{text-align:center;padding:20px;color:#999;font-size:12px}
</style></head><body>
<div class='container'>
<div class='header'><h2>New Lead from FABRIOZA Website</h2></div>
<div class='content'>$rows</div>
<div class='footer'><p>Saved to the FABRIOZA CRM before this email was sent.</p></div>
</div></body></html>";
}

function getAutoReplyTemplate($name, $formType) {
    $firstName = explode(' ', $name)[0] ?: 'there';
    return "<!DOCTYPE html>
<html><head><style>
body{font-family:Arial,sans-serif;line-height:1.6;color:#333}
.container{max-width:600px;margin:0 auto;padding:20px}
.header{background:#4A7C59;color:white;padding:30px 20px;text-align:center}
.header h1{margin:0;font-size:24px}
.content{background:#fff;padding:30px 20px;border:1px solid #ddd}
.cta{background:#4A7C59;color:white;padding:15px;text-align:center;margin:20px 0;border-radius:5px}
.cta a{color:white;text-decoration:none;font-weight:bold}
.features{background:#f5f5f5;padding:20px;margin:20px 0;border-radius:5px}
.feature{margin-bottom:10px;padding-left:25px;position:relative}
.feature::before{content:'\\2713';position:absolute;left:0;color:#4A7C59;font-weight:bold}
.footer{text-align:center;padding:20px;color:#999;font-size:12px;border-top:1px solid #eee}
</style></head><body>
<div class='container'>
<div class='header'>
<h1>FABRIOZA</h1>
<p>Premium Private Label Clothing Manufacturer</p>
</div>
<div class='content'>
<p>Hi " . h($firstName) . ",</p>
<p>Thank you for reaching out to FABRIOZA! We've received your inquiry and a member of our team will personally respond within <strong>24 hours</strong>.</p>
<div class='features'>
<div class='feature'>MOQ starts at just <strong>50 pieces</strong> (20-piece trial orders available)</div>
<div class='feature'>Free design mockups within 24-48 hours</div>
<div class='feature'>Sample production in 5-7 business days</div>
<div class='feature'>Factory-direct pricing (save 30-50%)</div>
<div class='feature'>ISO 9001 certified &amp; amfori BSCI audited</div>
</div>
<div class='cta'>
<a href='https://calendly.com/fabrioza/30min'>Book a Free 30-Minute Consultation</a>
</div>
<p>In the meantime, feel free to explore our website or book a meeting directly using the link above.</p>
<p>Best regards,<br><strong>The FABRIOZA Team</strong></p>
<p style='font-size:12px;color:#666'>USA Office: 157 Everett Sq, McDonough, GA 30252<br>Factory / Head Office: Saro Street, near Fateh Garh Road, Sialkot 51310, Pakistan<br>Email: info@fabrioza.com</p>
</div>
<div class='footer'>
<p>This is an automated response. Please do not reply to this email.</p>
<p>&copy; 2026 FABRIOZA. All rights reserved.</p>
</div>
</div></body></html>";
}
