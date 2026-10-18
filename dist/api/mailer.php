<?php
/**
 * FABRIOZA CRM - shared mailer.
 *
 * One implementation of "send a mail and log it", used by BOTH the public form
 * handler (send-email.php) and the admin resend button (admin/lead.php).
 * Never duplicate these functions into a page - a second copy will drift.
 *
 * Transport comes from the environment (see .env / CRM-HANDOFF.md section 8):
 *   SMTP_HOST / SMTP_PORT / SMTP_USER / SMTP_PASS
 * Port 587 uses STARTTLS, anything else (465) uses implicit SSL.
 */
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/lib/Exception.php';
require_once __DIR__ . '/lib/PHPMailer.php';
require_once __DIR__ . '/lib/SMTP.php';

/** HTML-escape helper used by the notification/auto-reply templates. */
if (!function_exists('h')) {
    function h($str) {
        return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
    }
}

/** Transport settings, resolved once from the environment. */
function fab_smtp_config(): array {
    return [
        'host' => getenv('SMTP_HOST') ?: 's99.veladns.com',
        'port' => (int)(getenv('SMTP_PORT') ?: 465),
        'user' => getenv('SMTP_USER') ?: 'info@fabrioza.com',
        'pass' => getenv('SMTP_PASS') ?: '',
    ];
}

/** Notification recipients from MAIL_TO, falling back to the sending mailbox. */
function fab_mail_to(): array {
    $c = fab_smtp_config();
    $list = array_filter(array_map('trim', explode(',', getenv('MAIL_TO') ?: $c['user'])));
    return $list ?: [$c['user']];
}

function logEmail(?PDO $db, ?int $leadId, string $recipient, string $subject, bool $ok, string $err = ''): void {
    if (!$db) { return; }
    try {
        $db->prepare('INSERT INTO email_log (lead_id, recipient, subject, status, error) VALUES (?,?,?,?,?)')
           ->execute([$leadId, $recipient, mb_substr($subject, 0, 200), $ok ? 'sent' : 'failed', mb_substr($err, 0, 500)]);
    } catch (Throwable $e) {
        error_log('FABRIOZA CRM email_log failed: '. $e->getMessage());
    }
}

/* ===================================================================
 * Resend transport (HTTPS API, port 443)
 *
 * Why this exists: on 18 Oct 2026 the cPanel mail host (95.216.22.216)
 * stopped answering this VPS on EVERY port - 25, 465, 587, 443 and even
 * ICMP - while Gmail, Office365 and Resend all connected instantly from the
 * same box. That is a firewall ban on 209.145.55.76, almost certainly an
 * LFD/cPHulk block triggered by weeks of failed SMTP auth retries. Outbound
 * SMTP itself was never blocked.
 *
 * Resend goes over HTTPS on 443, so it cannot be killed by an SMTP port
 * policy, and a bad API key returns a clean 401 instead of hammering a login
 * until the IP is banned again.
 *
 * The From address does NOT change: mail still goes out as info@fabrioza.com.
 * Resend is the pipe, not the identity. That requires fabrioza.com to be
 * verified in Resend - see RESEND-SETUP.md, and note the strict DMARC
 * alignment constraint documented there.
 * =================================================================== */

function fab_resend_config(): array {
    return [
        'key'  => getenv('RESEND_API_KEY') ?: '',
        'from' => getenv('RESEND_FROM') ?: (getenv('SMTP_USER') ?: 'info@fabrioza.com'),
        'name' => getenv('RESEND_FROM_NAME') ?: 'FABRIOZA',
    ];
}

function fab_resend_enabled(): bool {
    return fab_resend_config()['key'] !== '';
}

/**
 * Send one message through the Resend API.
 * @return array{0:bool,1:string} [ok, note-or-error]
 */
function resendSend($to, $toName, $subject, $htmlBody, $replyTo = '', $replyToName = ''): array {
    $c = fab_resend_config();
    if ($c['key'] === '') { return [false, 'resend: RESEND_API_KEY not set']; }

    $payload = [
        'from'    => sprintf('%s <%s>', $c['name'], $c['from']),
        'to'      => [$to],
        'subject' => $subject,
        'html'    => $htmlBody,
    ];
    if ($replyTo !== '') {
        $payload['reply_to'] = $replyToName !== ''
            ? sprintf('%s <%s>', $replyToName, $replyTo)
            : $replyTo;
    }

    $ch = curl_init('https://api.resend.com/emails');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $c['key'],
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_SLASHES),
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $cerr = curl_error($ch);
    // No curl_close(): a no-op since PHP 8.0 and deprecated in 8.5.

    if ($body === false) { return [false, 'resend: ' . ($cerr ?: 'request failed')]; }
    $j = json_decode($body, true);

    if ($code >= 200 && $code < 300 && !empty($j['id'])) {
        return [true, 'via resend id=' . $j['id']];
    }
    // Resend returns a readable message; keep it, it is far more useful than
    // PHPMailer's generic SMTP text.
    $msg = $j['message'] ?? $j['error'] ?? mb_substr((string)$body, 0, 200);
    return [false, "resend HTTP $code: $msg"];
}

/**
 * The single entry point every caller should use.
 *
 * Resend first when configured, SMTP second. If Resend is not configured the
 * behaviour is exactly what it was before, so this is safe to deploy before
 * the API key exists.
 *
 * @param bool $retrySmtp false = one SMTP attempt (an admin is waiting)
 */
function fab_send($to, $toName, $subject, $htmlBody, $replyTo = '', $replyToName = '', bool $retrySmtp = true): array {
    $notes = [];

    if (fab_resend_enabled()) {
        [$ok, $note] = resendSend($to, $toName, $subject, $htmlBody, $replyTo, $replyToName);
        if ($ok) { return [true, $note]; }
        $notes[] = $note;
    }

    $c = fab_smtp_config();
    $fn = $retrySmtp ? 'smtpSend' : 'smtpSendOnce';
    [$ok, $err] = $fn($c['host'], $c['port'], $c['user'], $c['pass'], $c['user'],
                      $to, $toName, $subject, $htmlBody, $replyTo, $replyToName);
    if ($ok) {
        $notes[] = 'via smtp' . ($err !== '' ? " ($err)" : '');
        return [true, implode(' | ', $notes)];
    }
    $notes[] = 'smtp: ' . $err;
    return [false, implode(' | ', $notes)];
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
        error_log('FABRIOZA mailer: '. $e->getMessage());
        return [false, $e->getMessage()];
    }
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
