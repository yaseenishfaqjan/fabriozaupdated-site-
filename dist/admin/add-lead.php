<?php
require __DIR__ . '/_lib.php';
adm_require_login();
$db = crm_db();
$ok = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    adm_check_csrf();
    $g = fn($k, $max = 190) => mb_substr(trim((string)($_POST[$k] ?? '')), 0, $max);
    $name = $g('name', 120); $email = $g('email');
    if ($name !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $payload = ['name' => $name, 'email' => $email, 'company' => $g('company'),
            'quantity' => $g('quantity'), 'form_type' => $g('form_type', 60) ?: 'LinkedIn',
            'utm_medium' => 'manual'];
        $score = crm_lead_score($payload);
        $db->prepare('INSERT INTO leads (name,email,company,country,product_type,quantity,message,
                form_type,source_page,lead_score,status,assigned_to,gdpr_consent,gdpr_consent_ts,ip_hash)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,0,NULL,?)')
           ->execute([$name, $email, $g('company'), $g('country', 80), $g('product_type'),
               $g('quantity'), $g('message', 5000), $payload['form_type'], 'manual-entry',
               $score, 'new', getenv('ADMIN_USER') ?: 'admin', '']);
        $id = (int)$db->lastInsertId();
        if ($g('note', 4000) !== '') {
            $db->prepare('INSERT INTO notes (lead_id, author, body) VALUES (?,?,?)')
               ->execute([$id, getenv('ADMIN_USER') ?: 'admin', $g('note', 4000)]);
        }
        adm_audit($db, 'lead_added_manually', $id);
        header("Location: /admin/lead.php?id=$id"); exit;
    }
    $ok = 'err';
}
adm_head('Add lead');
?>
<h1 class="text-2xl font-bold mb-2">Add a lead manually</h1>
<p class="text-sm text-stone-500 mb-5">For prospects sourced outside the website - LinkedIn outreach, trade shows, referrals, WhatsApp. Manual entries are marked <code>manual</code> and never receive automated sequences until they submit a website form themselves.</p>
<?php if ($ok === 'err'): ?><p class="text-red-600 text-sm mb-4 font-medium">Name and a valid email are required.</p><?php endif; ?>
<form method="post" class="bg-white rounded-2xl shadow p-6 max-w-2xl grid md:grid-cols-2 gap-4 text-sm">
  <?= adm_csrf_field() ?>
  <label class="font-semibold">Name *<input name="name" required class="mt-1 w-full border rounded-lg px-3 py-2"></label>
  <label class="font-semibold">Email *<input name="email" type="email" required class="mt-1 w-full border rounded-lg px-3 py-2"></label>
  <label class="font-semibold">Company<input name="company" class="mt-1 w-full border rounded-lg px-3 py-2"></label>
  <label class="font-semibold">Country<input name="country" class="mt-1 w-full border rounded-lg px-3 py-2"></label>
  <label class="font-semibold">Source
    <select name="form_type" class="mt-1 w-full border rounded-lg px-2 py-2">
      <option>LinkedIn</option><option>Trade Show</option><option>Referral</option>
      <option>WhatsApp</option><option>Other Manual</option>
    </select></label>
  <label class="font-semibold">Product interest<input name="product_type" class="mt-1 w-full border rounded-lg px-3 py-2" placeholder="e.g. Hoodies"></label>
  <label class="font-semibold">Quantity<input name="quantity" class="mt-1 w-full border rounded-lg px-3 py-2" placeholder="e.g. 100"></label>
  <label class="font-semibold md:col-span-2">Context / message<textarea name="message" rows="3" class="mt-1 w-full border rounded-lg px-3 py-2"></textarea></label>
  <label class="font-semibold md:col-span-2">First note (optional)<input name="note" class="mt-1 w-full border rounded-lg px-3 py-2" placeholder="e.g. Connected on LinkedIn, asked for hoodie pricing"></label>
  <button class="md:col-span-2 bg-emerald-700 text-white font-bold rounded-lg py-2.5 hover:bg-emerald-800">Add lead</button>
</form>
<?php adm_foot();
