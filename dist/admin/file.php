<?php
/**
 * Authenticated download for a lead attachment.
 *
 * Uploads live in /var/data/uploads, which is outside the web root and is not
 * served by Apache. This is the only route to them, and it requires an admin
 * login. The file is streamed, never redirected to, so there is no public URL
 * that could be shared or guessed.
 */
require __DIR__ . '/_lib.php';
require __DIR__ . '/../api/uploads.php';
adm_require_login();

$db = crm_db();
$id  = (int)($_GET['id'] ?? 0);

$row = $db->prepare('SELECT * FROM lead_files WHERE id = ?');
$row->execute([$id]);
$row = $row->fetch();

if (!$row) {
    http_response_code(404);
    exit('Not found');
}

$path = crm_upload_path($row);
if (!is_file($path)) {
    // The DB row outlived the file - say so plainly rather than serving 0 bytes.
    http_response_code(410);
    exit('That file is no longer on disk. The database row remains; the stored file is missing.');
}

// Force a download. Never inline: an uploaded PDF or EPS rendered in the
// admin's own origin is a scripting risk we gain nothing from.
header('Content-Type: ' . ($row['mime'] ?: 'application/octet-stream'));
header('Content-Length: ' . filesize($path));
// Serve under the extension the CONTENT proved, not the one the uploader
// typed. A real PDF named "shell.php" is stored as .pdf and downloads as .pdf.
$realExt  = pathinfo($row['stored_name'], PATHINFO_EXTENSION);
$baseName = pathinfo($row['orig_name'], PATHINFO_FILENAME);
$dlName   = str_replace('"', '', $baseName . '.' . $realExt);
header('Content-Disposition: attachment; filename="' . $dlName . '"');
header('X-Content-Type-Options: nosniff');
header('Content-Security-Policy: default-src \'none\'');
header('Cache-Control: private, no-store');

adm_audit($db, 'file_download', (int)$row['lead_id']);

readfile($path);
