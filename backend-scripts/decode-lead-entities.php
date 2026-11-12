<?php
/**
 * One-off: undo the HTML entities that sanitize() used to write into the
 * leads table.
 *
 *   docker exec -i fabrioza-web php /var/www/html/../backend-scripts/decode-lead-entities.php          # dry run
 *   docker exec -i fabrioza-web php .../decode-lead-entities.php --apply
 *
 * Until 12 Nov 2026 send-email.php html-escaped every field BEFORE storing
 * it, while every consumer escapes again at output - so "Hoodies & Jackets"
 * was stored as "Hoodies &amp; Jackets" and the admin rendered the literal
 * "&amp;" to staff. The save-time escaping is gone; this cleans up the rows
 * written while it was there.
 *
 * SAFE TO RE-RUN. html_entity_decode on an already-decoded value is a no-op
 * unless the text genuinely contains an entity-looking string, so each row
 * is only written when decoding actually changes it, and the count of
 * changed rows is reported either way.
 *
 * Only the columns sanitize() touched are decoded. email is included because
 * it went through sanitize() too, though an address containing an entity
 * would never have passed FILTER_VALIDATE_EMAIL.
 */

$apply = in_array('--apply', $argv, true);
$dbPath = getenv('CRM_DB') ?: '/var/data/fabrioza_crm.db';

$cols = ['name', 'email', 'company', 'country', 'product_type', 'quantity',
         'message', 'form_type', 'source_page', 'utm_source', 'utm_medium',
         'utm_campaign', 'gclid', 'fbclid', 'referrer', 'landing_page'];

$db = new PDO('sqlite:' . $dbPath);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$rows = $db->query('SELECT id, ' . implode(', ', $cols) . ' FROM leads')
           ->fetchAll(PDO::FETCH_ASSOC);

$changedRows = 0;
$changedCells = 0;
$samples = [];

foreach ($rows as $row) {
    $set = [];
    $vals = [];
    foreach ($cols as $c) {
        $old = (string)($row[$c] ?? '');
        if ($old === '') { continue; }
        $new = html_entity_decode($old, ENT_QUOTES, 'UTF-8');
        if ($new === $old) { continue; }
        $set[] = "$c = ?";
        $vals[] = $new;
        $changedCells++;
        if (count($samples) < 6) {
            $samples[] = sprintf('  #%s %s: "%s" -> "%s"',
                $row['id'], $c, mb_substr($old, 0, 46), mb_substr($new, 0, 46));
        }
    }
    if (!$set) { continue; }
    $changedRows++;
    if ($apply) {
        $vals[] = $row['id'];
        $db->prepare('UPDATE leads SET ' . implode(', ', $set) . ' WHERE id = ?')
           ->execute($vals);
    }
}

echo "leads scanned : " . count($rows) . PHP_EOL;
echo "rows to fix   : $changedRows" . PHP_EOL;
echo "cells to fix  : $changedCells" . PHP_EOL;
if ($samples) { echo implode(PHP_EOL, $samples) . PHP_EOL; }
echo $apply ? "APPLIED" . PHP_EOL : "DRY RUN - nothing written. Pass --apply." . PHP_EOL;
