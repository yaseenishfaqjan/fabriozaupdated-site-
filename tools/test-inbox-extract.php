<?php
/**
 * Tests for dist/api/lib/inbox_extract.php.
 *
 * Runs with no mailbox and no network: the mailbox credential is the one
 * thing nobody can work around, so the extraction had to be provable
 * without it. Pass a DSN as argv[1] to ALSO print what the extractor makes
 * of the messages already stored in the CRM - that half is a report to
 * read, not an assertion, because nobody has labelled those by hand.
 *
 *   php tools/test-inbox-extract.php
 *   php tools/test-inbox-extract.php sqlite:/var/data/fabrioza_crm.db
 */
require __DIR__ . '/../dist/api/lib/inbox_extract.php';

$pass = 0; $fail = 0;
function ok(string $what, $got, $want) {
    global $pass, $fail;
    $same = $got === $want;
    if ($same) { $pass++; return; }
    $fail++;
    printf("FAIL %s%s   got:  %s%s   want: %s%s", $what, PHP_EOL,
        var_export($got, true), PHP_EOL, var_export($want, true), PHP_EOL);
}

/* ===================================================================== *
 * 1. Quoting. This is the one that matters most: if the quoted thread
 *    survives, every reply carries OUR signature and brand extraction
 *    returns FABRIOZA for the customer. Verified to fail by removing the
 *    'On ... wrote:' cut.
 * ===================================================================== */
$reply = "Hi Yaseen,\n\nThat price works. Please proceed.\n\nBest regards,\nAnna\n"
       . "NORTHBOUND APPAREL\n\nOn Mon, 3 Nov 2026 at 09:14, FABRIOZA <info@fabrioza.com> wrote:\n"
       . "> Dear Anna, please find our quotation attached.\n"
       . "> Best regards,\n"
       . "> Yaseen Ishfaq\n"
       . "> FABRIOZA\n";
$clean = inbox_strip_quoted($reply);
ok('quoted thread is cut', strpos($clean, 'quotation attached'), false);
ok('our own name is gone', stripos($clean, 'Yaseen Ishfaq'), false);
ok('their words survive', strpos($clean, 'That price works') !== false, true);
[$b, $how] = inbox_brand($clean, 'anna@northbound-apparel.com');
ok('brand from a reply is theirs, not ours', $b, 'Northbound Apparel');

/* the un-stripped version is what the bug looks like */
[$bad, ] = inbox_brand($reply, 'anna@gmail.com');
ok('without stripping, a brand is still never FABRIOZA', inbox_is_ours((string)$bad), false);

/* other languages' separators */
ok('French separator cuts',
    strpos(inbox_strip_quoted("Bonjour,\nD'accord.\n\nLe 3 nov. 2026 a 09:14, FABRIOZA a ecrit :\n> ancien texte"), 'ancien'), false);
ok('Spanish separator cuts',
    strpos(inbox_strip_quoted("Hola,\nPerfecto.\n\nEl 3 nov 2026, FABRIOZA escribió:\n> texto antiguo"), 'antiguo'), false);
ok('Outlook divider cuts',
    strpos(inbox_strip_quoted("Yes please.\n\n__________________\nFrom: FABRIOZA\nold"), 'old'), false);

/* ===================================================================== *
 * 2. Brand from the sending domain
 * ===================================================================== */
[$b, $how] = inbox_brand('Hello, I need 200 hoodies.', 'contact@northform.example');
ok('business domain gives the brand', [$b, $how], ['Northform', 'domain']);

[$b, $how] = inbox_brand('Hello, I need 200 hoodies.', 'buyer@harlow.co.uk');
ok('co.uk public suffix handled', [$b, $how], ['Harlow', 'domain']);

[$b, $how] = inbox_brand('Hello, I need 200 hoodies.', 'someone@gmail.com');
ok('freemail gives nothing', [$b, $how], [null, 'none']);

[$b, ] = inbox_brand('Hello.', 'jo@two-words-brand.com');
ok('hyphens become spaces', $b, 'Two Words Brand');

[$b, ] = inbox_brand('Hello.', 'me@info.fabrioza.com');
ok('our own domain is never a brand', $b, null);

/* ===================================================================== *
 * 3. Brand stated outright - beats the domain
 * ===================================================================== */
[$b, $how] = inbox_brand('Hi, my brand is Velour Athletics and we need samples.', 'x@gmail.com');
ok('"my brand is X"', [$b, $how], ['Velour Athletics', 'stated']);

[$b, $how] = inbox_brand("I'm the co-founder of AURELIX, a premium menswear label.", 'x@gmail.com');
ok('"co-founder of X" stops at the comma', [$b, $how], ['AURELIX', 'stated']);

[$b, $how] = inbox_brand('We run Oakfield Studio and need 50 tees.', 'hello@oakfield-studio.com');
ok('stated beats the domain', $how, 'stated');

[$b, ] = inbox_brand('my brand is the', 'x@gmail.com');
ok('a stop word is not a brand', $b, null);

[$b, ] = inbox_brand('I am the founder of a small clothing brand', 'x@gmail.com');
ok('a generic phrase is not a brand', $b, null);

/* ===================================================================== *
 * 4. Signature
 * ===================================================================== */
[$b, $how] = inbox_brand("Please send a quote.\n\nBest regards,\nMarek Nowak\nHALCYON STUDIOS\n", 'm@gmail.com');
ok('ALL CAPS line under a sign-off', [$b, $how], ['HALCYON STUDIOS', 'signature']);

[$b, $how] = inbox_brand("Thanks.\n\nRegards,\nSam Patel\nPatel Textiles Ltd\n", 's@gmail.com');
ok('legal form under a sign-off', [$b, $how], ['Patel Textiles Ltd', 'signature']);

[$b, ] = inbox_brand("Thanks.\n\nRegards,\nSam Patel\nSenior Buyer\n", 's@gmail.com');
ok('a job title is not a brand', $b, null);

[$b, ] = inbox_brand("Thanks.\n\nRegards,\nSam Patel\n", 's@gmail.com');
ok('a lone human name is not a brand', $b, null);

[$b, ] = inbox_brand("Ok.\n\nThanks,\nJo\nSent from my iPhone\n", 'j@gmail.com');
ok('"Sent from my iPhone" is not a brand', $b, null);

[$b, $how] = inbox_brand('I need a private-label range for my brand, North by Hand, based in Australia.',
                         'info@northbyhand.example');
ok('comma apposition beats the domain form', [$b, $how], ['North by Hand', 'stated']);

[$b, ] = inbox_brand('Hi, my brand is Moorside. I also want hoodies.', 'x@gmail.com');
ok('a lone capital after a full stop does not extend the name', $b, 'Moorside');

[$b, ] = inbox_brand('Our label is House of Pine and we need 100 tees.', 'x@gmail.com');
ok('a lowercase connective inside a name is kept', $b, 'House of Pine');

[$b, $how] = inbox_brand('I work for a student engineering design team.', 'buyer@stateuni.edu');
ok('a .edu domain is an institution, not a brand', [$b, $how], [null, 'none']);

[$b, ] = inbox_brand('Hello.', 'someone@cam.ac.uk');
ok('an .ac.uk domain is not a brand', $b, null);

/* ORDER MATTERS, and getting it wrong loses every signature. inbox_query()
   flattens whitespace for storage; a signature only exists while the line
   breaks do. The old importer stored flattened text, which is why the
   brands in those rows can no longer be recovered. Extract first, flatten
   second - this test pins that. */
$sig = "Please quote 20 pieces.\n\nBest regards,\nSam Blake\nRIVER STAG\n";
[$b, $how] = inbox_brand(inbox_strip_quoted($sig), 'x@gmail.com');
ok('brand survives when extracted before flattening', [$b, $how], ['RIVER STAG', 'signature']);
[$b2, ] = inbox_brand(inbox_query($sig), 'x@gmail.com');
ok('and is unrecoverable after flattening (so never flatten first)', $b2, null);

/* ===================================================================== *
 * 5. Subject written as a deal name
 * ===================================================================== */
[$b, $how] = inbox_brand('Hello.', 'x@gmail.com', 'Re: R. Hale & Co. x FABRIOZA - fall collection');
ok('subject names the other party', [$b, $how], ['R. Hale & Co.', 'subject']);

[$b, ] = inbox_brand('Hello.', 'x@gmail.com', 'FABRIOZA x FABRIOZA');
ok('both sides ours gives nothing', $b, null);

/* Faults found by auditing the real 962-message mailbox. Every one of
   these cost a real brand name, or recorded a wrong one as fact. */

[$b, ] = inbox_brand('Hello.', 'buyer@outlook.fr');
ok('a consumer provider on another ccTLD is still freemail', $b, null);
[$b, ] = inbox_brand('Hello.', 'buyer@gmail.co.uk');
ok('gmail on any ccTLD is freemail', $b, null);
[$b, $how] = inbox_brand('Hello.', 'buyer@outlookfurniture.com');
ok('a company merely STARTING with a provider name is kept', [$b, $how], ['Outlookfurniture', 'domain']);

[$b, ] = inbox_brand("Please quote.\n\nBest regards,\nAlex Leiter\nCo-Founder\n", 'a@gmail.com');
ok('"Co-Founder" is a title, not a company', $b, null);
[$b, $how] = inbox_brand("Please quote.\n\nBest regards,\nAlex Leiter\nHale Trading Co.\n", 'a@gmail.com');
ok('a name-final legal form is still accepted', [$b, $how], ['Hale Trading Co.', 'signature']);

[$b, ] = inbox_brand("Hi, my brand is Hidden Haven. I'm looking for hoodies.", 'h@gmail.com');
ok('a stated name stops at the full stop', $b, 'Hidden Haven');
[$b, ] = inbox_brand('Hello.', 'x@gmail.com', 'R. Hale & Co. x FABRIOZA');
ok('an initial is not a full stop (unchanged)', $b, 'R. Hale & Co.');

/* NON-UTF-8 MAIL. The bug this guards against was invisible: preg_* with
   /u returns false rather than erroring, so a Windows-1252 body made the
   tidy step hand back null, the caller read null as noise, and a correct
   STATED brand was thrown away in favour of the subject line. Outlook
   sends 1252 by default for plenty of senders. */
$w1252 = "Hi, I" . chr(0x92) . "m the founder of AURELIX, a streetwear brand.";
ok('the fixture really is invalid UTF-8', mb_check_encoding($w1252, 'UTF-8'), false);
[$b, $how] = inbox_brand($w1252, 'x@gmail.com');
ok('a brand is still extracted from Windows-1252 mail', [$b, $how], ['AURELIX', 'stated']);
ok('the subject is not used when the body states a brand', $how, 'stated');

$w1252b = "We are based in Espa" . chr(0xF1) . "a and need 300 tees.";
ok('country still works on Windows-1252', inbox_country($w1252b), 'Spain');

ok('inbox_utf8 leaves valid UTF-8 untouched', inbox_utf8('caf\xc3\xa9'), 'caf\xc3\xa9');
ok('inbox_utf8 returns valid UTF-8 for 1252 input',
   mb_check_encoding(inbox_utf8($w1252), 'UTF-8'), true);

/* TRADE VOCABULARY IS NOT A NAME. Both of these were recorded as real
   companies from subject lines, and the three below them are real brands
   that ALSO appear in their senders' subject lines - so position cannot
   separate them and vocabulary must. */
ok('a product description is not a brand', inbox_brand_is_noise('Premium Oversized Tracksuit'), true);
ok('an enquiry phrase is not a brand',     inbox_brand_is_noise('Manufacturing Inquiry'), true);
ok('a short real brand survives',          inbox_brand_is_noise('RVL'), false);
ok('an invented word survives',            inbox_brand_is_noise('AURELIX'), false);
ok('a two-word real brand survives',       inbox_brand_is_noise('Mono Rotterdam'), false);
ok('one trade word alone is still allowed (it may be the whole name)',
   inbox_brand_is_noise('Streetwear'), false);
ok('a brand mixing a trade word with a name survives',
   inbox_brand_is_noise('Hale Sportswear'), false);

/* ===================================================================== *
 * 6. Country - only when stated about themselves
 * ===================================================================== */
ok('"I am from Brazil"', inbox_country("Hi. I'm from Brazil. Do you send to my country?"), 'Brazil');
ok('"based in Spain"',   inbox_country('We are based in Spain and need 300 tees.'), 'Spain');
ok('"ship to Germany"',  inbox_country('Can you ship to Germany?'), 'Germany');
ok('a bare mention is not a location', inbox_country('Our fabric usually comes from mills and we sell worldwide.'), null);
ok('no country stated',  inbox_country('Please send pricing for 100 hoodies.'), null);

/* ===================================================================== *
 * 7. The query capture
 * ===================================================================== */
$long = 'A' . str_repeat('b', 3000);
ok('query is capped', mb_strlen(inbox_query($long)), 2000);
ok('query is longer than the old 500-char snippet', mb_strlen(inbox_query($long)) > 500, true);
ok('query drops the quoted thread',
    strpos(inbox_query($reply), 'quotation attached'), false);
ok('query flattens whitespace',
    inbox_query("Hello\n\n\n   world"), 'Hello world');

/* ===================================================================== *
 * summary
 * ===================================================================== */
printf('%s%d passed, %d failed%s', PHP_EOL, $pass, $fail, PHP_EOL);

/* ---- optional: what it makes of the real stored messages ------------- */
$dsn = $argv[1] ?? '';
if ($dsn !== '') {
    echo PHP_EOL, '--- extractor against the messages already in the CRM ---', PHP_EOL;
    $db = new PDO($dsn);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $rows = $db->query("SELECT id, name, email, company, COALESCE(message,'') m
                        FROM leads ORDER BY id")->fetchAll();
    $found = 0; $byhow = [];
    foreach ($rows as $r) {
        if (trim($r['m']) === '') continue;
        $clean = inbox_strip_quoted($r['m']);
        [$b, $how] = inbox_brand($clean, $r['email'], '');
        $c = inbox_country($clean);
        if ($b === null && $c === null) continue;
        $byhow[$how] = ($byhow[$how] ?? 0) + 1;
        if ($b !== null) $found++;
        printf("  #%-3d %-28s brand=%-26s via %-9s country=%s%s",
            $r['id'], mb_substr($r['email'], 0, 28), $b === null ? '-' : mb_substr($b, 0, 26),
            $how, $c ?? '-', PHP_EOL);
    }
    printf('%s  %d of %d leads would gain a brand. by method: ', PHP_EOL, $found, count($rows));
    foreach ($byhow as $h => $n) echo "$h=$n ";
    echo PHP_EOL;
}
exit($fail > 0 ? 1 : 0);
