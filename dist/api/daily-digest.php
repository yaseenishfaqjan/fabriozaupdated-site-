<?php
/**
 * FABRIOZA CRM daily digest (CLI only). Sends a morning summary to MAIL_TO:
 * new leads in the last 24h, overdue follow-ups, high-score new leads.
 *
 * Cron on the VPS (see CRM-HANDOFF.md):
 *   0 6 * * * docker exec fabrioza-web php /var/www/html/api/daily-digest.php
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
require __DIR__ . '/lib/Exception.php';
require __DIR__ . '/lib/PHPMailer.php';
require __DIR__ . '/lib/SMTP.php';
require __DIR__ . '/db.php';

$db = crm_db();
$new = $db->query("SELECT id,name,company,form_type,lead_score FROM leads
    WHERE created_at >= datetime('now','-1 day') AND status != 'spam' ORDER BY lead_score DESC")->fetchAll();
$overdue = $db->query("SELECT id,name,company,next_follow_up FROM leads
    WHERE next_follow_up IS NOT NULL AND date(next_follow_up) < date('now')
      AND status NOT IN ('won','lost','spam') ORDER BY next_follow_up")->fetchAll();
$hot = $db->query("SELECT id,name,company,lead_score FROM leads
    WHERE lead_score >= 35 AND status = 'new' ORDER BY lead_score DESC")->fetchAll();

if (!$new && !$overdue && !$hot) { echo "Nothing to report - digest skipped.\n"; exit; }

function rows(array $rs, callable $fmt): string {
    if (!$rs) { return '<p style="color:#999">None.</p>'; }
    $out = '<ul>';
    foreach ($rs as $r) { $out .= '<li><a href="https://fabrioza.com/admin/lead.php?id=' . $r['id'] . '">#' . $r['id'] . '</a> ' . htmlspecialchars($fmt($r)) . '</li>'; }
    return $out . '</ul>';
}
$body = '<h2 style="color:#4A7C59">FABRIOZA CRM - Daily Digest ' . gmdate('Y-m-d') . '</h2>'
  . '<h3>New leads (24h): ' . count($new) . '</h3>' . rows($new, fn($r) => "{$r['name']} ({$r['company']}) - {$r['form_type']} - score {$r['lead_score']}")
  . '<h3 style="color:#b00020">Overdue follow-ups: ' . count($overdue) . '</h3>' . rows($overdue, fn($r) => "{$r['name']} ({$r['company']}) - due {$r['next_follow_up']}")
  . '<h3>High-score new leads: ' . count($hot) . '</h3>' . rows($hot, fn($r) => "{$r['name']} ({$r['company']}) - score {$r['lead_score']}")
  . '<p><a href="https://fabrioza.com/admin/">Open the CRM &rarr;</a></p>';

$host = getenv('SMTP_HOST') ?: 'smtp.gmail.com';
$port = (int)(getenv('SMTP_PORT') ?: 587);
$user = getenv('SMTP_USER') ?: '';
$pass = getenv('SMTP_PASS') ?: '';
$tos = array_filter(array_map('trim', explode(',', getenv('MAIL_TO') ?: $user)));
if ($pass === '') { fwrite(STDERR, "SMTP not configured\n"); exit(1); }

foreach ($tos as $to) {
    try {
        $m = new PHPMailer(true);
        $m->isSMTP(); $m->Host = $host; $m->SMTPAuth = true;
        $m->Username = $user; $m->Password = $pass; $m->Port = $port;
        $m->SMTPSecure = ($port === 587) ? PHPMailer::ENCRYPTION_STARTTLS : PHPMailer::ENCRYPTION_SMTPS;
        $m->CharSet = 'UTF-8'; $m->Timeout = 10;
        $m->setFrom($user, 'FABRIOZA CRM');
        $m->addAddress($to);
        $m->isHTML(true);
        $m->Subject = 'CRM digest: ' . count($new) . ' new, ' . count($overdue) . ' overdue - ' . gmdate('Y-m-d');
        $m->Body = $body;
        $m->send();
        echo "digest sent to $to\n";
    } catch (Exception $e) {
        fwrite(STDERR, "digest to $to failed: " . $e->getMessage() . "\n");
    }
}
