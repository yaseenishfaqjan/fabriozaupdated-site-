<?php
/**
 * Lead file attachments.
 *
 * Context: the contact form has had a file input since launch that accepted
 * .jpg/.png/.pdf/.ai/.eps, but it carried no onChange, name, ref or value, and
 * the form posts application/json. The browser never read the file and nothing
 * was ever uploaded. Customers attached tech packs and artwork, saw "thank
 * you", and we received nothing. This is the server half of the fix.
 *
 * Storage rules, both of which matter:
 *  - Files go to CRM_DATA_DIR (/var/data), the named Docker volume. They must
 *    NEVER be written under the web root: the Dockerfile does `COPY dist/
 *    /var/www/html/`, so anything there is destroyed on the next rebuild.
 *  - Nothing under /var/data is web-served, so an attachment can only be
 *    reached through admin/file.php, which requires a login.
 *
 * Validation is by MAGIC BYTES, not by the filename extension the browser
 * reported. An uploaded file is attacker-controlled input.
 */

const CRM_UPLOAD_MAX_BYTES  = 20971520;   // 20 MB per file
const CRM_UPLOAD_MAX_FILES  = 5;

/** Accepted types: leading magic bytes -> canonical extension. */
function crm_upload_signatures(): array {
    return [
        'jpg' => ["\xFF\xD8\xFF"],
        'png' => ["\x89PNG\r\n\x1A\n"],
        'pdf' => ['%PDF-'],
        // Illustrator files are either PDF-based (modern) or EPS-based (legacy)
        'ai'  => ['%PDF-', '%!PS-Adobe', "\xC5\xD0\xD3\xC6"],
        'eps' => ['%!PS-Adobe', "\xC5\xD0\xD3\xC6"],
    ];
}

/** Detect the real type from content. Returns an extension, or null. */
function crm_upload_sniff(string $bytes): ?string {
    foreach (crm_upload_signatures() as $ext => $magics) {
        foreach ($magics as $m) {
            if (strncmp($bytes, $m, strlen($m)) === 0) {
                return $ext;
            }
        }
    }
    // SVG is text, not a binary with a fixed magic number. Real files start
    // with a BOM, blank lines or an XML declaration in any combination, so a
    // strncmp from byte 0 misses most of them. Require BOTH an XML-ish
    // opening AND a literal <svg element, so an arbitrary XML document
    // cannot pass as an image.
    $head = ltrim(substr($bytes, 0, 1024), " \t\r\n\xEF\xBB\xBF");
    if ($head !== '' && $head[0] === '<'
        && (strncmp($head, '<svg', 4) === 0 || strncmp($head, '<?xml', 5) === 0)
        && stripos(substr($bytes, 0, 4096), '<svg') !== false) {
        return 'svg';
    }
    return null;
}

function crm_upload_mime(string $ext): string {
    return [
        'jpg' => 'image/jpeg',
        'png' => 'image/png',
        'pdf' => 'application/pdf',
        'ai'  => 'application/postscript',
        'eps' => 'application/postscript',
        // Stored as octet-stream ON PURPOSE. An SVG is executable XML and
        // admin/file.php echoes this header, so octet-stream guarantees it
        // can only ever download, never render in the admin origin. The
        // real type stays in the filename.
        'svg' => 'application/octet-stream',
    ][$ext] ?? 'application/octet-stream';
}

function crm_upload_dir(): string {
    return (getenv('CRM_DATA_DIR') ?: '/var/data') . '/uploads';
}

/**
 * Store one base64 attachment against a lead.
 *
 * @param array $f ['name' => original filename, 'data' => base64 string]
 * @return array{ok:bool, error?:string, id?:int, name?:string, size?:int}
 */
function crm_store_upload(PDO $db, int $leadId, array $f): array {
    $origName = (string)($f['name'] ?? '');
    $b64      = (string)($f['data'] ?? '');

    // Strip a data: URI prefix if the client sent one.
    if (($c = strpos($b64, ',')) !== false && strncmp($b64, 'data:', 5) === 0) {
        $b64 = substr($b64, $c + 1);
    }
    // Reject before decoding: base64 is ~4/3 of the raw size.
    if (strlen($b64) > (int)(CRM_UPLOAD_MAX_BYTES * 1.4)) {
        return ['ok' => false, 'error' => 'too large'];
    }
    $bytes = base64_decode($b64, true);
    if ($bytes === false || $bytes === '') {
        return ['ok' => false, 'error' => 'not valid base64'];
    }
    $size = strlen($bytes);
    if ($size > CRM_UPLOAD_MAX_BYTES) {
        return ['ok' => false, 'error' => 'too large'];
    }

    $ext = crm_upload_sniff($bytes);
    if ($ext === null) {
        // Content did not match any accepted type, whatever the name claimed.
        return ['ok' => false, 'error' => 'file type not accepted'];
    }

    // Keep a readable original name for the admin, but never trust it on disk.
    $safeName = preg_replace('/[^A-Za-z0-9._ -]/', '_', basename($origName));
    $safeName = mb_substr(trim($safeName) ?: "attachment.$ext", 0, 120);

    $dir = crm_upload_dir() . '/' . $leadId;
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
        return ['ok' => false, 'error' => 'storage unavailable'];
    }
    $stored = bin2hex(random_bytes(16)) . '.' . $ext;
    if (@file_put_contents($dir . '/' . $stored, $bytes) === false) {
        return ['ok' => false, 'error' => 'could not be saved'];
    }
    @chmod($dir . '/' . $stored, 0600);

    $db->prepare('INSERT INTO lead_files (lead_id, orig_name, stored_name, mime, size_bytes)
                  VALUES (?,?,?,?,?)')
       ->execute([$leadId, $safeName, $stored, crm_upload_mime($ext), $size]);

    return ['ok' => true, 'id' => (int)$db->lastInsertId(),
            'name' => $safeName, 'size' => $size];
}

/** Absolute path of a stored file, or null if the row is missing. */
function crm_upload_path(array $row): string {
    return crm_upload_dir() . '/' . (int)$row['lead_id'] . '/' . $row['stored_name'];
}

/** Remove a lead's files from disk. Called on GDPR erasure. */
function crm_delete_lead_files(PDO $db, int $leadId): int {
    $rows = $db->prepare('SELECT * FROM lead_files WHERE lead_id = ?');
    $rows->execute([$leadId]);
    $n = 0;
    foreach ($rows->fetchAll() as $r) {
        if (@unlink(crm_upload_path($r))) { $n++; }
    }
    @rmdir(crm_upload_dir() . '/' . $leadId);
    $db->prepare('DELETE FROM lead_files WHERE lead_id = ?')->execute([$leadId]);
    return $n;
}

function crm_format_bytes(int $b): string {
    if ($b >= 1048576) { return round($b / 1048576, 1) . ' MB'; }
    if ($b >= 1024)    { return round($b / 1024) . ' KB'; }
    return $b . ' B';
}

/**
 * Store one MULTIPART upload (a $_FILES entry) against a lead.
 *
 * Why this exists next to crm_store_upload(): the contact form posts
 * attachments base64-encoded in a JSON body, which is fine for a few MB but
 * holds ~4/3 of the file in memory. The custom-fabric form takes up to five
 * tech packs at 20 MB, so it posts multipart/form-data instead - PHP streams
 * each one to a temp file and memory stays flat.
 *
 * Validation is identical and deliberately so: magic bytes decide the type,
 * never the extension the browser reported, and the file is written outside
 * the web root under CRM_DATA_DIR. The ONLY difference is where the bytes
 * come from.
 *
 * @param array $file one entry of $_FILES, already de-interleaved
 * @return array{ok:bool, error?:string, id?:int, name?:string, size?:int}
 */
function crm_store_uploaded_file(PDO $db, int $leadId, array $file): array {
    $err = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($err === UPLOAD_ERR_NO_FILE) { return ['ok' => false, 'error' => 'no file']; }
    if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
        return ['ok' => false, 'error' => 'too large'];
    }
    if ($err !== UPLOAD_ERR_OK) { return ['ok' => false, 'error' => 'upload failed (' . $err . ')']; }

    $tmp = (string)($file['tmp_name'] ?? '');
    // is_uploaded_file is the check that stops a crafted path being read off
    // the server and filed as a customer attachment.
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        return ['ok' => false, 'error' => 'not an uploaded file'];
    }
    $size = (int)($file['size'] ?? 0);
    if ($size <= 0) { return ['ok' => false, 'error' => 'empty file']; }
    if ($size > CRM_UPLOAD_MAX_BYTES) { return ['ok' => false, 'error' => 'too large']; }

    // Sniff from the head only: no reason to read 20 MB to identify a type.
    $fh = @fopen($tmp, 'rb');
    if (!$fh) { return ['ok' => false, 'error' => 'unreadable']; }
    $head = (string)fread($fh, 4096);
    fclose($fh);
    $ext = crm_upload_sniff($head);
    if ($ext === null) { return ['ok' => false, 'error' => 'file type not accepted']; }

    $origName = (string)($file['name'] ?? '');
    $safeName = preg_replace('/[^A-Za-z0-9._ -]/', '_', basename($origName));
    $safeName = mb_substr(trim($safeName) ?: "attachment.$ext", 0, 120);

    $dir = crm_upload_dir() . '/' . $leadId;
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
        return ['ok' => false, 'error' => 'storage unavailable'];
    }
    $stored = bin2hex(random_bytes(16)) . '.' . $ext;
    if (!@move_uploaded_file($tmp, $dir . '/' . $stored)) {
        return ['ok' => false, 'error' => 'could not be saved'];
    }
    @chmod($dir . '/' . $stored, 0600);

    $db->prepare('INSERT INTO lead_files (lead_id, orig_name, stored_name, mime, size_bytes)
                  VALUES (?,?,?,?,?)')
       ->execute([$leadId, $safeName, $stored, crm_upload_mime($ext), $size]);

    return ['ok' => true, 'id' => (int)$db->lastInsertId(),
            'name' => $safeName, 'size' => $size];
}
