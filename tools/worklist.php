<?php
/**
 * The follow-up worklist, as one readable document.
 *
 * READ-ONLY. Emits Markdown on stdout and writes nothing, anywhere, so it
 * is safe to re-run whenever you want the current picture.
 *
 * Ranked by what the lead needs, not by date. A customer stopped at the
 * payment screen and a blank form submission are both "new" in the CRM,
 * and sorting by created_at puts them next to each other.
 */
$db = new PDO('sqlite:/var/data/fabrioza_crm.db');
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$today = new DateTime('today');
$nl = PHP_EOL;

/* ---- gather ------------------------------------------------------------ */
$leads = $db->query("SELECT * FROM leads ORDER BY id")->fetchAll();

$mail = [];
foreach ($db->query("SELECT lead_id,
        SUM(status='sent')   ok,
        SUM(status='failed') bad,
        MAX(CASE WHEN status='sent' THEN sent_at END) last_ok
      FROM email_log GROUP BY lead_id") as $r) {
    $mail[$r['lead_id']] = $r;
}

$reply = [];
foreach ($db->query("SELECT lead_id, COUNT(*) n, MAX(created_at) last
      FROM notes WHERE author='inbox-import' GROUP BY lead_id") as $r) {
    $reply[$r['lead_id']] = $r;
}

$inote = [];
foreach ($db->query("SELECT lead_id, COUNT(*) n FROM notes
      WHERE author <> 'inbox-import' GROUP BY lead_id") as $r) {
    $inote[$r['lead_id']] = $r['n'];
}

/* ---- helpers ----------------------------------------------------------- */
/* Country from the email ccTLD. The form leaves `country` blank on 82 of 87
   rows, while a business address on a country TLD plainly indicates one -
   and UK buyers are the current priority. Inferred here, never stored: a
   generic TLD tells you nothing, and a stored guess would go stale
   without anything noticing. */
$CC = ['co.uk'=>'UK','org.uk'=>'UK','uk'=>'UK','es'=>'Spain','nl'=>'Netherlands',
       'de'=>'Germany','fr'=>'France','it'=>'Italy','ie'=>'Ireland','au'=>'Australia',
       'ca'=>'Canada','nz'=>'New Zealand','za'=>'South Africa','ae'=>'UAE','se'=>'Sweden'];

function geo(array $L, array $CC) {
    if (trim((string)$L['country']) !== '') return $L['country'];
    $at = strrchr((string)$L['email'], '@');
    $host = $at === false ? '' : strtolower(substr($at, 1));
    foreach ($CC as $tld => $name) {
        $suffix = '.' . $tld;
        if (strlen($host) > strlen($suffix) && substr($host, -strlen($suffix)) === $suffix) {
            return $name . '?';
        }
    }
    return '';
}

function age_days($ts, DateTime $today) {
    if (!$ts) return null;
    $d = new DateTime(substr($ts, 0, 10));
    return (int) $d->diff($today)->format('%a');
}

/* Their own words, flattened. Quoting the customer beats any summary: it is
   the thing you have to answer, and it is already written. */
function says($m, $max = 230) {
    $m = html_entity_decode((string)$m, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $m = trim(preg_replace('/\s+/', ' ', $m));
    if ($m === '') return '_(no message - the form was submitted empty)_';
    if (mb_strlen($m) > $max) $m = mb_substr($m, 0, $max) . '...';
    return '"' . $m . '"';
}

/* ---- bucket ------------------------------------------------------------ */
$B = ['act'=>[], 'talking'=>[], 'unreached'=>[], 'quiet'=>[], 'warm'=>[], 'thin'=>[]];
foreach ($leads as $L) {
    $id   = $L['id'];
    $tags = array_values(array_filter(explode(',', (string)$L['interests'])));
    $m    = isset($mail[$id]) ? $mail[$id] : ['ok'=>0, 'bad'=>0, 'last_ok'=>null];

    $L['_tags']  = $tags;
    $L['_mail']  = $m;
    $L['_reply'] = isset($reply[$id]) ? $reply[$id] : null;
    $L['_notes'] = isset($inote[$id]) ? $inote[$id] : 0;
    $L['_geo']   = geo($L, $CC);
    $L['_age']   = age_days($L['created_at'], $today);

    $hot      = count(array_intersect($tags, ['payment', 'low_qty'])) > 0;
    $unreach  = ($m['ok'] == 0 && $m['bad'] > 0);
    $gotreply = ($L['_reply'] !== null);
    $thin     = (mb_strlen(trim((string)$L['message'])) < 30 && count($tags) === 0);

    /* "Quoted" stopped meaning what it used to. Any inbound reply
       promotes a lead to it, so after the mailbox import 49 leads carried
       that status and ACT TODAY held 55 people - not a day's work, and a
       list nobody can act on is the same as no list. A decision waiting on
       you (payment, or an order below MOQ) is a different job from a
       conversation already running. */
    if ($hot)                              $B['act'][]       = $L;
    elseif ($L['status'] === 'quoted')     $B['talking'][]   = $L;
    elseif ($unreach)                      $B['unreached'][] = $L;
    elseif ($gotreply)                     $B['quiet'][]     = $L;
    elseif ($thin)                         $B['thin'][]      = $L;
    else                                   $B['warm'][]      = $L;
}

/* ---- render ------------------------------------------------------------ */
echo '# FABRIOZA - follow-up worklist', $nl, $nl;
echo 'Generated ', date('j M Y H:i'), ' from the live CRM (', count($leads),
     ' leads). Read-only snapshot - re-run to refresh.', $nl, $nl;

/* Count the CONDITION over every lead, not the size of the bucket below.
   A never-reached lead that is ALSO at the payment step is promoted into
   ACT TODAY and leaves the unreached bucket, so the bucket understates it.
   The first version of this line printed the bucket and was short by one. */
$never = 0;
foreach ($leads as $L) {
    $m = isset($mail[$L['id']]) ? $mail[$L['id']] : null;
    if ($m && $m['ok'] == 0 && $m['bad'] > 0) $never++;
}
echo '## Read this first', $nl, $nl;
echo '**', $never, ' people never received anything from us.** ',
     'Every send to them failed. The mail server could not connect or authenticate ',
     'from **8 Sep to 30 Oct 2026** - 110 of 315 sends failed in that window. ',
     'They submitted the form, read "we will respond within 24 hours", and heard nothing.', $nl, $nl;

/* Resubmissions, counted rather than named. People who heard nothing filled
   the form in again, which is the clearest evidence the silence was felt -
   and it is also why the lead table has near-duplicate rows. Derived at run
   time: an earlier version of this paragraph hard-coded three customers by
   name, which put their names in the repo and would have gone stale the
   moment anyone else resubmitted. */
$dupes = $db->query("SELECT email, COUNT(*) n FROM leads
    WHERE email IS NOT NULL AND email <> ''
    GROUP BY lower(email) HAVING n > 1 ORDER BY n DESC")->fetchAll();
if ($dupes) {
    $extra = 0;
    foreach ($dupes as $d) $extra += ($d['n'] - 1);
    echo '**', count($dupes), ' people submitted the form more than once** (',
         $extra, ' duplicate rows in total, up to ', $dupes[0]['n'],
         ' from one person). Silence is why: with no acknowledgement arriving, ',
         'they assumed the form had not worked. Treat each set as one lead.', $nl, $nl;
}
echo 'Mail works again now - acknowledgements go out through Resend and are succeeding. ',
     'But nothing retried the backlog, so those people are still owed a first reply.', $nl, $nl;

$heads = [
  'act' => ['ACT TODAY - a decision is waiting on you',
    'At the payment step, or asking for an order below MOQ. These are the ones where a reply converts.'],
  'talking' => ['IN CONVERSATION',
    'They have written to us and the thread is open. Pick up whichever has been quiet longest.'],
  'unreached' => ['NEVER REACHED - owed a first reply',
    'Every email to them failed. Send the acknowledgement they never got, and apologise for the delay.'],
  'quiet' => ['REPLIED, THEN WENT QUIET',
    'They wrote back at least once, so a real conversation exists. Pick it up.'],
  'warm' => ['WARM - enquiry received, no human answer yet',
    'They got the automatic acknowledgement and the day-3 sequence. Nobody has answered what they actually asked.'],
  'thin' => ['THIN - little or nothing said',
    'Blank or near-blank submissions. Ask one short qualifying question, then drop if silent.'],
];

foreach ($heads as $k => $h) {
    $rows = $B[$k];
    if (!$rows) continue;
    echo '---', $nl, $nl, '## ', $h[0], ' (', count($rows), ')', $nl, $nl, '_', $h[1], '_', $nl, $nl;
    foreach ($rows as $L) {
        $meta = [];
        if ($L['_geo'] !== '')                      $meta[] = $L['_geo'];
        if ($L['reply_lang'])                       $meta[] = 'reply in ' . strtoupper($L['reply_lang']);
        if (trim((string)$L['product_type']) !== '')$meta[] = html_entity_decode($L['product_type'], ENT_QUOTES|ENT_HTML5, 'UTF-8');
        if (trim((string)$L['quantity']) !== '')    $meta[] = 'qty ' . $L['quantity'];
        $meta[] = $L['_age'] . 'd old';
        $meta[] = 'status ' . $L['status'];

        $m = $L['_mail'];
        $state = [];
        if ($m['ok'] > 0)  $state[] = $m['ok'] . ' email(s) delivered, last ' . substr($m['last_ok'], 0, 10);
        if ($m['bad'] > 0) $state[] = '**' . $m['bad'] . ' FAILED**';
        if ($m['ok'] == 0 && $m['bad'] == 0) $state[] = '**never emailed from the CRM**';
        /* Says it plainly wherever the lead ended up - a reader scanning
           ACT TODAY must not have to infer it from the FAILED count. */
        if ($m['ok'] == 0 && $m['bad'] > 0) $state[] = '**NEVER RECEIVED ANYTHING**';
        if ($L['_reply'])  $state[] = '**' . $L['_reply']['n'] . ' reply(ies) from them**, last ' . substr($L['_reply']['last'], 0, 10);
        if ($L['_notes'])  $state[] = $L['_notes'] . ' internal note(s)';

        echo '### #', $L['id'], ' ', ($L['name'] !== '' ? $L['name'] : '(no name)'), $nl;
        echo '`', $L['email'], '`';
        if (trim((string)$L['company']) !== '') echo ' - ' . $L['company'];
        echo $nl, $nl;
        echo '- ', implode(' | ', $meta), $nl;
        $shown = array_values(array_diff($L['_tags'], ['junk-message']));
        if ($shown) echo '- **Wants:** ', implode(', ', $shown), $nl;
        echo '- ', implode(' | ', $state), $nl;
        if (in_array('junk-message', $L['_tags'], true)) {
            echo '- _The stored message is a calendar/system email, not an enquiry._',
                 ($L['_reply'] ? ' **The real conversation is in the replies above - open the lead.**' : ''), $nl, $nl;
        } else {
            echo '- They said: ', says($L['message']), $nl, $nl;
        }
    }
}

/* ---- the asks, counted ------------------------------------------------- */
echo '---', $nl, $nl, '## What people are asking for', $nl, $nl;
echo '| Ask | Leads |', $nl, '|---|---|', $nl;
$c = [];
foreach ($leads as $L) {
    foreach (array_filter(explode(',', (string)$L['interests'])) as $t) {
        $c[$t] = (isset($c[$t]) ? $c[$t] : 0) + 1;
    }
}
arsort($c);
$LBL = [
  'payment'=>'At the payment step', 'low_qty'=>'Wants fewer than MOQ / a trial run',
  'startup'=>'Starting a brand (first order)', 'sample'=>'Wants a sample',
  'pricing'=>'Wants prices or a quote', 'moq'=>'Asked about MOQ',
  'gsm'=>'Asked about fabric weight / GSM', 'fabric'=>'Asked about fabric',
  'label'=>'Private label / branding', 'shipping'=>'Shipping, duty or delivery',
  'design'=>'Sent or needs a tech pack', 'decoration'=>'Embroidery / print',
  'bulk'=>'Bulk or long-term supply', 'junk-message'=>'(not an enquiry)',
];
foreach ($c as $t => $n) {
    echo '| ', (isset($LBL[$t]) ? $LBL[$t] : $t), ' | ', $n, ' |', $nl;
}
echo $nl, '_A lead carries every tag it matches, so these do not sum to ', count($leads), '._', $nl;
