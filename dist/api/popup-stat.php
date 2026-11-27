<?php
/**
 * Counter for the feature popup (assets/fab-popup.js).
 *
 * Deliberately tiny and deliberately anonymous: an event name and a day, no
 * IP, no identifier, nothing that makes a visitor re-identifiable. The
 * question this answers is "does the popup earn its place", which needs three
 * totals and nothing more.
 *
 * No CSRF token. The popup fires on pages a visitor may have sat on past the
 * token's session, and the worst a forged request can do is inflate a counter
 * we only read ourselves. Weigh that against a real failure mode: a 403 here
 * would be logged as a broken endpoint on every page of the site.
 */
declare(strict_types=1);

require_once __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex, nofollow');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false]);
    exit;
}

$raw  = file_get_contents('php://input') ?: '';
$data = json_decode($raw, true);
$event = is_array($data) ? (string)($data['event'] ?? '') : '';

// Whitelist. Without it this table grows a column of whatever a caller feels
// like sending, and the admin panel renders it.
$allowed = ['view', 'click', 'closed', 'closed_esc'];
if (!in_array($event, $allowed, true)) {
    http_response_code(400);
    echo json_encode(['ok' => false]);
    exit;
}

try {
    $db = crm_db();
    $db->prepare('INSERT INTO popup_stats (day, event, n) VALUES (?,?,1)
                  ON CONFLICT(day, event) DO UPDATE SET n = n + 1')
       ->execute([gmdate('Y-m-d'), $event]);
} catch (Throwable $e) {
    // A counter must never be the reason a page reports an error.
    crm_file_log('POPUP-STAT: ' . $e->getMessage());
}

echo json_encode(['ok' => true]);
