<?php
/**
 * Admin view of a Team Uniform Designer submission.
 *
 *   admin/design.php            list every submission
 *   admin/design.php?id=12      one design, roster, and the print pack
 *
 * The artwork is RE-RENDERED here from the stored parameters. The browser's
 * own SVG is never stored and never echoed, so nothing a customer sent can
 * become markup inside the admin origin.
 *
 * The 300 dpi print pack is built in THIS BROWSER, not on the server: the
 * container has no gd, no imagick and no zip, and gd could not rasterise an
 * SVG even if it were installed. Canvas can, at any resolution, and a stored
 * (uncompressed) ZIP is ~50 lines because PNG is already compressed. That also
 * avoids uploading 15 x 300 dpi PNGs through a 24 MB post_max_size.
 */
declare(strict_types=1);

require_once __DIR__ . '/_lib.php';
require_once __DIR__ . '/../api/db.php';

adm_require_login();
$db = crm_db();

/* ---------------- geometry: the same table the designer draws from ------- */
function td_garments(): array {
    return [
        'soccer' => ['label' => 'Soccer jersey', 'zones' => ['body','sleeves','collar','panels'],
            'body' => ['M90,70 L210,70 L214,330 L86,330 Z'],
            'sleeves' => ['M90,70 L60,86 L44,150 L80,160 L92,104 Z','M210,70 L240,86 L256,150 L220,160 L208,104 Z'],
            'collar' => ['M126,70 Q150,96 174,70 L166,62 Q150,80 134,62 Z'],
            'panels' => ['M86,330 L92,160 L104,160 L98,330 Z','M214,330 L208,160 L196,160 L202,330 Z']],
        'basketball' => ['label' => 'Basketball jersey', 'zones' => ['body','trim','collar','panels'],
            'body' => ['M96,74 L204,74 L212,330 L88,330 Z'],
            'collar' => ['M128,74 Q150,98 172,74 L164,66 Q150,84 136,66 Z'],
            'panels' => ['M88,330 L96,150 L108,150 L100,330 Z','M212,330 L204,150 L192,150 L200,330 Z'],
            'trim' => ['M96,74 L120,74 L112,116 L92,104 Z','M204,74 L180,74 L188,116 L208,104 Z']],
        'polo' => ['label' => 'Polo shirt', 'zones' => ['body','sleeves','collar','placket'],
            'body' => ['M92,74 L208,74 L212,326 L88,326 Z'],
            'sleeves' => ['M92,74 L62,90 L48,152 L86,162 L94,108 Z','M208,74 L238,90 L252,152 L214,162 L206,108 Z'],
            'collar' => ['M128,74 L150,96 L172,74 L164,60 L150,74 L136,60 Z'],
            'placket' => ['M144,92 L156,92 L156,150 L144,150 Z']],
    ];
}

function td_fonts(): array {
    return ['block' => "'Arial Narrow','Helvetica Neue',Arial,sans-serif",
            'classic' => "Georgia,'Times New Roman',serif",
            'rounded' => "'Trebuchet MS',Verdana,sans-serif"];
}

/**
 * Re-render a design. $player is null for the front view.
 * Every value interpolated here has been whitelisted on the way in
 * (hex colours, an enum garment, an enum font) and the player text is escaped.
 */
function td_svg(array $d, array $colours, ?array $player): string {
    $g = td_garments()[$d['garment']] ?? null;
    if (!$g) { return ''; }
    $fonts = td_fonts();
    $font  = $fonts[$d['font']] ?? $fonts['block'];

    $o = ['<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 300 360" width="300" height="360">'];
    $o[] = '<rect width="300" height="360" fill="#f6f7f8"/>';
    foreach (['body','panels','trim','sleeves','placket','collar'] as $z) {
        foreach (($g[$z] ?? []) as $path) {
            $fill = $colours[$z] ?? '#cccccc';
            $o[] = '<path d="' . $path . '" fill="' . $fill . '" stroke="rgba(0,0,0,.28)" stroke-width="1.2"/>';
        }
    }
    if ($player !== null) {
        $name = mb_strtoupper($player['player_name'] ?? '');
        $num  = (string)($player['player_number'] ?? '');
        $sw   = (int)$d['outline_width'];
        $stroke = $sw > 0
            ? ' stroke="' . $d['outline_colour'] . '" stroke-width="' . $sw
              . '" paint-order="stroke" stroke-linejoin="round"'
            : '';
        // Same fit rule as the designer: the body is ~120 units across.
        $fit = static function (string $t, float $max): float {
            $w = max(1, mb_strlen($t)) * 0.62;
            return max(9.0, min($max, 120 / $w));
        };
        $o[] = '<text x="150" y="128" text-anchor="middle" font-family="' . $font
             . '" font-size="' . round($fit($name, 26), 1) . '" font-weight="700" letter-spacing="1" fill="'
             . $d['text_colour'] . '"' . $stroke . '>' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '</text>';
        $o[] = '<text x="150" y="' . (mb_strlen($num) > 2 ? 230 : 238) . '" text-anchor="middle" font-family="' . $font
             . '" font-size="' . round($fit($num, 96), 1) . '" font-weight="800" fill="'
             . $d['text_colour'] . '"' . $stroke . '>' . htmlspecialchars($num, ENT_QUOTES, 'UTF-8') . '</text>';
    }
    $o[] = '</svg>';
    return implode('', $o);
}

/* ---------------- list ---------------------------------------------------- */
$id = (int)($_GET['id'] ?? 0);

if ($id <= 0) {
    adm_head('Kit designs');
    $rows = $db->query("SELECT d.id, d.reference, d.created_at, d.garment, l.name, l.email,
                               l.company, l.id AS lead_id,
                               (SELECT COUNT(*) FROM team_roster r WHERE r.design_id = d.id) AS players
                        FROM team_designs d LEFT JOIN leads l ON l.id = d.lead_id
                        ORDER BY d.id DESC LIMIT 200")->fetchAll();
    echo '<h1>Kit designs</h1>';
    if (!$rows) {
        echo '<p>No designs submitted yet.</p>';
    } else {
        echo '<table class="tbl"><tr><th>Ref</th><th>When</th><th>Customer</th><th>Garment</th>'
           . '<th>Players</th><th></th></tr>';
        foreach ($rows as $r) {
            echo '<tr><td>' . e($r['reference']) . '</td><td>' . e($r['created_at']) . '</td>'
               . '<td>' . e($r['name']) . ($r['company'] ? ' <small>' . e($r['company']) . '</small>' : '') . '</td>'
               . '<td>' . e(td_garments()[$r['garment']]['label'] ?? $r['garment']) . '</td>'
               . '<td>' . (int)$r['players'] . '</td>'
               . '<td><a href="design.php?id=' . (int)$r['id'] . '">Open</a>'
               . ($r['lead_id'] ? ' &middot; <a href="lead.php?id=' . (int)$r['lead_id'] . '">Lead</a>' : '')
               . '</td></tr>';
        }
        echo '</table>';
    }
    adm_foot();
    exit;
}

/* ---------------- one design ---------------------------------------------- */
$st = $db->prepare('SELECT d.*, l.name AS cname, l.email AS cemail, l.company AS ccompany,
                           l.country AS ccountry, l.id AS lead_id
                    FROM team_designs d LEFT JOIN leads l ON l.id = d.lead_id WHERE d.id = ?');
$st->execute([$id]);
$d = $st->fetch();
if (!$d) { adm_head('Not found'); echo '<p>No such design.</p>'; adm_foot(); exit; }

$rst = $db->prepare('SELECT * FROM team_roster WHERE design_id = ? ORDER BY position');
$rst->execute([$id]);
$roster = $rst->fetchAll();

$colours = json_decode((string)$d['colours'], true);
if (!is_array($colours)) { $colours = []; }
foreach ($colours as $k => $v) {
    if (!preg_match('/^#[0-9a-f]{6}$/i', (string)$v)) { $colours[$k] = '#cccccc'; }
}

$summary = [];
foreach ($roster as $p) { if ($p['size']) { $summary[$p['size']] = ($summary[$p['size']] ?? 0) + 1; } }

adm_head('Design ' . $d['reference']);
?>
<h1>Kit design <?= e($d['reference']) ?></h1>
<p>
  <strong><?= e($d['cname']) ?></strong>
  <?= $d['ccompany'] ? ' &middot; ' . e($d['ccompany']) : '' ?>
  &middot; <a href="mailto:<?= e($d['cemail']) ?>"><?= e($d['cemail']) ?></a>
  <?= $d['ccountry'] ? ' &middot; ' . e($d['ccountry']) : '' ?>
  <?php if ($d['lead_id']): ?> &middot; <a href="lead.php?id=<?= (int)$d['lead_id'] ?>">open lead</a><?php endif; ?>
</p>
<p><?= e(td_garments()[$d['garment']]['label'] ?? $d['garment']) ?> &middot;
   <?= count($roster) ?> players &middot; submitted <?= e($d['created_at']) ?></p>

<?php if ($d['logo_name']): ?>
  <p>Logo: <strong><?= e($d['logo_name']) ?></strong>
  <?php if ($d['logo_upload_id']): ?>
    &mdash; <a href="file.php?id=<?= (int)$d['logo_upload_id'] ?>">download</a>
    <small>(downloads only, never rendered here)</small>
  <?php else: ?>
    &mdash; <em>not attached; see the log</em>
  <?php endif; ?></p>
<?php endif; ?>

<div style="display:flex;gap:18px;flex-wrap:wrap;margin:14px 0">
  <div><div style="font-size:12px;text-transform:uppercase;color:#666">Front</div>
    <?= td_svg($d, $colours, null) ?></div>
  <div><div style="font-size:12px;text-transform:uppercase;color:#666">Back (first player)</div>
    <?= td_svg($d, $colours, $roster[0] ?? ['player_name' => 'PLAYER', 'player_number' => '10']) ?></div>
</div>

<p><strong>Colours:</strong>
<?php foreach ($colours as $zone => $hex): ?>
  <span style="display:inline-block;margin-right:10px"><span style="display:inline-block;width:12px;height:12px;background:<?= e($hex) ?>;border:1px solid #999;vertical-align:middle"></span>
  <?= e($zone) ?> <code><?= e($hex) ?></code></span>
<?php endforeach; ?>
</p>
<p><strong>Back text:</strong> font <?= e($d['font']) ?>, colour <code><?= e($d['text_colour']) ?></code>,
   outline <code><?= e($d['outline_colour']) ?></code> at <?= (int)$d['outline_width'] ?>px,
   logo <?= e(rtrim(rtrim(number_format((float)$d['logo_width_cm'], 1), '0'), '.')) ?> cm wide.</p>

<p><strong>Sizes:</strong>
<?php foreach (['YXS','YS','YM','YL','XS','S','M','L','XL','2XL','3XL'] as $s):
    if (!empty($summary[$s])): ?>
  <span style="display:inline-block;background:#eef3ef;border:1px solid #d7e3da;border-radius:99px;padding:2px 9px;margin-right:6px"><?= $s ?> &times; <?= (int)$summary[$s] ?></span>
<?php endif; endforeach; ?>
  <span style="font-weight:700">Total <?= count($roster) ?></span>
</p>

<p style="margin:16px 0">
  <button id="pack" class="btn">Download print pack (300 dpi ZIP)</button>
  <a class="btn" href="design-sheet.php?id=<?= (int)$id ?>">Order sheet (PDF)</a>
  <span id="packMsg" style="margin-left:10px"></span>
</p>

<table class="tbl"><tr><th>#</th><th>Player</th><th>Number</th><th>Size</th></tr>
<?php foreach ($roster as $p): ?>
  <tr><td><?= (int)$p['position'] ?></td><td><?= e($p['player_name']) ?></td>
      <td><?= e($p['player_number']) ?></td><td><?= e($p['size']) ?></td></tr>
<?php endforeach; ?>
</table>

<script>
/* Per-player artwork, rasterised here at 300 dpi and zipped in the browser.
   One <svg> per player is serialised, drawn to a canvas sized in real
   centimetres, and stored uncompressed - PNG is already deflated, so STORED
   costs nothing and keeps the writer short. */
(function(){
  var PLAYERS = <?= json_encode(array_map(static function ($p) {
      return ['name' => $p['player_name'], 'number' => $p['player_number'], 'size' => $p['size']];
  }, $roster), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  var REF = <?= json_encode($d['reference'], JSON_HEX_TAG | JSON_HEX_AMP) ?>;
  var TEMPLATE = <?= json_encode(td_svg($d, $colours, ['player_name' => '@@NAME@@', 'player_number' => '@@NUM@@']),
      JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

  var CM_W = 30, DPI = 300;                   // 30 cm wide print area
  var PX_W = Math.round(CM_W / 2.54 * DPI);   // 3543 px
  var PX_H = Math.round(PX_W * 360 / 300);

  function esc(s){ return String(s).replace(/[&<>"']/g, function(c){
    return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }
  function safe(s){ return String(s).replace(/[^A-Za-z0-9._-]+/g,'_').replace(/^_+|_+$/g,'') || 'player'; }

  function renderPlayer(p){
    return new Promise(function(resolve, reject){
      var svg = TEMPLATE.replace('@@NAME@@', esc(String(p.name||'').toUpperCase()))
                        .replace('@@NUM@@',  esc(String(p.number||'')));
      var img = new Image();
      var url = URL.createObjectURL(new Blob([svg], {type:'image/svg+xml;charset=utf-8'}));
      img.onload = function(){
        var c = document.createElement('canvas');
        c.width = PX_W; c.height = PX_H;
        var x = c.getContext('2d');
        x.fillStyle = '#ffffff'; x.fillRect(0,0,c.width,c.height);
        x.drawImage(img, 0, 0, c.width, c.height);
        URL.revokeObjectURL(url);
        c.toBlob(function(b){ b ? b.arrayBuffer().then(resolve) : reject(new Error('toBlob failed')); }, 'image/png');
      };
      img.onerror = function(){ URL.revokeObjectURL(url); reject(new Error('svg load failed')); };
      img.src = url;
    });
  }

  /* CRC32 + a STORED-only zip writer. */
  var TBL = (function(){ var t=new Uint32Array(256);
    for (var n=0;n<256;n++){ var c=n; for (var k=0;k<8;k++) c = c&1 ? 0xEDB88320^(c>>>1) : c>>>1; t[n]=c>>>0; }
    return t; })();
  function crc32(u8){ var c=0xFFFFFFFF; for (var i=0;i<u8.length;i++) c=TBL[(c^u8[i])&0xFF]^(c>>>8); return (c^0xFFFFFFFF)>>>0; }

  function zip(files){
    var enc = new TextEncoder(), chunks = [], central = [], offset = 0;
    function u32(v){ return new Uint8Array([v&255,(v>>>8)&255,(v>>>16)&255,(v>>>24)&255]); }
    function u16(v){ return new Uint8Array([v&255,(v>>>8)&255]); }
    files.forEach(function(f){
      var nameB = enc.encode(f.name), data = new Uint8Array(f.data), c = crc32(data);
      var local = [].concat([u32(0x04034b50), u16(20), u16(0), u16(0), u16(0), u16(0),
                             u32(c), u32(data.length), u32(data.length),
                             u16(nameB.length), u16(0), nameB, data]);
      local.forEach(function(p){ chunks.push(p); });
      central.push([u32(0x02014b50), u16(20), u16(20), u16(0), u16(0), u16(0), u16(0),
                    u32(c), u32(data.length), u32(data.length), u16(nameB.length),
                    u16(0), u16(0), u16(0), u16(0), u32(0), u32(offset), nameB]);
      offset += 30 + nameB.length + data.length;
    });
    var cstart = offset, cbytes = [];
    central.forEach(function(rec){ rec.forEach(function(p){ cbytes.push(p); offset += p.length; }); });
    var tail = [u32(0x06054b50), u16(0), u16(0), u16(files.length), u16(files.length),
                u32(offset - cstart), u32(cstart), u16(0)];
    return new Blob(chunks.concat(cbytes, tail), {type:'application/zip'});
  }

  document.getElementById('pack').addEventListener('click', function(){
    var btn = this, msg = document.getElementById('packMsg');
    btn.disabled = true;
    var files = [], i = 0;
    (function next(){
      if (i >= PLAYERS.length){
        msg.textContent = 'zipping...';
        var blob = zip(files);
        var a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = REF + '_print_pack.zip';
        document.body.appendChild(a); a.click(); a.remove();
        msg.textContent = PLAYERS.length + ' files, ' + (blob.size/1048576).toFixed(1) + ' MB';
        btn.disabled = false; return;
      }
      var p = PLAYERS[i];
      msg.textContent = 'rendering ' + (i+1) + ' of ' + PLAYERS.length + '...';
      renderPlayer(p).then(function(buf){
        files.push({name: safe(p.name) + '_' + safe(p.number) + '_' + safe(p.size) + '.png', data: buf});
        i++; next();
      }).catch(function(err){
        msg.textContent = 'failed on ' + (p.name || ('row ' + (i+1))) + ': ' + err.message;
        btn.disabled = false;
      });
    })();
  });
})();
</script>
<?php adm_foot();
