<?php
require __DIR__ . '/_lib.php';
adm_require_login();
$db = crm_db();

$q = fn(string $sql, array $p = []) => (function () use ($db, $sql, $p) {
    $s = $db->prepare($sql); $s->execute($p); return $s;
})();

$week  = (int)$q("SELECT COUNT(*) FROM leads WHERE created_at >= datetime('now','-7 days') AND status != 'spam'")->fetchColumn();
$month = (int)$q("SELECT COUNT(*) FROM leads WHERE created_at >= datetime('now','-30 days') AND status != 'spam'")->fetchColumn();
$byStatus = $q("SELECT status, COUNT(*) n FROM leads GROUP BY status")->fetchAll();
$hot = $q("SELECT id,name,company,lead_score,created_at FROM leads WHERE lead_score >= 35 AND status = 'new' ORDER BY lead_score DESC, id DESC LIMIT 8")->fetchAll();
$overdue = $q("SELECT id,name,company,next_follow_up,status FROM leads
    WHERE next_follow_up IS NOT NULL AND date(next_follow_up) < date('now')
      AND status NOT IN ('won','lost','spam') ORDER BY next_follow_up LIMIT 8")->fetchAll();
$bySource = $q("SELECT COALESCE(NULLIF(form_type,''),'(unknown)') k, COUNT(*) n FROM leads
    WHERE status != 'spam' GROUP BY k ORDER BY n DESC")->fetchAll();
$maxSrc = max(array_column($bySource, 'n') ?: [1]);
$trial = (int)$q("SELECT COUNT(*) FROM leads WHERE form_type = 'Trial Order'")->fetchColumn();
$quote = (int)$q("SELECT COUNT(*) FROM leads WHERE form_type IN ('Quote','Contact Form')")->fetchColumn();

adm_head('Dashboard');
?>
<h1 class="text-2xl font-bold mb-6">Dashboard</h1>

<div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-8">
  <div class="bg-white rounded-2xl shadow p-5"><div class="text-3xl font-extrabold"><?= $week ?></div><div class="text-sm text-stone-500">Leads this week</div></div>
  <div class="bg-white rounded-2xl shadow p-5"><div class="text-3xl font-extrabold"><?= $month ?></div><div class="text-sm text-stone-500">Leads this month</div></div>
  <div class="bg-white rounded-2xl shadow p-5"><div class="text-3xl font-extrabold text-teal-700"><?= $trial ?></div><div class="text-sm text-stone-500">Trial Order leads</div></div>
  <div class="bg-white rounded-2xl shadow p-5"><div class="text-3xl font-extrabold text-amber-700"><?= $quote ?></div><div class="text-sm text-stone-500">Quote leads</div></div>
</div>

<div class="grid md:grid-cols-2 gap-6 mb-8">
  <div class="bg-white rounded-2xl shadow p-6">
    <h2 class="font-bold mb-4">Pipeline</h2>
    <div class="flex flex-wrap gap-2">
      <?php foreach (ADM_STATUSES as $st):
        $n = 0; foreach ($byStatus as $r) { if ($r['status'] === $st) { $n = $r['n']; } } ?>
        <a href="/admin/leads.php?status=<?= $st ?>" class="flex items-center gap-2 border rounded-xl px-3 py-2 hover:bg-stone-50">
          <?= adm_badge($st) ?><span class="font-bold"><?= $n ?></span></a>
      <?php endforeach; ?>
    </div>
  </div>
  <div class="bg-white rounded-2xl shadow p-6">
    <h2 class="font-bold mb-4">Leads by source</h2>
    <?php foreach ($bySource as $r): ?>
      <div class="flex items-center gap-3 mb-2 text-sm">
        <span class="w-36 truncate"><?= e($r['k']) ?></span>
        <span class="flex-1 bg-stone-100 rounded h-4 overflow-hidden">
          <span class="block h-4 bg-emerald-600" style="width:<?= round($r['n'] / $maxSrc * 100) ?>%"></span></span>
        <span class="w-8 text-right font-semibold"><?= $r['n'] ?></span>
      </div>
    <?php endforeach; if (!$bySource) { echo '<p class="text-sm text-stone-400">No leads yet.</p>'; } ?>
  </div>
</div>

<div class="grid md:grid-cols-2 gap-6">
  <div class="bg-white rounded-2xl shadow p-6">
    <h2 class="font-bold mb-4">High-score leads needing attention <span class="text-xs font-normal text-stone-400">(score &ge; 35, status new)</span></h2>
    <?php foreach ($hot as $r): ?>
      <a href="/admin/lead.php?id=<?= $r['id'] ?>" class="flex justify-between items-center py-2 border-b last:border-0 hover:bg-emerald-50 rounded px-2">
        <span><strong><?= e($r['name']) ?></strong> <span class="text-stone-500 text-sm"><?= e($r['company']) ?></span></span>
        <span class="text-emerald-700 font-extrabold"><?= $r['lead_score'] ?></span>
      </a>
    <?php endforeach; if (!$hot) { echo '<p class="text-sm text-stone-400">None right now.</p>'; } ?>
  </div>
  <div class="bg-white rounded-2xl shadow p-6">
    <h2 class="font-bold mb-4 text-red-700">Overdue follow-ups</h2>
    <?php foreach ($overdue as $r): ?>
      <a href="/admin/lead.php?id=<?= $r['id'] ?>" class="flex justify-between items-center py-2 border-b last:border-0 hover:bg-red-50 rounded px-2">
        <span><strong><?= e($r['name']) ?></strong> <span class="text-stone-500 text-sm"><?= e($r['company']) ?></span></span>
        <span class="text-red-600 text-sm font-semibold"><?= e($r['next_follow_up']) ?></span>
      </a>
    <?php endforeach; if (!$overdue) { echo '<p class="text-sm text-stone-400">Nothing overdue. Nice.</p>'; } ?>
  </div>
</div>
<?php adm_foot();
