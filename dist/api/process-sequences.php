<?php
/**
 * FABRIOZA CRM follow-up sequence processor (CLI only, run daily via cron):
 *   30 6 * * * docker exec fabrioza-web php /var/www/html/api/process-sequences.php
 *
 * For each sequence rule: find leads of that form_type whose lead age has
 * reached the rule's day offset, that are still in an "open" status, not
 * paused, and that have not already received this template (checked against
 * email_log with a [seq:*] subject tag). Sends + logs.
 *
 * Also applies the status auto-progression rules:
 *   - quoted for 14+ days with no follow-up date -> next_follow_up = today
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
require __DIR__ . '/lib/Exception.php';
require __DIR__ . '/lib/PHPMailer.php';
require __DIR__ . '/lib/SMTP.php';
require __DIR__ . '/db.php';

/* Sequence config: form_type => list of [day_offset, template, only_status] */
$SEQUENCES = [
    'Quote'         => [[3, 'quote-day3', 'new']],
    'Contact Form'  => [[3, 'quote-day3', 'new']],
    'Trial Order'   => [[2, 'trial-day2', 'new']],
    'Guide Download'            => [[5, 'guide-day5', 'new']],
    'Free Guide Download'       => [[5, 'guide-day5', 'new']],
    'Free Pricing Guide Download' => [[5, 'guide-day5', 'new']],
];

$db = crm_db();
$host = getenv('SMTP_HOST') ?: 'smtp.gmail.com';
$port = (int)(getenv('SMTP_PORT') ?: 587);
$user = getenv('SMTP_USER') ?: '';
$pass = getenv('SMTP_PASS') ?: '';
$dry  = in_array('--dry-run', $argv ?? [], true);

function seqMail($host, $port, $user, $pass, $to, $toName, $subject, $body): array {
    try {
        $m = new PHPMailer(true);
        $m->isSMTP(); $m->Host = $host; $m->SMTPAuth = true;
        $m->Username = $user; $m->Password = $pass; $m->Port = $port;
        $m->SMTPSecure = ($port === 587) ? PHPMailer::ENCRYPTION_STARTTLS : PHPMailer::ENCRYPTION_SMTPS;
        $m->CharSet = 'UTF-8'; $m->Timeout = 10;
        $m->setFrom($user, 'FABRIOZA');
        $m->addAddress($to, $toName);
        $m->addReplyTo(getenv('MAIL_TO') ? explode(',', getenv('MAIL_TO'))[0] : $user, 'FABRIOZA');
        $m->isHTML(true); $m->Subject = $subject; $m->Body = $body;
        $m->AltBody = strip_tags($body);
        return [$m->send(), ''];
    } catch (Exception $e) { return [false, $e->getMessage()]; }
}

$sent = $skipped = 0;
foreach ($SEQUENCES as $formType => $rules) {
    foreach ($rules as [$days, $tpl, $onlyStatus]) {
        $tag = "[seq:$tpl]";
        $st = $db->prepare(
            "SELECT * FROM leads
             WHERE form_type = ? AND status = ? AND sequences_paused = 0
               AND created_at <= datetime('now', ?)
               AND created_at >  datetime('now', ?)      -- don't mail ancient leads on first rollout
               AND id NOT IN (SELECT lead_id FROM email_log WHERE lead_id IS NOT NULL AND subject LIKE ?)");
        $st->execute([$formType, $onlyStatus, "-$days days", '-' . ($days + 14) . ' days', "$tag%"]);
        foreach ($st->fetchAll() as $lead) {
            $make = require __DIR__ . "/email-templates/$tpl.php";
            $mail = $make($lead);
            $subject = $tag . ' ' . $mail['subject'];
            if ($dry) { echo "DRY: would send $tpl to lead #{$lead['id']} <{$lead['email']}>\n"; continue; }
            if ($pass === '') { fwrite(STDERR, "SMTP not configured\n"); exit(1); }
            [$ok, $err] = seqMail($host, $port, $user, $pass, $lead['email'], $lead['name'], $mail['subject'], $mail['body']);
            $db->prepare('INSERT INTO email_log (lead_id, recipient, subject, status, error) VALUES (?,?,?,?,?)')
               ->execute([$lead['id'], $lead['email'], mb_substr($subject, 0, 200), $ok ? 'sent' : 'failed', mb_substr($err, 0, 500)]);
            echo ($ok ? 'sent' : 'FAILED') . ": $tpl -> lead #{$lead['id']} <{$lead['email']}>\n";
            $ok ? $sent++ : $skipped++;
        }
    }
}

/* Auto-progression: quoted 14+ days -> surface in the follow-up queue today */
$n = $db->exec("UPDATE leads SET next_follow_up = date('now')
    WHERE status = 'quoted' AND next_follow_up IS NULL
      AND created_at <= datetime('now','-14 days')");
echo "sequences: $sent sent, $skipped failed | stale-quoted surfaced: $n\n";
