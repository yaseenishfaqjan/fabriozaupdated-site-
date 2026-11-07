<?php
/**
 * The inbox skip rules, tested in BOTH directions.
 *
 * A filter is only half-tested when you check that it drops the junk. The
 * expensive failure is the other way: a rule that quietly eats a customer.
 * A skip is permanent here - the Message-ID goes into mail_seen and the
 * message is never looked at again - so a false positive loses a lead and
 * nothing anywhere shows it happened.
 *
 * The subjects below follow the SHAPES found in the real mailbox with
 * invented brand names substituted, so the rules are exercised without
 * customer identities entering the repo.
 *
 *   php tools/test-inbox-filters.php
 */
$src = file_get_contents(__DIR__ . '/../dist/api/import-inbox.php');

/* Pull the patterns out of the importer rather than restating them. A copy
   here would drift from the real rule and pass for ever afterwards. */
foreach (['SKIP_SUBJECTS', 'SKIP_SUBJECT_ANY', 'SKIP_PITCH', 'SKIP_PITCH_ANYWHERE'] as $name) {
    if (!preg_match('/\$' . $name . ' = (.*?);\n/s', $src, $m)) {
        fwrite(STDERR, "could not find \$$name in import-inbox.php\n");
        exit(1);
    }
    eval('$' . $name . ' = ' . $m[1] . ';');
}

function dropped(string $s): bool
{
    global $SKIP_SUBJECTS, $SKIP_SUBJECT_ANY, $SKIP_PITCH;
    global $SKIP_PITCH_ANYWHERE;
    return (bool) (preg_match($SKIP_SUBJECTS, $s)
                || preg_match($SKIP_SUBJECT_ANY, $s)
                || preg_match($SKIP_PITCH, $s)
                || preg_match($SKIP_PITCH_ANYWHERE, $s));
}

/* Must be dropped: cold outreach selling us a service, autoresponders,
   and mail the hosting spam filter already tagged. */
$DROP = [
    '***SPAM***  Custom Heavyweight T-Shirt Manufacturing',
    '[Request received]',
    'We will get back to you! Re: a better way to train',
    'SEO+AI 2026?',
    'WEBSITE DEVELOPMENT',
    'I want to connect',
    'Call',
    'Re: SEO RANK TOP SERVICE PACKAGE ?',
    'Empower Your Business',
    'Developers and Designers ?',
    'Re: 1st page..',
    'Rw: Website Review & Suggestions!',
    'Page 1 discussion',
    'Improvement Ideas',
    'Re: Rebuild Your Website',
    'Web issues ///',
    "Re:  Let's talk App",
    'RE: increase business',
    'Rate Promo Store',
    'Business Financing Inquiry',
    'Automatic reply: out today',
    'Quick Follow-Up -SEO Minimal Proposal',
    'Website Fix',
    'Mobile app development',
    'Make sure your site is crawl-able by search engine',
    'Smart Mobile Solutions for Your Business',
    'Accepted: a meeting and Fabrioza',
];

/* Must survive. Every shape here is a real enquiry pattern. */
$KEEP = [
    'NORTHFORM - Free Branded Sample & Manufacturing Inquiry',
    'Premium Luxury Apparel Manufacturing Inquiry - AURELIX',
    'HALEWOOD - quotation request: custom tracksuit + tee',
    'Sample + bulk quote: custom quarter zip (photo attached)',
    'RIVER STAG CO - Private Label Outdoor',
    'Moorside - 4 Tech Packs | Custom Manufacturing Quote Request',
    'Sample development request - 23 pieces for a fashion show',
    'DEEPWATER - Manufacturing inquiry | 240 GSM DTG T-shirts',
    'Quote request: custom French terry hoodie and crewneck, 25',
    'Manufacturing Partnership Inquiry - Harbour Line NYC',
    'Custom 530 GSM Premium Hoodie Manufacturing Inquiry',
    'Low-MOQ Sampling Inquiry - Organic Cotton Henley + Linen',
    'Oakfield LLC - Custom Clothing Manufacturing Inquiry',
    'Inquiry: Seamless Activewear and Streetwear Manufacturing',
    'Stateuni Motorsports T-Shirt Print Test',
    'Custom Zip-Up Hoodie Manufacturing Inquiry | Vantage Points',
    'team uniform designs and sizes',
    'Re: Custom Soccer Kits - Home and Away',
    'Mijn ontwerpen',
    'Bag specification review',
    'Apparel Business Information',
    'Message: looking for vender',
    'Custom Sportswear Manufacturing for Your Brand',
    'Re: Re: Sample - Design & Fabric Updates',
    'Custom Baggy Track Pants Quote Request',
    'Preliminary RFQ | Collection',
    /* NEAR-MISSES. Each contains a word the pitch rule looks for, WITHOUT
       the sales framing, and a real customer could write any of them.
       These are the cases that make the rule narrow instead of greedy. */
    'Can you print the artwork from our website?',
    'Our designers sent over the tech pack',
    'Website for our brand launch - need hoodies to match',
    'Call sheet for the sample review',
];

$fail = 0;
foreach ($DROP as $s) {
    if (!dropped($s)) { printf("NOT DROPPED (pitch/auto): %s%s", $s, PHP_EOL); $fail++; }
}
foreach ($KEEP as $s) {
    if (dropped($s)) { printf("WRONGLY DROPPED (real enquiry): %s%s", $s, PHP_EOL); $fail++; }
}
printf('%s%d pitches, %d real enquiries, %d mistake(s)%s',
    PHP_EOL, count($DROP), count($KEEP), $fail, PHP_EOL);
exit($fail > 0 ? 1 : 0);
