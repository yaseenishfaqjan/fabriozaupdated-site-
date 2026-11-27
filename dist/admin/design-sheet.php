<?php
/**
 * Order sheet for a kit design, as a real PDF.
 *
 * Written by hand because there is no PDF library in this image and no
 * composer to add one. A text-and-rules table needs only Helvetica (one of
 * the 14 fonts every reader has built in), so the whole file is a catalog, a
 * pages node, one page object per sheet, a font, and a content stream each.
 * No compression: the file is a few KB and an uncompressed stream is far
 * easier to debug if a reader ever complains.
 */
declare(strict_types=1);

require_once __DIR__ . '/_lib.php';
require_once __DIR__ . '/../api/db.php';

adm_require_login();
$db = crm_db();

$id = (int)($_GET['id'] ?? 0);
$st = $db->prepare('SELECT d.*, l.name AS cname, l.company AS ccompany, l.email AS cemail
                    FROM team_designs d LEFT JOIN leads l ON l.id = d.lead_id WHERE d.id = ?');
$st->execute([$id]);
$d = $st->fetch();
if (!$d) { http_response_code(404); echo 'No such design.'; exit; }

$rst = $db->prepare('SELECT * FROM team_roster WHERE design_id = ? ORDER BY position');
$rst->execute([$id]);
$roster = $rst->fetchAll();

$GARMENTS = ['soccer' => 'Soccer jersey', 'basketball' => 'Basketball jersey', 'polo' => 'Polo shirt'];

/* ---- PDF primitives ----------------------------------------------------- */

/** Escape for a PDF literal string, and drop anything outside WinAnsi. */
function pdf_str(string $s): string {
    $s = iconv('UTF-8', 'Windows-1252//TRANSLIT', $s);
    if ($s === false) { $s = ''; }
    return '(' . str_replace(['\\', '(', ')', "\r", "\n"], ['\\\\', '\\(', '\\)', ' ', ' '], $s) . ')';
}

function pdf_text(array &$ops, float $x, float $y, string $text, float $size = 10, bool $bold = false): void {
    $ops[] = 'BT /' . ($bold ? 'F2' : 'F1') . ' ' . $size . ' Tf '
           . round($x, 2) . ' ' . round($y, 2) . ' Td ' . pdf_str($text) . ' Tj ET';
}

function pdf_line(array &$ops, float $x1, float $y1, float $x2, float $y2): void {
    $ops[] = '0.75 w 0.78 0.80 0.82 RG ' . round($x1, 2) . ' ' . round($y1, 2) . ' m '
           . round($x2, 2) . ' ' . round($y2, 2) . ' l S';
}

/* ---- compose the sheets -------------------------------------------------- */
const PW = 595.28, PH = 841.89;           // A4 portrait, points
const ML = 48, MR = 48;
const ROW_H = 18;

$sizes = [];
foreach ($roster as $p) { if ($p['size']) { $sizes[$p['size']] = ($sizes[$p['size']] ?? 0) + 1; } }
$sizeLine = [];
foreach (['YXS','YS','YM','YL','XS','S','M','L','XL','2XL','3XL'] as $s) {
    if (!empty($sizes[$s])) { $sizeLine[] = $s . ' x ' . $sizes[$s]; }
}

$pages   = [];
$i       = 0;
$total   = count($roster);
$pageNum = 0;

while ($i < $total || $pageNum === 0) {
    $pageNum++;
    $ops = [];
    $y = PH - 56;

    pdf_text($ops, ML, $y, 'FABRIOZA - Order sheet', 16, true);
    $y -= 18;
    pdf_text($ops, ML, $y, 'Reference ' . (string)$d['reference']
        . '   ' . ($GARMENTS[$d['garment']] ?? (string)$d['garment'])
        . '   ' . $total . ' players', 10);
    $y -= 14;
    pdf_text($ops, ML, $y, trim((string)$d['cname'] . ' ' . ((string)$d['ccompany'] !== '' ? '(' . $d['ccompany'] . ')' : ''))
        . '   ' . (string)$d['cemail'], 10);
    $y -= 14;
    pdf_text($ops, ML, $y, 'Submitted ' . (string)$d['created_at']
        . ($sizeLine ? '   Sizes: ' . implode(', ', $sizeLine) : ''), 9);

    $y -= 22;
    pdf_text($ops, ML, $y, '#', 10, true);
    pdf_text($ops, ML + 36, $y, 'Player name', 10, true);
    pdf_text($ops, ML + 300, $y, 'Number', 10, true);
    pdf_text($ops, ML + 400, $y, 'Size', 10, true);
    $y -= 6;
    pdf_line($ops, ML, $y, PW - MR, $y);
    $y -= ROW_H;

    while ($i < $total && $y > 64) {
        $p = $roster[$i];
        pdf_text($ops, ML, $y, (string)$p['position'], 10);
        pdf_text($ops, ML + 36, $y, (string)$p['player_name'], 10);
        pdf_text($ops, ML + 300, $y, (string)$p['player_number'], 10);
        pdf_text($ops, ML + 400, $y, (string)$p['size'], 10);
        $i++;
        $y -= 4;
        pdf_line($ops, ML, $y, PW - MR, $y);
        $y -= ROW_H - 4;
    }

    pdf_text($ops, ML, 42, 'fabrioza.com   info@fabrioza.com   +92 312 4815601', 8);
    pdf_text($ops, PW - MR - 60, 42, 'Page ' . $pageNum, 8);
    $pages[] = implode("\n", $ops);
    if ($i >= $total) { break; }
}

/* ---- assemble ------------------------------------------------------------ */
$objects = [];                       // 1-indexed
$nPages  = count($pages);
$kids    = [];
$firstPageObj = 4;                   // 1 catalog, 2 pages, 3 fonts pair starts at 3
for ($k = 0; $k < $nPages; $k++) { $kids[] = ($firstPageObj + $k * 2) . ' 0 R'; }

$objects[1] = "<< /Type /Catalog /Pages 2 0 R >>";
$objects[2] = "<< /Type /Pages /Count $nPages /Kids [" . implode(' ', $kids) . "] >>";
$objects[3] = "<< /Font << /F1 " . ($firstPageObj + $nPages * 2) . " 0 R /F2 "
            . ($firstPageObj + $nPages * 2 + 1) . " 0 R >> >>";

for ($k = 0; $k < $nPages; $k++) {
    $pageObj    = $firstPageObj + $k * 2;
    $contentObj = $pageObj + 1;
    $objects[$pageObj] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 " . PW . ' ' . PH . "] "
        . "/Resources 3 0 R /Contents $contentObj 0 R >>";
    $stream = $pages[$k];
    $objects[$contentObj] = "<< /Length " . strlen($stream) . " >>\nstream\n" . $stream . "\nendstream";
}
$objects[$firstPageObj + $nPages * 2]     = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>";
$objects[$firstPageObj + $nPages * 2 + 1] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>";

ksort($objects);
$pdf = "%PDF-1.4\n";
$offsets = [];
foreach ($objects as $num => $body) {
    $offsets[$num] = strlen($pdf);
    $pdf .= "$num 0 obj\n$body\nendobj\n";
}
$xrefPos = strlen($pdf);
$count   = count($objects) + 1;
$pdf .= "xref\n0 $count\n0000000000 65535 f \n";
foreach ($objects as $num => $_) {
    $pdf .= sprintf("%010d 00000 n \n", $offsets[$num]);
}
$pdf .= "trailer\n<< /Size $count /Root 1 0 R >>\nstartxref\n$xrefPos\n%%EOF";

$name = preg_replace('/[^A-Za-z0-9._-]+/', '_', (string)$d['reference']) . '_order_sheet.pdf';
header('Content-Type: application/pdf');
header('Content-Length: ' . strlen($pdf));
header('Content-Disposition: attachment; filename="' . $name . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
echo $pdf;
