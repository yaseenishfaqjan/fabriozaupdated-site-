<?php
require __DIR__ . '/_lib.php';
adm_require_login();
$db = crm_db();
$id = (int)($_GET['id'] ?? $_POST['lead_id'] ?? 0);

/* ---- POST actions ---- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    adm_check_csrf();
    $act = $_POST['action'] ?? '';
    if ($act === 'delete') {                       // GDPR right to erasure: hard delete
        $db->prepare('DELETE FROM leads WHERE id = ?')->execute([$id]);
        adm_audit($db, 'lead_deleted', $id);       // no PII in the audit log
        header('Location: /admin/leads.php'); exit;
    }
    if ($act === 'update') {
        $st = in_array($_POST['status'] ?? '', ADM_STATUSES, true) ? $_POST['status'] : 'new';
        $fu = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['next_follow_up'] ?? '') ? $_POST['next_follow_up'] : null;
        $as = mb_substr(trim((string)($_POST['assigned_to'] ?? '')), 0, 80);
        $old = $db->prepare('SELECT status FROM leads WHERE id = ?');
        $old->execute([$id]); $oldSt = $old->fetchColumn();
        $db->prepare('UPDATE leads SET status = ?, next_follow_up = ?, assigned_to = ? WHERE id = ?')
           ->execute([$st, $fu, $as !== '' ? $as : null, $id]);
        if ($oldSt !== $st) { adm_audit($db, "status_changed:$st", $id); }
    }
    if ($act === 'log_reply') {
        // lead replied to an auto-mail: promote to quoted + stop sequences
        $db->prepare("UPDATE leads SET status = 'quoted', sequences_paused = 1 WHERE id = ?")->execute([$id]);
        $db->prepare('INSERT INTO notes (lead_id, author, body) VALUES (?,?,?)')
           ->execute([$id, getenv('ADMIN_USER') ?: 'admin', 'Reply received - moved to quoted, sequences paused']);
        adm_audit($db, 'reply_received', $id);
    }
    if ($act === 'note' && trim((string)($_POST['body'] ?? '')) !== '') {
        $db->prepare('INSERT INTO notes (lead_id, author, body) VALUES (?,?,?)')
           ->execute([$id, getenv('ADMIN_USER') ?: 'admin', mb_substr(trim($_POST['body']), 0, 4000)]);
        adm_audit($db, 'note_added', $id);
    }
    header("Location: /admin/lead.php?id=$id"); exit;
}

$st = $db->prepare('SELECT * FROM leads WHERE id = ?'); $st->execute([$id]);
$L = $st->fetch();
if (!$L) { http_response_code(404); adm_head('Not found'); echo '<p>Lead not found. <a class="text-emerald-700" href="/admin/leads.php">Back</a></p>'; adm_foot(); exit; }
$notes = $db->prepare('SELECT * FROM notes WHERE lead_id = ? ORDER BY created_at DESC'); $notes->execute([$id]); $notes = $notes->fetchAll();
$emails = $db->prepare('SELECT * FROM email_log WHERE lead_id = ? ORDER BY sent_at DESC'); $emails->execute([$id]); $emails = $emails->fetchAll();

$PIPE = ['new' => 'New', 'quoted' => 'Quoted', 'sample' => 'Sample', 'trial' => 'Trial', 'bulk_negotiation' => 'Bulk', 'won' => 'Won'];
$stageIdx = array_search($L['status'], array_keys($PIPE), true);

adm_head('Lead #' . $L['id']);
?>
<div class="flex items-center justify-between mb-5">
  <h1 class="text-2xl font-bold">#<?= $L['id'] ?> &middot; <?= e($L['name']) ?> <?= adm_badge($L['status']) ?>
    <?php if ($L['lead_score'] >= 35): ?><span class="text-emerald-700 text-base font-extrabold ml-2">score <?= $L['lead_score'] ?> &#9733;</span><?php endif; ?></h1>
  <a class="text-sm text-stone-500 hover:underline" href="/admin/leads.php">&larr; All leads</a>
</div>

<!-- pipeline indicator -->
<div class="flex items-center gap-1 mb-6 overflow-x-auto">
  <?php $i = 0; foreach ($PIPE as $key => $label):
      $done = $stageIdx !== false && $i <= $stageIdx; ?>
    <div class="flex items-center gap-1">
      <div class="px-3 py-1.5 rounded-full text-xs font-bold <?= $done ? 'bg-emerald-700 text-white' : 'bg-stone-200 text-stone-500' ?>"><?= $label ?></div>
      <?php if ($key !== 'won'): ?><div class="w-5 h-0.5 <?= $done ? 'bg-emerald-700' : 'bg-stone-300' ?>"></div><?php endif; ?>
    </div>
  <?php $i++; endforeach; ?>
  <?php if ($L['status'] === 'lost'): ?><div class="ml-2"><?= adm_badge('lost') ?></div><?php endif; ?>
</div>

<div class="grid md:grid-cols-3 gap-6">
  <div class="md:col-span-2 space-y-6">
    <div class="bg-white rounded-2xl shadow p-6">
      <h2 class="font-bold mb-4">Lead details</h2>
      <dl class="grid grid-cols-2 gap-x-6 gap-y-2 text-sm">
        <?php foreach (['email' => 'Email', 'company' => 'Company', 'country' => 'Country',
            'product_type' => 'Product', 'quantity' => 'Quantity', 'form_type' => 'Form',
            'source_page' => 'Page', 'utm_source' => 'UTM source', 'utm_medium' => 'UTM medium',
            'utm_campaign' => 'UTM campaign', 'created_at' => 'Created', 'gdpr_consent_ts' => 'Consent at'] as $k => $lab):
            if ((string)$L[$k] === '') { continue; } ?>
          <dt class="text-stone-400"><?= $lab ?></dt><dd class="font-medium break-all"><?= e($L[$k]) ?></dd>
        <?php endforeach; ?>
      </dl>
      <?php if ($L['message']): ?>
        <div class="mt-4 p-4 bg-stone-50 rounded-xl text-sm whitespace-pre-wrap"><?= e($L['message']) ?></div>
      <?php endif; ?>
      <div class="mt-4 flex flex-wrap gap-2 items-center">
        <a class="inline-block text-sm bg-emerald-700 text-white px-4 py-2 rounded-lg hover:bg-emerald-800"
           href="mailto:<?= e($L['email']) ?>?subject=Re: your FABRIOZA enquiry">Reply by email</a>
        <form method="post" class="inline"><?= adm_csrf_field() ?>
          <input type="hidden" name="action" value="log_reply"><input type="hidden" name="lead_id" value="<?= $id ?>">
          <button class="text-sm border border-emerald-700 text-emerald-800 px-4 py-2 rounded-lg hover:bg-emerald-50">Log reply received</button>
        </form>
        <?php if (!empty($L['sequences_paused'])): ?>
          <span class="text-xs bg-stone-200 text-stone-600 px-2 py-1 rounded-full font-semibold">auto follow-ups paused</span>
        <?php endif; ?>
      </div>
    </div>

    <div class="bg-white rounded-2xl shadow p-6">
      <h2 class="font-bold mb-4">Notes</h2>
      <form method="post" class="flex gap-2 mb-4">
        <?= adm_csrf_field() ?><input type="hidden" name="action" value="note"><input type="hidden" name="lead_id" value="<?= $id ?>">
        <input name="body" required placeholder="Add a note..." class="flex-1 border rounded-lg px-3 py-2 text-sm">
        <button class="bg-stone-900 text-white px-4 rounded-lg text-sm">Add</button>
      </form>
      <?php foreach ($notes as $n): ?>
        <div class="border-t py-3 text-sm"><span class="text-stone-400"><?= e($n['created_at']) ?> &middot; <?= e($n['author']) ?></span>
          <div class="mt-1"><?= e($n['body']) ?></div></div>
      <?php endforeach; if (!$notes) { echo '<p class="text-sm text-stone-400">No notes yet.</p>'; } ?>
    </div>

    <div class="bg-white rounded-2xl shadow p-6">
      <h2 class="font-bold mb-4">Email history</h2>
      <?php foreach ($emails as $m): ?>
        <div class="border-t py-2 text-sm flex justify-between gap-3">
          <span class="truncate"><?= e($m['subject']) ?> <span class="text-stone-400">&rarr; <?= e($m['recipient']) ?></span></span>
          <span class="<?= $m['status'] === 'sent' ? 'text-emerald-700' : 'text-red-600' ?> font-semibold"><?= e($m['status']) ?></span>
        </div>
        <?php if ($m['error']): ?><div class="text-xs text-red-500 pb-1"><?= e($m['error']) ?></div><?php endif; ?>
      <?php endforeach; if (!$emails) { echo '<p class="text-sm text-stone-400">No emails logged.</p>'; } ?>
    </div>
  </div>

  <div class="space-y-6">
    <form method="post" class="bg-white rounded-2xl shadow p-6 space-y-4 text-sm">
      <?= adm_csrf_field() ?><input type="hidden" name="action" value="update"><input type="hidden" name="lead_id" value="<?= $id ?>">
      <h2 class="font-bold">Manage</h2>
      <label class="block font-semibold">Status
        <select name="status" class="mt-1 w-full border rounded-lg px-2 py-2">
          <?php foreach (ADM_STATUSES as $s2): ?><option <?= $L['status'] === $s2 ? 'selected' : '' ?>><?= $s2 ?></option><?php endforeach; ?>
        </select></label>
      <label class="block font-semibold">Next follow-up
        <input type="date" name="next_follow_up" value="<?= e($L['next_follow_up']) ?>" class="mt-1 w-full border rounded-lg px-2 py-2"></label>
      <label class="block font-semibold">Assigned to
        <input name="assigned_to" value="<?= e($L['assigned_to']) ?>" placeholder="TODO(fabrioza): team member" class="mt-1 w-full border rounded-lg px-2 py-2"></label>
      <button class="w-full bg-emerald-700 text-white font-bold rounded-lg py-2.5 hover:bg-emerald-800">Save</button>
    </form>

    <form method="post" class="bg-white rounded-2xl shadow p-6"
          onsubmit="return confirm('Permanently delete this lead and all its notes? This is the GDPR erasure action and cannot be undone.')">
      <?= adm_csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="lead_id" value="<?= $id ?>">
      <h2 class="font-bold mb-2 text-red-700">Danger zone</h2>
      <p class="text-xs text-stone-500 mb-3">Hard-deletes the lead + notes (right to erasure). Logged to the audit trail without PII.</p>
      <button class="w-full border border-red-300 text-red-700 font-bold rounded-lg py-2 hover:bg-red-50">Delete lead</button>
    </form>
  </div>
</div>
<?php adm_foot();
