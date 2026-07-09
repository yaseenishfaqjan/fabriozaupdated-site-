<?php
/**
 * FABRIOZA Form Handler - SMTP edition
 *
 * Sends through the cPanel mail server (where the fabrioza.com mailboxes
 * live) via authenticated SMTP, because the Docker/VPS container has no
 * local mail transport - PHP mail() cannot work here.
 *
 * Credentials come from environment variables set in docker-compose /
 * a .env file on the server (never committed to git):
 *   SMTP_HOST  (default: mail.fabrioza.com)
 *   SMTP_PORT  (default: 465 = SSL; use 587 for STARTTLS)
 *   SMTP_USER  (default: info@fabrioza.com)
 *   SMTP_PASS  (required - the mailbox password from cPanel)
 */

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require __DIR__ . '/lib/Exception.php';
require __DIR__ . '/lib/PHPMailer.php';
require __DIR__ . '/lib/SMTP.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success' => false, 'message' => 'Method not allowed']); exit; }

$data = json_decode(file_get_contents('php://input'), true) ?: $_POST;
if (empty($data)) { http_response_code(400); echo json_encode(['success' => false, 'message' => 'No data received']); exit; }

$SMTP_HOST = getenv('SMTP_HOST') ?: 'smtp.hostinger.com';
$SMTP_PORT = (int)(getenv('SMTP_PORT') ?: 465);
$SMTP_USER = getenv('SMTP_USER') ?: 'sales@fabrioza.com';
$SMTP_PASS = getenv('SMTP_PASS') ?: '';

// Leads are delivered to every address in MAIL_TO (comma-separated;
// defaults to the authenticated mailbox)
$TO_EMAILS  = array_filter(array_map('trim', explode(',', getenv('MAIL_TO') ?: $SMTP_USER)));
$TO_EMAIL   = $TO_EMAILS[0];
$FROM_EMAIL = $SMTP_USER;

if ($SMTP_PASS === '') {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Mail is not configured yet. Please contact us directly at info@fabrioza.com or via WhatsApp.']);
    exit;
}

$clientEmail = sanitize($data['email'] ?? '');
$clientName  = sanitize($data['name'] ?? '');
$formType    = sanitize($data['form_type'] ?? 'General Inquiry');
$company     = sanitize($data['company'] ?? '');
$productType = sanitize($data['product_type'] ?? '');
$quantity    = sanitize($data['quantity'] ?? '');
$message     = sanitize($data['message'] ?? '');
$source      = sanitize($data['source'] ?? '');

if (empty($clientEmail) || !filter_var($clientEmail, FILTER_VALIDATE_EMAIL)) { http_response_code(400); echo json_encode(['success' => false, 'message' => 'A valid email is required']); exit; }

// 1. Notification to info@fabrioza.com (Reply-To = the lead, so you can reply directly)
$notifSubject = "New Lead: $formType - $clientName";
$notifBody = "<!DOCTYPE html>
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
<div class='content'>
<div class='field'><div class='label'>Form Type:</div><div>" . h($formType) . "</div></div>
<div class='field'><div class='label'>Name:</div><div>" . h($clientName) . "</div></div>
<div class='field'><div class='label'>Email:</div><div>" . h($clientEmail) . "</div></div>
<div class='field'><div class='label'>Company:</div><div>" . h($company) . "</div></div>
<div class='field'><div class='label'>Product Type:</div><div>" . h($productType) . "</div></div>
<div class='field'><div class='label'>Quantity:</div><div>" . h($quantity) . "</div></div>
<div class='field'><div class='label'>Message:</div><div>" . nl2br(h($message)) . "</div></div>
<div class='field'><div class='label'>Source:</div><div>" . h($source) . "</div></div>
<div class='field'><div class='label'>Date:</div><div>" . date('Y-m-d H:i:s') . "</div></div>
</div>
<div class='footer'><p>This email was sent from your FABRIOZA website form handler.</p></div>
</div></body></html>";

$notifSent = false;
foreach ($TO_EMAILS as $to) {
    $sent = smtpSend($SMTP_HOST, $SMTP_PORT, $SMTP_USER, $SMTP_PASS, $FROM_EMAIL,
        $to, 'FABRIOZA Leads', $notifSubject, $notifBody, $clientEmail, $clientName);
    $notifSent = $notifSent || $sent;
}

// 2. Auto-reply to the client (best effort - a failure here must not fail the lead)
$autoSent = false;
if ($notifSent) {
    $autoSent = smtpSend($SMTP_HOST, $SMTP_PORT, $SMTP_USER, $SMTP_PASS, $FROM_EMAIL,
        $clientEmail, $clientName ?: 'there',
        'Thank you for contacting FABRIOZA - We will respond within 24 hours',
        getAutoReplyTemplate($clientName, $formType), $TO_EMAIL, 'FABRIOZA');
}

if ($notifSent) {
    echo json_encode(['success' => true, 'message' => 'Thank you! We will get back to you within 24 hours.']);
} else {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to send email. Please try again or contact us directly at info@fabrioza.com']);
}

function smtpSend($host, $port, $user, $pass, $from, $to, $toName, $subject, $htmlBody, $replyTo = '', $replyToName = '') {
    try {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host       = $host;
        $mail->SMTPAuth   = true;
        $mail->Username   = $user;
        $mail->Password   = $pass;
        $mail->Port       = $port;
        $mail->SMTPSecure = ($port === 587) ? PHPMailer::ENCRYPTION_STARTTLS : PHPMailer::ENCRYPTION_SMTPS;
        $mail->Timeout    = 15;
        $mail->CharSet    = 'UTF-8';

        $mail->setFrom($from, 'FABRIOZA');
        $mail->addAddress($to, $toName);
        if ($replyTo !== '') { $mail->addReplyTo($replyTo, $replyToName); }
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $htmlBody;
        $mail->AltBody = strip_tags(preg_replace('/<br\s*\/?>/i', "\n", $htmlBody));

        return $mail->send();
    } catch (Exception $e) {
        error_log('FABRIOZA mailer: ' . $e->getMessage());
        return false;
    }
}

function sanitize($str) {
    // Strip CR/LF so client values can never inject mail headers
    $str = preg_replace('/[\r\n]+/', ' ', (string)$str);
    return htmlspecialchars(strip_tags(trim($str)), ENT_QUOTES, 'UTF-8');
}
function h($str) {
    return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
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
<div class='feature'>MOQ starts at just <strong>50 pieces</strong></div>
<div class='feature'>Free design mockups within 24-48 hours</div>
<div class='feature'>Sample production in 5-7 business days</div>
<div class='feature'>Factory-direct pricing (save 30-50%)</div>
<div class='feature'>ISO 9001, BSCI, OEKO-TEX certified</div>
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
