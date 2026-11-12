<?php
/**
 * Build the follow-up worklist for leads that have never been actioned.
 *
 *   docker exec -i fabrioza-web php < build-followup-worklist.php > FOLLOWUP-WORKLIST.md
 *
 * CONTAINS CUSTOMER NAMES AND EMAIL ADDRESSES. The output file is in
 * .gitignore (line 22) and must stay there. This script is committed; its
 * output never is.
 *
 * It SENDS NOTHING and WRITES NOTHING to the database. It reads the leads
 * table and prints a plan.
 *
 * WHY: measured 12 Nov 2026 - 224 leads, 128 still status="new" and never
 * touched, 180 follow-ups due or overdue, 1 won. The constraint is not
 * traffic and not the website; it is that nobody has replied to the people
 * who already asked.
 *
 * The playbook each lead gets is decided by what they actually told us,
 * because that is what decides whether the reply can contain a PRICE:
 *
 *   A QUOTE NOW   product + quantity known -> send a real number today.
 *                 Nothing else needs to happen first.
 *   B PRICE BAND  product known, no quantity -> send the band for that
 *                 item and ask the one question that unlocks a firm price.
 *   C QUALIFY     no product named -> ask what they are making. Lead with
 *                 MOQ from 20, which is the thing that makes this factory
 *                 different from the ones that ignored them.
 *   D SAMPLE      they asked about a trial/sample order - different flow.
 *   E CHECK       chat capture, meeting RSVP, or a low score - read it
 *                 before spending a reply on it.
 */

$db = new PDO('sqlite:' . (getenv('CRM_DB') ?: '/var/data/fabrioza_crm.db'));
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

/* Live price list, read off /pricing on 12 Nov 2026. Columns are the MOQ
   tier, 100-499, then 500+. These go in the reply so the follow-up carries
   a number instead of "we will get back to you". */
$PRICES = [
    'tee'        => ['Heavyweight T-Shirt (180-220gsm)',   '50 pcs',  '$11.40', '$9.50',  '$8.55'],
    'hoodie'     => ['Premium Hoodie (280-350gsm)',        '50 pcs',  '$19.20', '$16.00', '$14.40'],
    'crewneck'   => ['Crewneck Sweatshirt (260-300gsm)',   '50 pcs',  '$18.00', '$15.00', '$13.50'],
    'jersey'     => ['Sports Jersey (sublimated)',         '20 sets', '$18.00', '$18.00', '$16.20'],
    'soccer'     => ['Soccer Kit (full set)',              '15 sets', '$20.00', '$20.00', '$18.00'],
    'tracksuit'  => ['Tracksuit Set',                      '50 sets', '$26.40', '$22.00', '$19.80'],
    'activewear' => ['Gym / Activewear Set',               '50 sets', '$18.00', '$15.00', '$13.50'],
    'polo'       => ['Corporate Polo Shirt',               '50 pcs',  '$15.60', '$13.00', '$11.70'],
    'jacket'     => ['Bomber / Varsity Jacket',            '50 pcs',  '$38.40', '$32.00', '$28.80'],
    'cap'        => ['Baseball Cap (3D puff)',             '100 pcs', '-',      '$8.50',  '$7.65'],
    'streetwear' => ['Streetwear Acid Wash Tee',           '50 pcs',  '$13.20', '$11.00', '$9.90'],
    'windbreak'  => ['Windbreaker Jacket',                 '50 pcs',  '$22.80', '$19.00', '$17.10'],
    'cutsew'     => ['Cut & Sew Custom Design',            '100 pcs', '-',      '$12.00+', '$10.80+'],
    'cycling'    => ['Cycling Jersey',                     '50 pcs',  '$22.80', '$19.00', '$17.10'],
];

/* Order matters: the first match wins, so the more specific words come
   first. "tracksuit" before "jacket", "soccer" before "jersey". */
$MATCH = [
    'tracksuit'  => ['tracksuit', 'track suit', 'shell suit'],
    'soccer'     => ['soccer', 'football kit', 'football uniform'],
    'cycling'    => ['cycling', 'cyclist', 'bib'],
    'activewear' => ['activewear', 'gym', 'legging', 'compression', 'yoga', 'sportswear set'],
    'hoodie'     => ['hoodie', 'hooded'],
    'crewneck'   => ['crewneck', 'crew neck', 'sweatshirt'],
    'polo'       => ['polo'],
    'cap'        => ['cap', 'hat', 'beanie'],
    'jacket'     => ['varsity', 'bomber', 'letterman'],
    'windbreak'  => ['windbreaker', 'windcheater', 'rain jacket'],
    'streetwear' => ['streetwear', 'acid wash', 'oversized'],
    'jersey'     => ['jersey', 'kit', 'uniform', 'basketball', 'baseball', 'rugby', 'cricket'],
    'tee'        => ['t-shirt', 'tshirt', 't shirt', 'tee', 'shirt'],
    'cutsew'     => ['cut and sew', 'cut & sew', 'cut-and-sew', 'custom design'],
];

function matchProduct(string $text, array $MATCH): ?string {
    $t = mb_strtolower($text);
    foreach ($MATCH as $key => $words) {
        foreach ($words as $w) { if (strpos($t, $w) !== false) { return $key; } }
    }
    return null;
}

$rows = $db->query("SELECT id, created_at, name, email, company, country, product_type,
                           quantity, message, form_type, source_page, lead_score
                    FROM leads WHERE status = 'new'
                    ORDER BY lead_score DESC, created_at DESC")->fetchAll(PDO::FETCH_ASSOC);

$buckets = ['A' => [], 'B' => [], 'C' => [], 'D' => [], 'E' => []];

foreach ($rows as $r) {
    $age = (int)floor((time() - strtotime($r['created_at'])) / 86400);
    $prod = trim((string)$r['product_type']);
    $qty  = trim((string)$r['quantity']);
    $ft   = (string)$r['form_type'];
    $key  = matchProduct($prod . ' ' . (string)$r['message'], $MATCH);

    /* D is form_type ONLY. The first version also matched any message
       containing the word "sample" and put 60 of 128 leads here - 58 of
       them wrongly. "sample order" matches zero leads; what the word
       actually catches is our own boilerplate quoted back inside imported
       email threads, MIME headers, and enquiries like "I would like to
       SEND IN a sample to have it replicated" - a buyer sending US a
       garment to copy, which is a cut-and-sew job and the opposite of a
       trial order. Misfiling a lead into the wrong playbook is worse than
       leaving it unclassified: it gets the wrong reply. */
    if ($ft === 'Trial Order') {
        $b = 'D';
    } elseif ($ft === 'Chat Lead Capture' || $ft === 'Meeting RSVP' || (int)$r['lead_score'] < 15) {
        $b = 'E';
    } elseif ($prod !== '' && $qty !== '') {
        $b = 'A';
    } elseif ($prod !== '' || $key) {
        $b = 'B';
    } else {
        $b = 'C';
    }

    $r['_age'] = $age;
    $r['_key'] = $key;
    $buckets[$b][] = $r;
}

/* ------------------------------------------------------------------ */
function esc(string $s): string { return str_replace('|', '\\|', trim($s)); }

function priceLine(?string $key, array $PRICES): string {
    if (!$key || !isset($PRICES[$key])) {
        return 'No price table match - quote from /pricing by hand.';
    }
    [$label, $moq, $p1, $p2, $p3] = $PRICES[$key];
    return sprintf('**%s** - MOQ %s. %s at MOQ tier, %s at 100-499, %s at 500+.',
        $label, $moq, $p1, $p2, $p3);
}

function ageNote(int $age): string {
    if ($age <= 7)  { return 'Fresh - reply today, they are still shopping.'; }
    if ($age <= 30) { return 'Open with an apology for the delay, then the price.'; }
    if ($age <= 90) { return 'Re-engage: assume they have other quotes. Lead with the number.'; }
    return 'Cold. One short message, no essay. Ask if the project is still live.';
}

$today = gmdate('Y-m-d');
echo "# FABRIOZA - follow-up worklist\n\n";
echo "Generated $today by `backend-scripts/build-followup-worklist.php`.\n";
echo "**Contains customer contact details - this file is gitignored. Do not commit it.**\n\n";
echo "Nothing has been sent. This is a plan.\n\n";
echo "## Why this list exists\n\n";
echo "- **" . count($rows) . " leads** are still `new` and have never been actioned\n";
echo "- 224 leads in total, **1 won**\n";
echo "- The site is fast, consistent, and captures quotes on every page. None of that earns\n";
echo "  anything until someone replies to the people who already asked.\n\n";
echo "## How to work it\n\n";
echo "Top to bottom. Group A first - those are the ones you can answer with a price today,\n";
echo "which is the only reply that moves a B2B enquiry forward. Do not start at the newest.\n\n";
echo "| group | what it is | count |\n|---|---|---|\n";
echo "| A | **Quote now** - product and quantity both known | " . count($buckets['A']) . " |\n";
echo "| B | **Price band** - product known, quantity missing | " . count($buckets['B']) . " |\n";
echo "| C | **Qualify** - no product named yet | " . count($buckets['C']) . " |\n";
echo "| D | **Sample / trial order** | " . count($buckets['D']) . " |\n";
echo "| E | **Read before replying** - chat capture, RSVP, or low score | " . count($buckets['E']) . " |\n\n";

$titles = [
    'A' => 'A. Quote now - send a real number today',
    'B' => 'B. Send the price band, ask the one missing question',
    'C' => 'C. Qualify - find out what they are making',
    'D' => 'D. Sample / trial order',
    'E' => 'E. Read these before spending a reply',
];
$intro = [
    'A' => "They told us the product AND the quantity. There is nothing to ask first - a price\nis the reply. Anything less wastes the strongest leads on the list.",
    'B' => "The product is known, the quantity is not. Send the band for that item so the message\ncarries a number, then ask quantity - that one answer unlocks a firm quote.",
    'C' => "No product named. Ask what they are making, and lead with MOQ from 20 pieces: that is\nthe thing that separates this factory from the ones that ignored them.",
    'D' => "They asked about samples or a trial order. Send the trial terms and the sample lead\ntime, not a bulk price list.",
    'E' => "Chat captures, meeting RSVPs and low-scoring rows. Read the message first - some of\nthese are not real enquiries, and a reply spent here is a reply not spent on group A.",
];

foreach (['A', 'B', 'C', 'D', 'E'] as $b) {
    if (!$buckets[$b]) { continue; }
    echo "\n---\n\n## " . $titles[$b] . " (" . count($buckets[$b]) . ")\n\n";
    echo $intro[$b] . "\n\n";
    foreach ($buckets[$b] as $r) {
        $who = $r['name'] !== '' ? $r['name'] : '(no name given)';
        echo "### #" . $r['id'] . " - " . esc($who);
        if (trim((string)$r['company']) !== '') { echo " - " . esc($r['company']); }
        echo "\n\n";
        echo "- **Email:** " . esc($r['email']) . "\n";
        echo "- **Asked:** " . ($r['_age']) . " days ago";
        if (trim((string)$r['country']) !== '') { echo " - from " . esc($r['country']); }
        echo " - score " . (int)$r['lead_score'] . "\n";
        if (trim((string)$r['product_type']) !== '') { echo "- **Wants:** " . esc($r['product_type']) . "\n"; }
        if (trim((string)$r['quantity']) !== '')     { echo "- **Quantity:** " . esc($r['quantity']) . "\n"; }
        if (trim((string)$r['source_page']) !== '')  { echo "- **Came from:** " . esc($r['source_page']) . "\n"; }
        $msg = trim(preg_replace('/\s+/', ' ', (string)$r['message']));
        if ($msg !== '') { echo "- **They said:** " . esc(mb_substr($msg, 0, 240)) . (mb_strlen($msg) > 240 ? '...' : '') . "\n"; }
        echo "\n**Send:** ";
        if ($b === 'A' || $b === 'B') {
            echo priceLine($r['_key'], $PRICES) . "\n";
            if ($b === 'B') { echo "  Then ask: *how many pieces per design?* - that is the only thing missing.\n"; }
        } elseif ($b === 'C') {
            echo "What are you looking to make, and roughly how many? We start at **20 pieces per style**,\n";
            echo "  so a first run does not need to be big. Include the /moq-calculator link.\n";
        } elseif ($b === 'D') {
            echo "Trial order terms and sample lead time. Point at /sample-order.\n";
        } else {
            echo "Read the message above first. If it is real, move it into group A, B or C.\n";
        }
        echo "  *" . ageNote($r['_age']) . "*\n\n";
    }
}

echo "\n---\n\n";
echo "## After you send\n\n";
echo "Mark each one in the admin so it leaves this list: **fabrioza.com/admin/lead.php?id=<id>**\n";
echo "Set the status to `quoted` and give it a next follow-up date. A lead left on `new`\n";
echo "after you have replied to it will show up here again next time.\n";
