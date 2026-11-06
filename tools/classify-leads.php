<?php
/**
 * Tag every lead with what it asked for, and optionally put the
 * unscheduled backlog onto /admin/followups.php.
 *
 * /admin/followups.php ALREADY IS the worklist - due/overdue, "Mark
 * contacted", notes. It selects on next_follow_up, and only 6 of 87 leads
 * had one, so it read "All caught up" over an 81-lead backlog. Nothing was
 * broken; the data never reached the page. This fills the data in rather
 * than building a second page.
 *
 *   --dry-run          report, write nothing
 *   --seed-followups   also give unscheduled open leads a follow-up date
 *
 * Seeding is behind its own flag because it changes what a human sees on
 * their work queue tomorrow morning; classifying does not.
 * Safe to re-run: tags are recomputed, and a date a human set is never
 * overwritten (the UPDATE re-tests next_follow_up IS NULL).
 */
require __DIR__ . '/../dist/api/lib/lead_tags.php';

$dry  = in_array('--dry-run', $argv, true);
$seed = in_array('--seed-followups', $argv, true);

$db = new PDO('sqlite:/var/data/fabrioza_crm.db');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

/* ---- migration 7 -------------------------------------------------------
   Two nullable text columns. ONE COMMA-SEPARATED COLUMN, NOT TWELVE
   BOOLEANS: a new question then costs a line in leadtags.php rather than a
   migration, and the admin pages render it without knowing the vocabulary. */
$cols = array_column($db->query('PRAGMA table_info(leads)')->fetchAll(), 'name');
foreach (['interests' => 'TEXT', 'reply_lang' => 'TEXT'] as $c => $t) {
    if (in_array($c, $cols, true)) continue;
    if ($dry) { echo "DRY: would add leads.$c $t\n"; continue; }
    $db->exec("ALTER TABLE leads ADD COLUMN $c $t");
    echo "added leads.$c $t\n";
}
$ver = (int) $db->query('PRAGMA user_version')->fetch()['user_version'];
if ($ver < 7 && !$dry) { $db->exec('PRAGMA user_version = 7'); echo "user_version 6 -> 7\n"; }
elseif ($ver < 7) echo "DRY: would set user_version = 7\n";

/* ---- classify ----------------------------------------------------------
   THEIR message and product_type only - never our notes. Including notes
   changed the result by 2 leads out of 87 while making the tag mean "this
   came up in the thread" instead of "the customer asked for this". */
$rows = $db->query("SELECT id, COALESCE(message,'') m, COALESCE(product_type,'') p FROM leads")->fetchAll();

/* Language is read from their message AND their imported replies; interests
   are not. The two want different sources, and the first version used one
   for both: at least one lead opened in English and continued in another
   language, so detecting on `message` alone would have us answer in the
   wrong one. An interest tag is the opposite case - it must stay the
   ORIGINAL ask, because a word WE used in a note is not something the
   customer asked for. */
$inbound = [];
foreach ($db->query("SELECT lead_id, GROUP_CONCAT(body, ' ') b FROM notes
                     WHERE author = 'inbox-import' GROUP BY lead_id") as $n)
    $inbound[$n['lead_id']] = $n['b'];
/* prepared only for a real run - in --dry-run the column does not exist yet,
   which the first dry run discovered by failing here. */
$upd  = $dry ? null : $db->prepare('UPDATE leads SET interests = ?, reply_lang = ? WHERE id = ?');

$counts = []; $tagged = 0; $junk = 0; $langs = []; $hot = [];
foreach ($rows as $r) {
    $msg = trim($r['m']);
    if ($msg !== '' && preg_match($LEAD_JUNK_MSG, $msg)) {
        $junk++;
        if (!$dry) $upd->execute(['junk-message', null, $r['id']]);
        continue;
    }
    $hay = $r['m'] . ' ' . $r['p'];
    $hits = [];
    foreach ($LEAD_TAGS as $tag => $rx)
        if (preg_match($rx, $hay)) { $hits[] = $tag; $counts[$tag] = ($counts[$tag] ?? 0) + 1; }

    $lang = null;
    $langhay = $r['m'] . ' ' . ($inbound[$r['id']] ?? '');
    $best = 0;
    foreach ($LEAD_LANG as $code => $rx) {
        /* Score, do not first-match: a mail can carry a stray "hola" and
           several of these languages share words ("hallo" is de and nl). */
        $n = preg_match_all($rx, $langhay);
        if ($n > $best) { $best = $n; $lang = $code; }
    }
    if ($lang !== null && $best >= 2) $langs[$lang] = ($langs[$lang] ?? 0) + 1;
    else $lang = null;   /* one stray word is not a language */

    if (!$dry) $upd->execute([$hits ? implode(',', $hits) : null, $lang, $r['id']]);
    if ($hits) $tagged++;
    if (array_intersect($hits, $LEAD_HOT)) $hot[] = $r['id'];
}

printf("%s%d of %d leads tagged, %d junk messages\n", $dry ? 'DRY: ' : '', $tagged, count($rows), $junk);
arsort($counts);
foreach ($counts as $t => $n) printf("   %-11s %d\n", $t, $n);
if ($langs) { echo "   reply language: ";
    foreach ($langs as $c => $n) echo "$c=$n "; echo "\n"; }
printf("   needs a human today (%s): %s\n", implode('/', $LEAD_HOT), implode(' ', $hot) ?: 'none');

/* ---- backlog onto the page that already exists ------------------------- */
if (!$seed) { echo "\n(not seeding follow-up dates; pass --seed-followups)\n"; exit; }

$back = $db->query("SELECT id FROM leads
    WHERE next_follow_up IS NULL
      AND status NOT IN ('won','lost','spam')
      AND email IS NOT NULL AND email <> ''
    ORDER BY created_at ASC")->fetchAll();
$per  = 12;   /* a day's outreach, not a wall of 81 */
$setd = $dry ? null : $db->prepare('UPDATE leads SET next_follow_up = ? WHERE id = ? AND next_follow_up IS NULL');
$byday = [];
foreach ($back as $i => $b) {
    $day  = intdiv($i, $per);
    $date = date('Y-m-d', strtotime("+$day weekday"));
    if (!$dry) $setd->execute([$date, $b['id']]);
    $byday[$date] = ($byday[$date] ?? 0) + 1;
}
printf("\n%s%d backlog leads over %d working days, oldest first:\n",
       $dry ? 'DRY: ' : 'scheduled: ', count($back), count($byday));
foreach ($byday as $d => $n) printf("   %s  %d\n", $d, $n);
