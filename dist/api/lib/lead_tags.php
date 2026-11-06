<?php
/**
 * The lead-interest vocabulary: what each enquiry actually asks for.
 *
 * DERIVED BY READING EVERY STORED ENQUIRY, NOT GUESSED. The first draft of
 * this table scored 61 of 87 and missed six real shapes, including the two
 * that matter most commercially:
 *   - enquiries asking for less than MOQ without ever using the term,
 *     phrased instead as a piece range, a trial run, or simply less than
 *     the usual minimum - the question the 20-piece headline answers;
 *   - first-time founders with no order yet, who describe starting a brand
 *     or looking for a manufacturer rather than asking a spec question.
 * Both are now tagged. Order matters only for display; a lead carries all
 * the tags it matches.
 *
 * No customer wording is reproduced in this file. The alternations below
 * are generic trade vocabulary, chosen to cover those shapes.
 *
 * MATCHES ON WORD BOUNDARIES, and each pattern was checked against the
 * corpus rather than assumed: 'ship' would otherwise hit "relationship",
 * 'patch' would hit "dispatch", and 'oz' would hit nothing because
 * "Fabrioza" has no boundary before it. Verified by printing every matched
 * substring, not just the counts.
 */
$LEAD_TAGS = [
  /* Mid-transaction. Top of any worklist: the money is already agreed. */
  'payment'   => '/\b(payment|invoice|deposit|stripe|paypal|bank transfer|wire|'
               . 'proceed with|pay(ing)? (the|for)|checkout|payment link)\b/i',
  /* Asking for less than MOQ. None of these says "MOQ". */
  'low_qty'   => '/\b(less than|smaller (initial |first )?order|small(er)? quantit|'
               . 'lower quantit|20 ?- ?30|10 ?- ?20|start small|trial order|'
               . 'test order|below your|under your|as few as|first trial)\b/i',
  /* First-time brands - the segment the 20-piece tier exists for. */
  'startup'   => '/\b(start(ing|ed)? ?(up)? ?(a|my|an|own)? ?(small|own|new)? ?'
               . '(clothing|apparel|menswear|womenswear|fashion|streetwear)? ?'
               . '(brand|line|business|label|company)|new brand|'
               . 'first (order|collection|product|time|batch)|'
               . 'looking for a (manufactur|supplier|vendor|factory|partner)|'
               . 'need a (vendor|manufactur|supplier|factory)|'
               . 'brand identity|launch(ing)? (my|a)|my clothing (business|brand|line)|'
               . 'interested in working with)\b/i',
  'sample'    => '/\b(sample|samples|sampling|prototype|pre-?production)\b/i',
  'pricing'   => '/\b(price|prices|pricing|quote|quoted|quotation|cost|costs|'
               . 'rate|rates|how much)\b/i',
  'moq'       => '/\b(moq|minimum order|minimum quantity|smallest order)\b/i',
  'gsm'       => '/\b(gsm|g\/m2|gramm?age|fabric weight|heavyweight|oz)\b/i',
  'fabric'    => '/\b(fabric|texture|material|cotton|fleece|polyester|velour|blend|'
               . 'french terry|jersey|twill|knit)\b/i',
  'label'     => '/\b(private label|white label|woven label|hang ?tag|branding|'
               . 'custom label)\b/i',
  'shipping'  => '/\b(ship|shipping|shipment|freight|ddp|delivery|customs|duty|import|'
               . 'send to (my|me)|do you send|export to|deliver to)\b/i',
  'design'    => '/\b(tech ?pack|design|artwork|mockup|pattern|cad)\b/i',
  'decoration'=> '/\b(embroider\w*|screen ?print\w*|sublimat\w*|dtf|dtg|puff|patch\w*)\b/i',
  'bulk'      => '/\b(bulk|large order|container|wholesale|ongoing|long.?term|partner\w*)\b/i',
];

/**
 * Not an enquiry at all: calendar notifications that landed in the inbox
 * importer, and the go-live test row. Tagging these "wants pricing" would
 * put junk at the top of a worklist.
 * NOTE this classifies the MESSAGE, never the lead - one of these rows is
 * a genuine quoted customer whose stored message happens to be calendar
 * noise, with the real conversation in the notes.
 */
$LEAD_JUNK_MSG = '/^(Accepted|Declined|Invitation|Tentative):|'
               . 'CRM go-live verification|Content-Transfer-Encoding: base64/i';

/** Reply language, so a follow-up is not written in the wrong one. */
$LEAD_LANG = [
  'fr' => '/\b(bonjour|merci|cordialement|je suis|votre|nous|s\'il vous)\b/i',
  'es' => '/\b(hola|gracias|saludos|quisiera|necesito|buenos d[ií]as|me gustar[ií]a)\b/i',
  'de' => '/\b(hallo|danke|freundlichen gr[uü]|ich m[oö]chte|sehr geehrte)\b/i',
  'nl' => '/\b(hallo|bedankt|graag|met vriendelijke groet|wij zijn)\b/i',
];

/** Tags worth a human reading the mail today, in priority order. */
$LEAD_HOT = ['payment', 'low_qty'];

/**
 * The tags a message earns, as a comma-separated string, or null.
 *
 * Both callers go through this - tools/classify-leads.php for the existing
 * rows and the inbox importer for new mail - so the vocabulary and the
 * matching live in exactly one place. An earlier version had the loop
 * written out at each call site, which is how the two drifted apart in the
 * navigation menus and in the shells.
 *
 * $message is the CUSTOMER'S words only. Never pass our own notes: it
 * changes the meaning of a tag from "they asked for this" to "this came up".
 */
function lead_interests(string $message, string $product_type = ''): ?string
{
    global $LEAD_TAGS, $LEAD_JUNK_MSG;
    $msg = trim($message);
    if ($msg !== '' && preg_match($LEAD_JUNK_MSG, $msg)) return 'junk-message';
    $hay  = $message . ' ' . $product_type;
    $hits = [];
    foreach ($LEAD_TAGS as $tag => $rx) if (preg_match($rx, $hay)) $hits[] = $tag;
    return $hits ? implode(',', $hits) : null;
}

/** Reply language from whatever text is available, or null. */
function lead_reply_lang(string $text): ?string
{
    global $LEAD_LANG;
    $best = 0; $lang = null;
    foreach ($LEAD_LANG as $code => $rx) {
        $n = preg_match_all($rx, $text);
        if ($n > $best) { $best = $n; $lang = $code; }
    }
    /* One stray word is not a language: "hola" appears in plenty of
       English mail and "hallo" belongs to two of these languages. */
    return $best >= 2 ? $lang : null;
}
