<?php
/**
 * FABRIOZA CRM admin - shared auth, security and layout (Phase B).
 *
 * Auth: single admin user. Credentials live ONLY in the VPS .env:
 *   ADMIN_USER       (default: admin)
 *   ADMIN_PASS_HASH  (bcrypt hash - see CRM-HANDOFF.md for the command)
 * Session: HTTP-only, Secure, SameSite=Strict cookie scoped to /admin,
 * 2-hour idle timeout. Login lockout: 5 fails = 15 min per IP.
 */
require __DIR__ . '/../api/db.php';

header('X-Robots-Tag: noindex, nofollow');
header('X-Frame-Options: DENY');
header('Cache-Control: no-store');

function adm_session(): void {
    if (session_status() === PHP_SESSION_ACTIVE) { return; }
    $secure = (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    session_set_cookie_params(['lifetime' => 0, 'path' => '/admin',
        'secure' => $secure, 'httponly' => true, 'samesite' => 'Strict']);
    session_name('FABADMIN');
    session_start();
}

function adm_logged_in(): bool {
    adm_session();
    if (empty($_SESSION['adm_ok'])) { return false; }
    if (time() - ($_SESSION['adm_last'] ?? 0) > 7200) {   // 2h idle timeout
        session_destroy(); return false;
    }
    $_SESSION['adm_last'] = time();
    return true;
}

function adm_require_login(): void {
    if (!adm_logged_in()) {
        http_response_code(401);
        echo '<!DOCTYPE html><meta charset="utf-8"><meta name="robots" content="noindex">'
           . '<title>401</title><body style="font-family:sans-serif;display:grid;place-items:center;height:100vh">'
           . '<div><h2>Authentication required</h2><a href="/admin/login.php" style="color:#4A7C59">Log in &rarr;</a></div>';
        exit;
    }
}

function adm_csrf(): string {
    adm_session();
    if (empty($_SESSION['adm_csrf'])) { $_SESSION['adm_csrf'] = bin2hex(random_bytes(32)); }
    return $_SESSION['adm_csrf'];
}
function adm_csrf_field(): string {
    return '<input type="hidden" name="csrf" value="' . adm_csrf() . '">';
}
function adm_check_csrf(): void {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $t = (string)($_POST['csrf'] ?? '');
        if (empty($_SESSION['adm_csrf']) || !hash_equals($_SESSION['adm_csrf'], $t)) {
            http_response_code(403); exit('Invalid CSRF token. Go back and retry.');
        }
    }
}

function adm_audit(PDO $db, string $action, ?int $leadId): void {
    try {
        $db->prepare('INSERT INTO audit_log (action, lead_id, actor) VALUES (?,?,?)')
           ->execute([$action, $leadId, getenv('ADMIN_USER') ?: 'admin']);
    } catch (Throwable $e) { error_log('audit: ' . $e->getMessage()); }
}

function e($v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }

const ADM_STATUSES = ['new', 'quoted', 'sample', 'trial', 'bulk_negotiation', 'won', 'lost', 'spam'];
const ADM_STATUS_COLORS = [
    'new' => 'bg-blue-100 text-blue-800', 'quoted' => 'bg-amber-100 text-amber-800',
    'sample' => 'bg-purple-100 text-purple-800', 'trial' => 'bg-teal-100 text-teal-800',
    'bulk_negotiation' => 'bg-indigo-100 text-indigo-800', 'won' => 'bg-green-100 text-green-800',
    'lost' => 'bg-gray-200 text-gray-600', 'spam' => 'bg-red-100 text-red-700',
];
function adm_badge(string $status): string {
    $c = ADM_STATUS_COLORS[$status] ?? 'bg-gray-100 text-gray-700';
    return '<span class="px-2 py-0.5 rounded-full text-xs font-semibold ' . $c . '">'
        . e(str_replace('_', ' ', $status)) . '</span>';
}

function adm_head(string $title): void {
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
       . '<meta name="viewport" content="width=device-width,initial-scale=1">'
       . '<meta name="robots" content="noindex,nofollow"><title>' . e($title) . ' | FABRIOZA CRM</title>'
       . '<script src="https://cdn.tailwindcss.com"></script>'
       . '<link rel="icon" href="/images/fabrioza-logo.jpg"></head>'
       . '<body class="bg-stone-100 text-stone-900 min-h-screen">';
    echo '<header class="bg-stone-950 text-white">
      <div class="max-w-6xl mx-auto px-4 py-3 flex items-center justify-between">
        <a href="/admin/" class="flex items-center gap-2 font-bold tracking-wide">
          <img src="/images/fabrioza-logo.jpg" class="w-7 h-7 rounded-full" alt="">FABRIOZA CRM</a>
        <nav class="flex gap-5 text-sm">
          <a class="hover:text-emerald-400" href="/admin/">Dashboard</a>
          <a class="hover:text-emerald-400" href="/admin/leads.php">Leads</a>
          <a class="hover:text-emerald-400" href="/admin/followups.php">Follow-ups</a>
          <a class="hover:text-emerald-400 text-stone-400" href="/admin/logout.php">Log out</a>
        </nav>
      </div></header><main class="max-w-6xl mx-auto px-4 py-6">';
}
function adm_foot(): void { echo '</main></body></html>'; }
