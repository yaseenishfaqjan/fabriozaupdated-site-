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

require __DIR__. '/mailer.php';   // shared mailer: smtpSend, logEmail, buildNotificationEmail
require __DIR__. '/db.php';
require __DIR__. '/uploads.php';  // lead attachments (base64 -> /var/data/uploads)

header('Content-Type: application/json');
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowedOrigins = ['https://fabrioza.com', 'https://www.fabrioza.com', 'http://localhost:8080', 'http://localhost:8085'];
if (in_array($origin, $allowedOrigins, true)) {
    header('Access-Control-Allow-Origin: '. $origin);
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
    crm_file_log('SPAM(honeypot) form='. substr((string)($data['form_type'] ?? '?'), 0, 40)
     . ' email='. substr((string)($data['email'] ?? '?'), 0, 60));
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
$gclid       = mb_substr(sanitize($data['gclid'] ?? ''), 0, 200);
$fbclid      = mb_substr(sanitize($data['fbclid'] ?? ''), 0, 200);
$referrer    = mb_substr(sanitize($data['referrer'] ?? ''), 0, 400);
$landingPage = mb_substr(sanitize($data['landing_page'] ?? ''), 0, 200);
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
    error_log('FABRIOZA CRM db error (pre-insert): '. $e->getMessage());
    $db = null;
    $ipHash = '';
}

/* ---- 6. INSERT THE LEAD FIRST ---- */
$leadId = null;
$savedFiles = [];
$rejectedFiles = [];
if ($db) {
    try {
        $payload = [
            'name' => $clientName, 'email' => $clientEmail, 'company' => $company,
            'country' => $country, 'product_type' => $productType, 'quantity' => $quantity,
            'message' => $message, 'form_type' => $formType, 'source_page' => $sourcePage,
            'utm_source' => $utmSource, 'utm_medium' => $utmMedium, 'utm_campaign' => $utmCampaign,
            'gclid' => $gclid, 'fbclid' => $fbclid,
            'referrer' => $referrer, 'landing_page' => $landingPage,
        ];
        $score = crm_lead_score($payload);
        $stmt = $db->prepare('INSERT INTO leads
            (name, email, company, country, product_type, quantity, message, form_type,
             source_page, utm_source, utm_medium, utm_campaign,
             gclid, fbclid, referrer, landing_page, lead_score,
             gdpr_consent, gdpr_consent_ts, ip_hash)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,1,CURRENT_TIMESTAMP,?)');
        $stmt->execute([$clientName, $clientEmail, $company, $country, $productType, $quantity,
            $message, $formType, $sourcePage, $utmSource, $utmMedium, $utmCampaign,
            $gclid, $fbclid, $referrer, $landingPage, $score, $ipHash]);
        $leadId = (int)$db->lastInsertId();

        /* ---- attachments -------------------------------------------------
           The form posts JSON, so files arrive base64-encoded in `files`.
           A rejected file must never fail the lead: the enquiry is worth far
           more than the attachment, so failures are recorded and reported
           back, and the submission still succeeds. */
        /* Its own try/catch on purpose. This block sits inside the lead
           insert's try, whose catch calls crm_failed_lead_dump() - so an
           exception thrown while storing a file (a full disk, a locked DB)
           would suppress the Telegram alert and record a lead that IS saved
           as a failure. The attachment must never be able to do that. */
        try {
            $incoming = $data['files'] ?? [];
            if (is_array($incoming)) {
                foreach (array_slice($incoming, 0, CRM_UPLOAD_MAX_FILES) as $f) {
                    if (!is_array($f)) { continue; }
                    $r = crm_store_upload($db, $leadId, $f);
                    if ($r['ok']) {
                        $savedFiles[] = $r['name'] . ' (' . crm_format_bytes($r['size']) . ')';
                    } else {
                        $rejectedFiles[] = (string)($f['name'] ?? 'file') . ' - ' . $r['error'];
                        crm_file_log("LEAD $leadId attachment rejected: "
                            . (string)($f['name'] ?? '?') . ' - ' . $r['error']);
                    }
                }
            }
        } catch (Throwable $e) {
            $rejectedFiles[] = 'attachment could not be stored';
            crm_file_log("LEAD $leadId attachment EXCEPTION: " . $e->getMessage());
            error_log('FABRIOZA attachment store failed: ' . $e->getMessage());
        }
        $fileLine = $savedFiles ? "\n📎 " . count($savedFiles) . " file(s): "
            . implode(', ', $savedFiles) : '';
        // WhatsApp gets no name, company or email: see crm_notify().
        // Attachment COUNT only - a filename can carry a customer's name.
        $waFiles = $savedFiles ? "
📎 " . count($savedFiles) . " file(s) attached" : '';
        crm_notify("🟢 NEW LEAD #$leadId ($formType)
$clientName". ($company !== '' ? " - $company" : '')
         . "
$clientEmail". ($quantity !== '' ? "
Qty: $quantity" : ''). "
Score: $score"
         . $fileLine . "
https://fabrioza.com/admin/lead.php?id=$leadId",
            "🟢 New lead #$leadId ($formType)
Score: $score" . $waFiles . "
https://fabrioza.com/admin/lead.php?id=$leadId");
    } catch (Throwable $e) {
        error_log('FABRIOZA CRM lead insert failed: '. $e->getMessage());
        crm_failed_lead_dump($payload ?? ['name' => $clientName, 'email' => $clientEmail,
            'form_type' => $formType, 'message' => $message]);
    }
} else {
    crm_failed_lead_dump(['name' => $clientName, 'email' => $clientEmail,
        'form_type' => $formType, 'message' => $message, 'reason' => 'db unavailable']);
}

/* ---- 7. Best-effort email (notification + auto-reply), fully logged ---- */
$SMTP_HOST = getenv('SMTP_HOST') ?: 's99.veladns.com';
$SMTP_PORT = (int)(getenv('SMTP_PORT') ?: 465);
$SMTP_USER = getenv('SMTP_USER') ?: 'info@fabrioza.com';
$SMTP_PASS = getenv('SMTP_PASS') ?: '';
$TO_EMAILS  = array_filter(array_map('trim', explode(',', getenv('MAIL_TO') ?: $SMTP_USER)));
$TO_EMAIL   = $TO_EMAILS[0];
$FROM_EMAIL = $SMTP_USER;

$notifSubject = "New Lead". ($leadId ? " #$leadId" : ""). ": $formType - $clientName";
$notifBody = buildNotificationEmail($leadId, $formType, $clientName, $clientEmail, $company, $country, $productType, $quantity, $message, $source, $sourcePage);

/* Attachments are NOT attached to this email on purpose: the files are already
   stored on the VPS, and a 10 MB MIME part is a reliable way to make an SMTP
   send time out. The notification names them and links to the admin instead. */
if ($savedFiles || $rejectedFiles) {
    $att = '<p style="margin:16px 0 4px"><strong>Attachments</strong></p><ul>';
    foreach ($savedFiles as $f) {
        $att .= '<li>' . h($f) . '</li>';
    }
    foreach ($rejectedFiles as $f) {
        $att .= '<li style="color:#b00">rejected - ' . h($f) . '</li>';
    }
    $att .= '</ul><p style="font-size:12px;color:#666">Download them from the lead page: '
          . 'https://fabrioza.com/admin/lead.php?id=' . (int)$leadId . '</p>';
    $notifBody .= $att;
}

$notifSent = false;
// Either transport is enough to try a send. Gating on SMTP_PASS alone would
// silently skip mail entirely on a box where only Resend is configured.
if (fab_resend_enabled() || $SMTP_PASS !== '') {
    foreach ($TO_EMAILS as $to) {
        [$sent, $err] = fab_send($to, 'FABRIOZA Leads', $notifSubject, $notifBody,
                                 $clientEmail, $clientName);
        logEmail($db, $leadId, $to, $notifSubject, $sent, $err);
        $notifSent = $notifSent || $sent;
    }
    $autoSubject = 'Thank you for contacting FABRIOZA - We will respond within 24 hours';
    [$autoSent, $autoErr] = fab_send($clientEmail, $clientName ?: 'there', $autoSubject,
        getAutoReplyTemplate($clientName, $formType), $TO_EMAIL, 'FABRIOZA');
    logEmail($db, $leadId, $clientEmail, $autoSubject, $autoSent, $autoErr);
} else {
    logEmail($db, $leadId, implode(',', $TO_EMAILS), $notifSubject, false,
             'no mail transport configured (set RESEND_API_KEY or SMTP_PASS)');
}

/* ---- 8. Respond. The lead is stored; email failure is an internal problem. ---- */
crm_file_log('LEAD'. ($leadId ? " #$leadId" : ' (DB-FAILED)'). " form=$formType email=$clientEmail notif=". ($notifSent ? 'sent' : 'FAILED'));
if ($leadId !== null || $notifSent) {
    echo json_encode(['success' => true, 'message' => 'Thank you! We will get back to you within 24 hours.']);
} else {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Something went wrong - please email us directly at info@fabrioza.com']);
}

/* ================= helpers ================= */


function sanitize($str) {
    $str = preg_replace('/[\r\n]+/', ' ', (string)$str);
    return htmlspecialchars(strip_tags(trim($str)), ENT_QUOTES, 'UTF-8');
}


function getAutoReplyTemplate($name, $formType) {
    $firstName = explode(' ', $name)[0] ?: 'there';
    return "<!DOCTYPE html>
<html><head><meta charset='utf-8'><meta name='viewport' content='width=device-width,initial-scale=1'><style>
body{margin:0;padding:0;background:#f4f4f2;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Arial,sans-serif;color:#23262b;line-height:1.65}
.wrap{max-width:600px;margin:0 auto;background:#ffffff}
.header{background:#1d2b22;padding:30px 28px}
.header h1{margin:0;font-size:21px;letter-spacing:3px;color:#ffffff;font-weight:700}
.header p{margin:6px 0 0;font-size:13px;color:#9db9a8}
.content{padding:30px 28px}
.content p{margin:0 0 16px;font-size:15px}
.facts{background:#f6f7f6;border-left:3px solid #4A7C59;padding:18px 20px;margin:22px 0}
.facts div{font-size:14px;color:#3d4248;padding:5px 0}
.facts b{color:#1d2b22}
.links{margin:24px 0}
.links a{display:block;padding:13px 18px;background:#4A7C59;color:#ffffff !important;text-decoration:none;font-weight:600;font-size:14px;border-radius:5px;margin-bottom:9px;text-align:center}
.links a.alt{background:#ffffff;color:#1d2b22 !important;border:1px solid #d6d9d7}
.sig{margin-top:26px;padding-top:20px;border-top:1px solid #e7e9e8;font-size:13px;color:#5b6168}
.sig b{color:#1d2b22;font-size:14px}
.footer{padding:20px 28px;background:#f6f7f6;font-size:11.5px;color:#8b9097;line-height:1.6}
@media(max-width:620px){.content,.header,.footer{padding-left:20px;padding-right:20px}}
</style></head><body>
<div class='wrap'>
<div class='header'>
<h1>FABRIOZA</h1>
<p>Custom clothing manufacturer &middot; Sialkot, Pakistan</p>
</div>
<div class='content'>
<p>Hi ". h($firstName). ",</p>
<p>Thanks for getting in touch. Your enquiry has reached us and a real person will reply within <strong>one working day</strong> &mdash; not an auto-quote, an actual answer to what you asked.</p>
<div class='facts'>
<div><b>Minimum order:</b> from 20 pieces</div>
<div><b>Made by us:</b> cut and sewn in our own factory, not brokered out</div>
<div><b>Delivery:</b> DDP, so the price we quote is the price landed</div>
</div>
<p>While you wait, two things that might save us both a round of emails:</p>
<div class='links'>
<a href='https://fabrioza.com/design'>Design it and see a preview</a>
<a class='alt' href='https://fabrioza.com/brand-launch'>Starting a brand from scratch?</a>
</div>
<p>If you already have artwork, reply to this email and attach it &mdash; a logo, a sketch or a photo of something similar all work.</p>
<div class='sig'>
<b>The FABRIOZA team</b><br>
info@fabrioza.com &middot; +92 312 4815601<br>
Saro Street, near Fateh Garh Road, Sialkot 51310, Pakistan<br>
USA office: McDonough, Georgia (by appointment)
</div>
</div>
<div class='footer'>
You are receiving this because you sent an enquiry through fabrioza.com.
<strong>You can reply to this email</strong> &mdash; it reaches the same inbox our team works from.
</div>
</div>
</body></html>";
}
