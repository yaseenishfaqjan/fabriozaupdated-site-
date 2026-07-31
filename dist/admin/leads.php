<?php
require __DIR__ . '/_lib.php';
adm_require_login();
$db = crm_db();

/* ---- inline status change (POST) ---- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['set_status'], $_POST['lead_id'])) {
    adm_check_csrf();
    $id = (int)$_POST['lead_id'];
    $st = in_array($_POST['set_status'], ADM_STATUSES, true) ? $_POST['set_status'] : 'new';
    $db->prepare('UPDATE leads SET status = ? WHERE id = ?')->execute([$st, $id]);
    adm_audit($db, "status_changed:$st", $id);
    header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? '/admin/leads.php')); exit;
}

/* ---- filters ---- */
$where = ["1=1"]; $p = [];
$f = fn($k) => trim((string)($_GET[$k] ?? ''));
if ($f('status') !== '' && in_array($f('status'), ADM_STATUSES, true)) { $where[] = 'status = ?'; $p[] = $f('status'); }
if ($f('form_type') !== '') { $where[] = 'form_type = ?'; $p[] = $f('form_type'); }
if ($f('country') !== '')   { $where[] = 'country LIKE ?'; $p[] = '%' . $f('country') . '%'; }
if ($f('assigned') !== '')  { $where[] = 'assigned_to LIKE ?'; $p[] = '%' . $f('assigned') . '%'; }
if ($f('from') !== '')      { $where[] = 'date(created_at) >= date(?)'; $p[] = $f('from'); }
if ($f('to') !== '')        { $where[] = 'date(created_at) <= date(?)'; $p[] = $f('to'); }
if ($f('q') !== '') {
    $where[] = '(name LIKE ? OR email LIKE ? OR company LIKE ?)';
    $like = '%' . $f('q') . '%'; array_push($p, $like, $like, $like);
}
$sortMap = ['date' => 'created_at', 'name' => 'name', 'company' => 'company',
            'score' => 'lead_score', 'status' => 'status', 'followup' => 'next_follow_up'];
$sort = $sortMap[$f('sort')] ?? 'created_at';
$dir  = $f('dir') === 'asc' ? 'ASC' : 'DESC';
$W = implode(' AND ', $where);

/* ---- CSV export (current filters, no pagination) ---- */
if (isset($_GET['export'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="fabrioza-leads-' . date('Ymd') . '.csv"');
    $out = fopen('php://output', 'w');
    $cols = ['id','created_at','name','email','company','country','product_type','quantity',
             'form_type','source_page','utm_source','utm_medium','utm_campaign','lead_score',
             'status','assigned_to','next_follow_up','message'];
    fputcsv($out, $cols);
    $st = $db->prepare("SELECT " . implode(',', $cols) . " FROM leads WHERE $W ORDER BY $sort $dir");
    $st->execute($p);
    while ($r = $st->fetch()) { fputcsv($out, $r); }
    exit;
}

/* ---- pagination ---- */
$page = max(1, (int)($_GET['page'] ?? 1));
$per = 25;
$total = (int)(function () use ($db, $W, $p) { $s = $db->prepare("SELECT COUNT(*) FROM leads WHERE $W"); $s->execute($p); return $s->fetchColumn(); })();
$pages = max(1, (int)ceil($total / $per));
$st = $db->prepare("SELECT * FROM leads WHERE $W ORDER BY $sort $dir LIMIT $per OFFSET " . (($page - 1) * $per));
$st->execute($p);
$rows = $st->fetchAll();
/* stale = new for 7+ days with no notes and no status change (visual flag only) */
$staleIds = array_column($db->query("SELECT id FROM leads l WHERE status='new'
    AND created_at <= datetime('now','-7 days')
    AND NOT EXISTS (SELECT 1 FROM notes n WHERE n.lead_id = l.id)
    AND NOT EXISTS (SELECT 1 FROM audit_log a WHERE a.lead_id = l.id AND a.action LIKE 'status_changed%')")->fetchAll(), 'id');
$formTypes = $db->query("SELECT DISTINCT form_type FROM leads WHERE form_type != '' ORDER BY 1")->fetchAll(PDO::FETCH_COLUMN);

function sortlink(string $key, string $label): string {
    $q = $_GET; $q['sort'] = $key;
    $q['dir'] = (($q['dir'] ?? '') === 'asc' || ($_GET['sort'] ?? '') !== $key) ? (($_GET['sort'] ?? '') === $key ? 'desc' : 'asc') : 'asc';
    $q['dir'] = (($_GET['sort'] ?? '') === $key && ($_GET['dir'] ?? 'desc') !== 'asc') ? 'asc' : 'desc';
    return '<a class="hover:underline" href="?' . e(http_build_query($q)) . '">' . $label . '</a>';
}
adm_head('Leads');
?>
<div class="flex items-center justify-between mb-4">
  <h1 class="text-2xl font-bold">Leads <span class="text-stone-400 text-lg">(<?= $total ?>)</span></h1>
  <a class="bg-stone-900 text-white text-sm px-4 py-2 rounded-lg hover:bg-stone-700"
     href="?<?= e(http_build_query(array_merge($_GET, ['export' => 1]))) ?>">Export CSV</a>
</div>

<form class="bg-white rounded-2xl shadow p-4 mb-5 grid grid-cols-2 md:grid-cols-7 gap-3 text-sm">
  <input name="q" value="<?= e($f('q')) ?>" placeholder="Search name/email/company" class="border rounded-lg px-3 py-2 col-span-2">
  <select name="status" class="border rounded-lg px-2 py-2"><option value="">Any status</option>
    <?php foreach (ADM_STATUSES as $s2): ?><option <?= $f('status') === $s2 ? 'selected' : '' ?>><?= $s2 ?></option><?php endforeach; ?></select>
  <select name="form_type" class="border rounded-lg px-2 py-2"><option value="">Any form</option>
    <?php foreach ($formTypes as $ft): ?><option <?= $f('form_type') === $ft ? 'selected' : '' ?>><?= e($ft) ?></option><?php endforeach; ?></select>
  <input name="from" type="date" value="<?= e($f('from')) ?>" class="border rounded-lg px-2 py-2">
  <input name="to" type="date" value="<?= e($f('to')) ?>" class="border rounded-lg px-2 py-2">
  <button class="bg-emerald-700 text-white rounded-lg font-semibold">Filter</button>
</form>

<div class="bg-white rounded-2xl shadow overflow-x-auto">
<table class="w-full text-sm">
  <thead class="bg-stone-50 text-left text-stone-500">
    <tr>
      <th class="p-3"><?= sortlink('date', 'Date') ?></th>
      <th class="p-3"><?= sortlink('name', 'Name') ?></th>
      <th class="p-3"><?= sortlink('company', 'Company') ?></th>
      <th class="p-3">Form</th>
      <th class="p-3"><?= sortlink('score', 'Score') ?></th>
      <th class="p-3"><?= sortlink('status', 'Status') ?></th>
      <th class="p-3"><?= sortlink('followup', 'Follow-up') ?></th>
      <th class="p-3"></th>
    </tr>
  </thead>
  <tbody>
  <?php foreach ($rows as $r):
      $hot = $r['lead_score'] >= 35;
      $over = $r['next_follow_up'] && $r['next_follow_up'] < date('Y-m-d') && !in_array($r['status'], ['won','lost','spam']);
  ?>
    <tr class="border-t <?= $hot ? 'bg-emerald-50/60' : '' ?>">
      <td class="p-3 whitespace-nowrap text-stone-500"><?= e(substr($r['created_at'], 0, 10)) ?></td>
      <td class="p-3 <?= $r['status'] === 'new' ? 'font-bold' : '' ?>">
        <a class="hover:underline" href="/admin/lead.php?id=<?= $r['id'] ?>"><?= e($r['name']) ?></a>
        <?php if (in_array($r['id'], $staleIds)): ?><span class="ml-1 text-[10px] bg-orange-100 text-orange-700 px-1.5 py-0.5 rounded-full font-bold uppercase">needs attention</span><?php endif; ?>
        <div class="text-xs text-stone-400"><?= e($r['email']) ?></div></td>
      <td class="p-3"><?= e($r['company']) ?></td>
      <td class="p-3 text-xs"><?= e($r['form_type']) ?></td>
      <td class="p-3 font-extrabold <?= $hot ? 'text-emerald-700' : 'text-stone-400' ?>"><?= $r['lead_score'] ?></td>
      <td class="p-3"><?= adm_badge($r['status']) ?></td>
      <td class="p-3 <?= $over ? 'text-red-600 font-bold' : 'text-stone-500' ?>"><?= e($r['next_follow_up']) ?></td>
      <td class="p-3">
        <form method="post" class="flex gap-1">
          <?= adm_csrf_field() ?><input type="hidden" name="lead_id" value="<?= $r['id'] ?>">
          <select name="set_status" class="border rounded px-1 py-0.5 text-xs">
            <?php foreach (ADM_STATUSES as $s2): ?><option <?= $r['status'] === $s2 ? 'selected' : '' ?>><?= $s2 ?></option><?php endforeach; ?>
          </select><button class="text-xs bg-stone-200 rounded px-2 hover:bg-stone-300">Set</button>
        </form></td>
    </tr>
  <?php endforeach; if (!$rows) { echo '<tr><td class="p-6 text-stone-400" colspan="8">No leads match.</td></tr>'; } ?>
  </tbody>
</table>
</div>

<?php if ($pages > 1): ?>
<div class="flex gap-2 mt-4">
  <?php for ($i = 1; $i <= $pages; $i++): $q2 = $_GET; $q2['page'] = $i; ?>
    <a href="?<?= e(http_build_query($q2)) ?>" class="px-3 py-1 rounded <?= $i === $page ? 'bg-stone-900 text-white' : 'bg-white shadow' ?>"><?= $i ?></a>
  <?php endfor; ?>
</div>
<?php endif;
adm_foot();
