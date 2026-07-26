<?php
/**
 * Issues the CSRF token for the public forms. Same-origin pages fetch this
 * before POSTing to send-email.php; the token is bound to the PHP session
 * (HTTP-only cookie), so a cross-site attacker can neither read it nor
 * forge a matching session.
 */
require __DIR__ . '/db.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

crm_session_start();
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}
echo json_encode(['token' => $_SESSION['csrf']]);
