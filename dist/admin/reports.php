<?php
require __DIR__ . '/_lib.php';
adm_require_login();
$db = crm_db();

$period = ($_GET['period'] ?? 'week') === 'month' ? 'month' : 'week';
$fmt = $period === 'week' ? '%Y-W%W' : '%Y-%m';
$span = $period === 'week' ? '-12 weeks' : '-12 months';

/* leads by source per period */
$bySrc = $db->prepare("SELECT strftime(?, created_at) bucket,
        COALESCE(NULLIF(form_type,''),'(unknown)') src, COUNT(*) n
    FROM leads WHERE status != 'spam' AND created_at >= datetime('now', ?)
    GROUP BY bucket, src ORDER BY bucket DESC");
$bySrc->execute([$fmt, $span]);
$srcRows = $bySrc->fetchAll();
$buckets = []; $sources = [];
foreach ($srcRows as $r) { $buckets[$r['bucket']][$r['src']] = $r['n']; $sources[$r['src']] = true; }
$sources = array_keys($sources);
$maxCell = max(array_map(fn($b) => array_sum($b), $buckets) ?: [1]);

/* funnel */
$funnel = [];
foreach (ADM_STATUSES as $s) {
    $st = $db->prepare('SELECT COUNT(*) FROM leads WHERE status = ?');
    $st->execute([$s]); $funnel[$s] = (int)$st->fetchColumn();
}
$maxF = max(array_values($funnel) ?: [1]);

/* trial-to-bulk conversion: Trial Order leads that reached bulk_negotiation or won */
$trialTotal = (int)$db->query("SELECT COUNT(*) FROM leads WHERE form_type = 'Trial Order'")->fetchColumn();
$trialConv = (int)$db->query("SELECT COUNT(*) FROM leads WHERE form_type = 'Trial Order'
    AND (status IN ('bulk_negotiation','won')
         OR id IN (SELECT lead_id FROM audit_log WHERE action IN ('status_changed:bulk_negotiation','status_changed:won')))")->fetchColumn();
$trialRate = $trialTotal ? round($trialConv / $trialTotal * 100) : null;

/* avg sales cycle: created_at -> first status_changed:won audit entry */
$cycle = $db->query("SELECT AVG(julianday(a.created_at) - julianday(l.created_at)) d
    FROM audit_log a JOIN leads l ON l.id = a.lead_id
    WHERE a.action = 'status_changed:won'")->fetchColumn();
$cycleDays = $cycle !== null && $cycle !== false && $cycle !== '' ? round((float)$cycle, 1) : null;

/* top countries */
$countries = $db->query("SELECT COALESCE(NULLIF(country,''),'(not given)') c, COUNT(*) n
    FROM leads WHERE status != 'spam' GROUP BY c ORDER BY n DESC LIMIT 10")->fetchAll();
$maxC = max(array_column($countries, 'n') ?: [1]);

adm_head('Reports');
?>
<div class="flex items-center justify-between mb-6">
  <h1 class="text-2xl font-bold">Reports</h1>
  <div class="text-sm bg-white rounded-lg shadow px-1 py-1 flex gap-1">
    <a href="?period=week" class="px-3 py-1 rounded <?= $period === 'week' ? 'bg-stone-900 text-white' : '' ?>">Weekly</a>
    <a href="?period=month" class="px-3 py-1 rounded <?= $period === 'month' ? 'bg-stone-900 text-white' : '' ?>">Monthly</a>
  </div>
</div>

<div class="grid grid-cols-2 md:grid-cols-3 gap-4 mb-8">
  <div class="bg-white rounded-2xl shadow p-5">
    <div class="text-3xl font-extrabold"><?= $trialRate === null ? '&mdash;' : $trialRate . '%' ?></div>
    <div class="text-sm text-stone-500">Trial &rarr; bulk conversion (<?= $trialConv ?>/<?= $trialTotal ?>)</div></div>
  <div class="bg-white rounded-2xl shadow p-5">
    <div class="text-3xl font-extrabold"><?= $cycleDays === null ? '&mdash;' : $cycleDays ?></div>
    <div class="text-sm text-stone-500">Avg days new &rarr; won</div></div>
  <div class="bg-white rounded-2xl shadow p-5">
    <div class="text-3xl font-extrabold"><?= $funnel['won'] ?></div>
    <div class="text-sm text-stone-500">Total won</div></div>
</div>

<div class="bg-white rounded-2xl shadow p-6 mb-8">
  <h2 class="font-bold mb-4">Conversion funnel</h2>
  <?php foreach (['new','quoted','sample','trial','bulk_negotiation','won'] as $s): ?>
    <div class="flex items-center gap-3 mb-2 text-sm">
      <span class="w-36"><?= adm_badge($s) ?></span>
      <span class="flex-1 bg-stone-100 rounded h-5 overflow-hidden">
        <span class="block h-5 bg-emerald-600" style="width:<?= round($funnel[$s] / $maxF * 100) ?>%"></span></span>
      <span class="w-10 text-right font-bold"><?= $funnel[$s] ?></span>
    </div>
  <?php endforeach; ?>
  <p class="text-xs text-stone-400 mt-2">lost: <?= $funnel['lost'] ?> &middot; spam: <?= $funnel['spam'] ?></p>
</div>

<div class="grid md:grid-cols-2 gap-6">
  <div class="bg-white rounded-2xl shadow p-6 overflow-x-auto">
    <h2 class="font-bold mb-4">Leads by source per <?= $period ?></h2>
    <table class="w-full text-sm">
      <thead><tr class="text-left text-stone-500"><th class="py-1 pr-3"><?= ucfirst($period) ?></th>
        <?php foreach ($sources as $s): ?><th class="py-1 pr-3"><?= e($s) ?></th><?php endforeach; ?>
        <th>Total</th></tr></thead>
      <tbody>
      <?php foreach ($buckets as $b => $row): $tot = array_sum($row); ?>
        <tr class="border-t"><td class="py-1.5 pr-3 font-semibold whitespace-nowrap"><?= e($b) ?></td>
          <?php foreach ($sources as $s): ?><td class="py-1.5 pr-3"><?= $row[$s] ?? 0 ?></td><?php endforeach; ?>
          <td class="font-bold"><span class="inline-block bg-emerald-600 h-3 align-middle mr-1 rounded-sm" style="width:<?= round($tot / $maxCell * 60) ?>px"></span><?= $tot ?></td></tr>
      <?php endforeach; if (!$buckets) { echo '<tr><td class="py-3 text-stone-400" colspan="9">No data yet.</td></tr>'; } ?>
      </tbody>
    </table>
  </div>
  <div class="bg-white rounded-2xl shadow p-6">
    <h2 class="font-bold mb-4">Top countries</h2>
    <?php foreach ($countries as $r): ?>
      <div class="flex items-center gap-3 mb-2 text-sm">
        <span class="w-32 truncate"><?= e($r['c']) ?></span>
        <span class="flex-1 bg-stone-100 rounded h-4 overflow-hidden">
          <span class="block h-4 bg-emerald-600" style="width:<?= round($r['n'] / $maxC * 100) ?>%"></span></span>
        <span class="w-8 text-right font-semibold"><?= $r['n'] ?></span>
      </div>
    <?php endforeach; if (!$countries) { echo '<p class="text-sm text-stone-400">No data yet.</p>'; } ?>
  </div>
</div>
<?php adm_foot();
