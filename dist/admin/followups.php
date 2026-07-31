<?php
require __DIR__ . '/_lib.php';
adm_require_login();
$db = crm_db();

/* "Mark contacted": clears the follow-up date and stores the note */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'contacted') {
    adm_check_csrf();
    $id = (int)$_POST['lead_id'];
    $db->prepare('UPDATE leads SET next_follow_up = NULL WHERE id = ?')->execute([$id]);
    $note = trim((string)($_POST['note'] ?? ''));
    $db->prepare('INSERT INTO notes (lead_id, author, body) VALUES (?,?,?)')
       ->execute([$id, getenv('ADMIN_USER') ?: 'admin', $note !== '' ? mb_substr($note, 0, 4000) : 'Contacted (follow-up cleared)']);
    adm_audit($db, 'marked_contacted', $id);
    header('Location: /admin/followups.php'); exit;
}

$rows = $db->query("SELECT * FROM leads
    WHERE next_follow_up IS NOT NULL AND date(next_follow_up) <= date('now','+1 day')
      AND status NOT IN ('won','lost','spam')
    ORDER BY next_follow_up ASC, lead_score DESC")->fetchAll();

adm_head('Follow-ups');
?>
<h1 class="text-2xl font-bold mb-5">Follow-ups due</h1>
<div class="space-y-3">
<?php foreach ($rows as $r):
    $overdue = $r['next_follow_up'] < date('Y-m-d');
    $today = $r['next_follow_up'] === date('Y-m-d'); ?>
  <div class="bg-white rounded-2xl shadow p-4 flex flex-wrap items-center gap-4 <?= $overdue ? 'border-l-4 border-red-500' : ($today ? 'border-l-4 border-amber-400' : '') ?>">
    <div class="flex-1 min-w-[220px]">
      <a class="font-bold hover:underline" href="/admin/lead.php?id=<?= $r['id'] ?>">#<?= $r['id'] ?> <?= e($r['name']) ?></a>
      <span class="text-stone-500 text-sm"><?= e($r['company']) ?></span>
      <div class="text-xs text-stone-400"><?= e($r['email']) ?> &middot; <?= adm_badge($r['status']) ?> &middot; score <?= $r['lead_score'] ?></div>
    </div>
    <div class="text-sm font-bold <?= $overdue ? 'text-red-600' : ($today ? 'text-amber-600' : 'text-stone-500') ?>">
      <?= $overdue ? 'OVERDUE ' : ($today ? 'Today ' : 'Tomorrow ') ?><?= e($r['next_follow_up']) ?></div>
    <form method="post" class="flex gap-2">
      <?= adm_csrf_field() ?><input type="hidden" name="action" value="contacted"><input type="hidden" name="lead_id" value="<?= $r['id'] ?>">
      <input name="note" placeholder="Optional note..." class="border rounded-lg px-2 py-1 text-sm">
      <button class="bg-emerald-700 text-white text-sm px-3 py-1.5 rounded-lg hover:bg-emerald-800">Mark contacted</button>
    </form>
  </div>
<?php endforeach; if (!$rows) { echo '<p class="text-stone-400">Nothing due today or tomorrow. All caught up.</p>'; } ?>
</div>
<?php adm_foot();
