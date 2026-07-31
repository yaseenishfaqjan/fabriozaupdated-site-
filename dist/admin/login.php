<?php
require __DIR__ . '/_lib.php';
adm_session();
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    adm_check_csrf();
    $db = crm_db();
    $ipKey = 'adm:' . crm_ip_hash();
    if (!crm_rate_limit_ok($db, $ipKey, 5, 900)) {          // 5 fails / 15 min
        $err = 'Too many attempts. Locked for 15 minutes.';
    } else {
        $user = (string)($_POST['user'] ?? '');
        $pass = (string)($_POST['pass'] ?? '');
        $wantUser = getenv('ADMIN_USER') ?: 'admin';
        $hash = getenv('ADMIN_PASS_HASH') ?: '';
        if ($hash === '') {
            $err = 'Admin password not configured. Set ADMIN_PASS_HASH in the VPS .env (see CRM-HANDOFF.md).';
        } elseif (hash_equals($wantUser, $user) && password_verify($pass, $hash)) {
            session_regenerate_id(true);
            $_SESSION['adm_ok'] = true;
            $_SESSION['adm_last'] = time();
            // clear the lockout counter on success
            $db->prepare('DELETE FROM rate_limits WHERE ip_hash = ?')->execute([$ipKey]);
            adm_audit($db, 'login', null);
            header('Location: /admin/'); exit;
        } else {
            $err = 'Wrong username or password.';
        }
    }
}
adm_head('Login');
?>
<div class="max-w-sm mx-auto mt-16 bg-white rounded-2xl shadow p-8">
  <h1 class="text-xl font-bold mb-6">Admin login</h1>
  <?php if ($err): ?><p class="mb-4 text-sm text-red-600 font-medium"><?= e($err) ?></p><?php endif; ?>
  <form method="post" class="space-y-4">
    <?= adm_csrf_field() ?>
    <label class="block text-sm font-semibold">Username
      <input name="user" required class="mt-1 w-full border rounded-lg px-3 py-2" autocomplete="username">
    </label>
    <label class="block text-sm font-semibold">Password
      <input name="pass" type="password" required class="mt-1 w-full border rounded-lg px-3 py-2" autocomplete="current-password">
    </label>
    <button class="w-full bg-emerald-700 hover:bg-emerald-800 text-white font-bold rounded-lg py-2.5">Log in</button>
  </form>
</div>
<?php adm_foot();
