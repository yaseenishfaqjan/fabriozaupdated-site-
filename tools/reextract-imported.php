<?php
/**
 * Re-run brand/country/language/interest extraction over leads already
 * imported from the mailbox, using the stored message text.
 *
 * WHY THIS EXISTS. The first Gmail import ran with a latent bug: preg_* with
 * the /u modifier returns false on invalid UTF-8, so every Windows-1252
 * message (Outlook's default for many senders) silently lost its brand and
 * fell through to a subject-line guess. Re-fetching 962 messages to repair
 * that would be wasteful when the text is already in the table.
 *
 *   php tools/reextract-imported.php --dry-run
 *   php tools/reextract-imported.php
 *
 * WHAT IT WILL NOT DO: overwrite a value with a WORSE one. A recomputed
 * value is only written when the column is empty, or when the stored value
 * is one the subject-line path produced - which is the exact set the bug
 * created. Anything else is reported and left alone, because a human may
 * have typed it.
 *
 * The subject is deliberately NOT passed to inbox_brand() here. The stored
 * message already begins with the subject text, so the subject path would
 * re-derive the same wrong answers this script exists to clean up.
 */
require __DIR__ . '/../dist/api/lib/inbox_extract.php';
require __DIR__ . '/../dist/api/lib/lead_tags.php';

$dry = in_array('--dry-run', $argv, true);
$db = new PDO('sqlite:/var/data/fabrioza_crm.db');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

$rows = $db->query("SELECT id, email, company, country, reply_lang, interests,
                           COALESCE(message,'') m
                      FROM leads WHERE source_page = 'mailbox-import'
                      ORDER BY id")->fetchAll();

$up = $dry ? null : $db->prepare(
    'UPDATE leads SET company = ?, country = ?, reply_lang = ?, interests = ? WHERE id = ?');

$changed = $kept = 0;
foreach ($rows as $r) {
    $clean = inbox_strip_quoted($r['m']);
    list($brand, $how) = inbox_brand($clean, $r['email'], '');   /* no subject - see above */
    $country = inbox_country($clean);
    $lang    = lead_reply_lang($clean);
    $tags    = lead_interests($clean, '');

    $oldCo = trim((string) $r['company']);
    $newCo = $oldCo;
    $note  = '';
    /* A WRONG BRAND IS WORSE THAN NONE, which is the rule the extractor
       itself follows, so it has to apply to cleanup too. When the stored
       value is a subject-line guess and nothing better can be derived, the
       column is CLEARED rather than left reading like a fact. The first
       version of this script required a replacement to exist and so left
       a product description sitting in a company field. */
    if ($brand === null && $oldCo !== '' && inbox_brand_is_noise($oldCo)) {
        $newCo = '';
        $note  = 'CLEARED (not a name - trade vocabulary only)';
    } elseif ($brand !== null && $brand !== $oldCo) {
        if ($oldCo === '') { $newCo = $brand; $note = 'filled'; }
        /* The bug's fingerprint: the stored value is a fragment of the
           stored subject line, which is what the subject path returns. */
        elseif (inbox_brand_is_noise($oldCo)) {
            $newCo = $brand; $note = 'replaced a value the rules now reject';
        } else {
            $note = 'LEFT ALONE (stored value is a plausible name)';
        }
    }

    $newCountry = trim((string) $r['country']) !== '' ? $r['country'] : $country;
    $newLang    = $r['reply_lang'] !== null ? $r['reply_lang'] : $lang;
    $newTags    = ($r['interests'] !== null && $r['interests'] !== '') ? $r['interests'] : $tags;

    $diff = ($newCo !== $oldCo) || ($newCountry !== $r['country'])
         || ($newLang !== $r['reply_lang']) || ($newTags !== $r['interests']);

    if (!$diff) { $kept++; continue; }
    $changed++;
    printf("#%-4d %-30s%s", $r['id'], mb_substr($r['email'], 0, 30), PHP_EOL);
    if ($newCo !== $oldCo || $note !== '') {
        printf("      brand   %-26s -> %-26s %s%s",
            $oldCo === '' ? '(none)' : $oldCo,
            $brand === null ? '(none)' : $brand, $note, PHP_EOL);
    }
    if ($newCountry !== $r['country']) printf("      country (none) -> %s%s", $newCountry, PHP_EOL);
    if ($newLang !== $r['reply_lang'])  printf("      lang    (none) -> %s%s", $newLang, PHP_EOL);
    if ($newTags !== $r['interests'])
        printf("      wants   %s -> %s%s", $r['interests'] ?? '(none)', $newTags ?? '(none)', PHP_EOL);

    if (!$dry && strpos($note, 'LEFT ALONE') === false) {
        $up->execute([$newCo === '' ? null : $newCo, $newCountry, $newLang, $newTags, $r['id']]);
    }
}
printf("%s%s %d of %d leads changed, %d already correct%s",
    PHP_EOL, $dry ? 'DRY:' : 'applied:', $changed, count($rows), $kept, PHP_EOL);
