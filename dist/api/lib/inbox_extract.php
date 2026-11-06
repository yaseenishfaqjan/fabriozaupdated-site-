<?php
/**
 * FABRIOZA - pulling usable facts out of an inbox message.
 *
 * Pure functions, no IMAP and no database, so the whole thing is testable
 * offline against real stored messages: see tools/test-inbox-extract.php.
 * That matters here because the mailbox credential is the one part nobody
 * can work around, and everything else had to be built and proven without
 * it.
 *
 * Three jobs:
 *   inbox_strip_quoted()  drop the quoted thread and the signature
 *   inbox_brand()         the sender's brand name, or null
 *   inbox_country()       a country, only when the message states one
 *
 * THE RULE THROUGHOUT: return null rather than a guess. A blank company
 * field reads as "we do not know". A wrong one reads as fact, gets used in
 * a reply, and tells the customer we were not listening.
 */

/**
 * Everything after a reply marker is THEIR copy of OUR email.
 *
 * This runs before anything else, and the rest of the file depends on it.
 * Without it a reply carries our own signature, so brand extraction
 * confidently returns "FABRIOZA" for every customer who ever replied, and
 * the stored "query" is mostly our own words quoted back.
 */
function inbox_strip_quoted(string $body): string
{
    $body = str_replace(["\r\n", "\r"], "\n", $body);

    /* Reply separators, in the forms the real inbox actually contains:
       English and the languages these customers write in. Each cuts the
       message at the first match, because everything below is history. */
    $cuts = [
        '/^\s*On .{0,80}\bwrote:\s*$/mi',            // Gmail, English
        '/^\s*Le .{0,80}\ba écrit\s*:\s*$/miu',       // French
        '/^\s*El .{0,80}\bescribió:\s*$/miu',         // Spanish
        '/^\s*Am .{0,80}\bschrieb\b.{0,40}:\s*$/mi',  // German
        '/^\s*Op .{0,80}\bschreef\b.{0,40}:\s*$/mi',  // Dutch
        '/^\s*-{2,}\s*Original Message\s*-{2,}\s*$/mi',
        '/^\s*_{10,}\s*$/m',                          // Outlook divider
        '/^\s*From:\s.{0,120}$/mi',                   // forwarded header block
        '/^\s*Van:\s.{0,120}$/mi',
        '/^\s*De\s?:\s.{0,120}$/mi',
    ];
    $cutAt = strlen($body);
    foreach ($cuts as $rx) {
        if (preg_match($rx, $body, $m, PREG_OFFSET_CAPTURE)) {
            $cutAt = min($cutAt, $m[0][1]);
        }
    }
    $body = substr($body, 0, $cutAt);

    /* Drop quoted lines wherever they survived a missing separator. */
    $keep = [];
    foreach (explode("\n", $body) as $line) {
        if (preg_match('/^\s*>/', $line)) continue;
        $keep[] = $line;
    }
    return trim(implode("\n", $keep));
}

/** Free mail providers: the domain says nothing about a brand. */
function inbox_is_freemail(string $host): bool
{
    static $free = [
        'gmail.com', 'googlemail.com', 'yahoo.com', 'yahoo.co.uk', 'ymail.com',
        'hotmail.com', 'hotmail.co.uk', 'outlook.com', 'live.com', 'msn.com',
        'icloud.com', 'me.com', 'mac.com', 'aol.com', 'proton.me',
        'protonmail.com', 'gmx.com', 'gmx.de', 'yandex.com', 'yandex.ru',
        'mail.com', 'mail.ru', 'qq.com', '163.com', '126.com', 'zoho.com',
        'inbox.lv', 'web.de', 'free.fr', 'orange.fr', 'libero.it',
        'seznam.cz', 'naver.com', 'daum.net', 'rediffmail.com',
    ];
    return in_array(strtolower($host), $free, true);
}

/** Never report ourselves as the customer's brand. */
function inbox_is_ours(string $s): bool
{
    $s = strtolower($s);
    foreach (['fabrioza', 'storehouse360', 'storehouse 360', 'scalaro'] as $own) {
        if (strpos($s, $own) !== false) return true;
    }
    return false;
}

/**
 * Words that look like a brand to a regex and are not one. Kept separate
 * from the patterns because this is the list that grows: every false
 * positive found in testing is added here rather than by tightening a
 * pattern until it stops matching real brands too.
 */
function inbox_brand_is_noise(string $c): bool
{
    $c = trim($c);
    if ($c === '' || mb_strlen($c) < 2 || mb_strlen($c) > 60) return true;
    if (preg_match('/[@<>{}|\\\\]|https?:/i', $c)) return true;
    if (preg_match('/^\d+$/', $c)) return true;
    static $stop = [
        'a', 'an', 'the', 'my', 'our', 'your', 'this', 'that', 'it', 'me', 'we',
        'i', 'you', 'they', 'new', 'own', 'small', 'clothing', 'apparel',
        'brand', 'company', 'business', 'label', 'line', 'startup', 'start',
        'manufacturer', 'supplier', 'vendor', 'factory', 'team', 'hi', 'hello',
        'hey', 'dear', 'thanks', 'thank', 'regards', 'best', 'sincerely',
        'cheers', 'kind', 'sent', 'from', 'iphone', 'android', 'mobile',
        'customer', 'client', 'sir', 'madam', 'good', 'morning', 'afternoon',
        'evening', 'please', 'quote', 'quotation', 'enquiry', 'inquiry',
        'order', 'sample', 'samples', 'price', 'pricing', 'moq', 'yes', 'no',
        'ok', 'okay', 'owner', 'founder', 'director', 'manager', 'ceo',
    ];
    if (in_array(strtolower($c), $stop, true)) return true;
    /* A sentence is not a brand name - but an initial is not a sentence.
       The first version used /[.!?]\s+\S/ and threw away "R. Hale & Co.",
       exactly the kind of name it exists to keep. A LOWERCASE word after
       the stop is what actually marks prose. */
    if (str_word_count($c) > 6) return true;
    if (preg_match('/[.!?]\s+\p{Ll}/u', $c)) return true;
    return false;
}

function inbox_brand_tidy(string $c): string
{
    $c = html_entity_decode($c, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $c = trim(preg_replace('/\s+/', ' ', $c));
    $c = trim($c, " \t\n\"'`,;:!?()[]-–—*");
    /* Trailing stop: strip it, UNLESS it belongs to an abbreviation that
       really ends in one. "R. Hale & Co." is the company's name; trimming
       every trailing period renamed it to "R. Hale & Co". */
    if (!preg_match('/\b(Co|Inc|Ltd|Corp|Bros|Co\.,? Ltd|St|Mfg|Intl)\.$/i', $c)) {
        $c = rtrim($c, '.');
    }
    return trim($c);
}

/**
 * The sender's brand, or null.
 *
 * Tried in confidence order, and it stops at the first hit:
 *   1. the customer SAYS it ("my brand is X", "I am the founder of X")
 *   2. a non-freemail sending domain (a business address IS the brand)
 *   3. a signature line
 * Order matters. The domain is reliable but coarse - it gives "Harlow" for
 * buyer@harlow.co.uk, which is right - while a stated name is exact. The
 * signature is last because it is the guessiest of the three.
 *
 * @param string $body    message text, ALREADY passed through
 *                        inbox_strip_quoted() - otherwise this reads our
 *                        signature out of their reply and returns FABRIOZA
 * @param string $email   sender address
 * @param string $subject subject line
 * @return array{0: ?string, 1: string}  [brand, how it was found]
 */
function inbox_brand(string $body, string $email, string $subject = ''): array
{
    /* ---- 1. stated outright ------------------------------------------- */
    /* A brand NAME, not the remainder of the sentence. The first version
       captured [^\n,.;!?]{1,48} and returned "Velour Athletics and we need
       samples" - everything up to the full stop. A name is up to four
       words that each START UPPERCASE, and that is also what stops the
       match at the lower-case conjunction beginning the rest of the
       sentence.

       The capture stays CASE-SENSITIVE via (?-i:) even though each pattern
       now carries /i. The /i is needed because the lead-in words appear
       capitalised at the start of a sentence - the first version wrote
       \b(?:I|we) with no /i and silently never matched "We run X" - but
       the capital on the name is the only signal that it IS a name, so it
       must not be relaxed with it. */
    /* A word may be joined by a lowercase connective - "North by Hand",
       "House of Pine" - but the name can never END on one, because the
       trailing [A-Z0-9] word is required after it. Without the connective
       list this returned "South" and fell short of the real name. */
    $W1   = '[A-Z0-9][\w&\x{2019}\'.\-]*';   // first word: "N." is valid
    $W2   = '[A-Z0-9][\w&\x{2019}\'.\-]+';   // later words: 2+ chars
    $JOIN = '(?:at|of|and|the|for|by|de|du|la|le|los|in|on|to|&)';
    /* A CONTINUATION word must be at least two characters. Allowing one
       turned "my brand is Moorside. I also need..." into the brand
       "Moorside. I" - the sentence's next word was a capital letter on
       its own. The first word may still be a single initial. */
    $NAME = '(?-i:' . $W1 . '(?:\s+(?:' . $JOIN . '\s+)?' . $W2 . '){0,4})';
    $patterns = [
        '/\b(?:my|our)\s+(?:clothing\s+|apparel\s+|streetwear\s+|fashion\s+)?'
            . '(?:brand|label|company|business)\s+(?:is\s+|called\s+|named\s+)'
            . '(' . $NAME . ')/iu',
        /* Comma apposition - "my brand, North by Hand, based in..." - which
           is how people actually write it. Requiring is/called/named made
           this fall through to the domain and return "Northbyhand". */
        '/\b(?:my|our)\s+(?:clothing\s+|apparel\s+|streetwear\s+|fashion\s+)?'
            . '(?:brand|label|company|business)\s*,\s*'
            . '(' . $NAME . ')/iu',
        '/\b(?:co-?)?(?:founder|owner|director|ceo)\s+(?:and\s+\w+\s+)?of\s+'
            . '(' . $NAME . ')/iu',
        '/\b(?:i|we)\s+(?:am|are)\s+(?:the\s+)?(?:co-?)?(?:founder|owner)\s+of\s+'
            . '(' . $NAME . ')/iu',
        '/\bbrand\s+name\s+is\s+(' . $NAME . ')/iu',
        '/\bon\s+behalf\s+of\s+(' . $NAME . ')/iu',
        '/\b(?:i|we)\s+(?:run|own)\s+(' . $NAME . ')/iu',
    ];
    foreach ($patterns as $rx) {
        if (preg_match($rx, $body, $m)) {
            $c = inbox_brand_tidy($m[1]);
            if (!inbox_brand_is_noise($c) && !inbox_is_ours($c)) return [$c, 'stated'];
        }
    }

    /* ---- 2. the sending domain ---------------------------------------- */
    $at = strrchr($email, '@');
    $host = $at === false ? '' : strtolower(substr($at, 1));
    /* Academic, government and military domains are institutions. The
       domain rule turned stateuni.edu into the brand "Stateuni" - the University of
       Florida, and that sender was a student design team, not a label. */
    $institutional = (bool) preg_match('/\.(edu|gov|mil|int)$|\.(ac|edu|gov|mil)\.[a-z]{2}$/i', $host);
    if ($host !== '' && !inbox_is_freemail($host) && !inbox_is_ours($host) && !$institutional) {
        /* Strip the public suffix: co.uk and com.au are two labels. */
        $parts = explode('.', $host);
        static $two = ['co', 'com', 'org', 'net', 'gov', 'ac', 'edu'];
        $n = count($parts);
        $idx = ($n >= 3 && in_array($parts[$n - 2], $two, true)) ? $n - 3 : $n - 2;
        $name = $idx >= 0 ? $parts[$idx] : $parts[0];
        $name = str_replace('-', ' ', $name);
        if (!inbox_brand_is_noise($name)) {
            /* A domain is lowercase; title-case it for display, but leave a
               name that is already mixed case alone. */
            return [ucwords($name), 'domain'];
        }
    }

    /* ---- 3. the signature --------------------------------------------- */
    /* Look only just after a sign-off. Scanning the whole tail finds the
       customer's own name, their job title and their street address. */
    if (preg_match('/^\s*(?:best\s+regards|kind\s+regards|warm\s+regards|regards|'
        . 'sincerely|thanks|thank\s+you|cheers|best|cordialement|saludos|'
        . 'mit\s+freundlichen\s+gr(?:u|ü)(?:ss|ß)en|met\s+vriendelijke\s+groet)'
        . '\s*[,.!]?\s*$/mi', $body, $m, PREG_OFFSET_CAPTURE)) {
        $tail = substr($body, $m[0][1] + strlen($m[0][0]));
        $lines = [];
        foreach (explode("\n", $tail) as $l) {
            $l = inbox_brand_tidy($l);
            if ($l !== '') $lines[] = $l;
            if (count($lines) >= 4) break;
        }
        /* The first line after a sign-off is almost always the person.
           A brand, when present, follows it - and tends to be ALL CAPS or
           to carry a legal form. Require one of those signals rather than
           taking line 2 on faith. */
        foreach (array_slice($lines, 1) as $l) {
            if (inbox_brand_is_noise($l) || inbox_is_ours($l)) continue;
            $caps  = (mb_strtoupper($l) === $l && preg_match('/\p{L}{2}/u', $l));
            $legal = preg_match('/\b(ltd|limited|llc|inc|co|corp|gmbh|bv|b\.v\.|'
                              . 'sarl|s\.?l\.?|pty|plc|studio|studios|collective|'
                              . 'apparel|clothing|brands?|wear|co\.)\b/i', $l);
            if ($caps || $legal) return [$l, 'signature'];
        }
    }

    /* ---- 4. the subject, when it names two parties --------------------- */
    /* "R. Hale & Co. x FABRIOZA" - a subject written like a deal name has
       the customer on one side of the separator and us on the other. */
    /* Allow trailing text. A real subject carries the topic after the
       deal name ("A x FABRIOZA - fall collection"), so anchoring the
       second party to end-of-line matched none of them. */
    if (preg_match('/^(?:re\s*:\s*|fwd?\s*:\s*|subject\s*:\s*)*'
                 . '(.{2,60}?)\s+[x\x{00d7}|]\s+([^\x{2013}\x{2014}\-]{2,60})/iu',
                   trim($subject), $m)) {
        foreach ([$m[1], $m[2]] as $side) {
            $c = inbox_brand_tidy($side);
            if (!inbox_brand_is_noise($c) && !inbox_is_ours($c)) return [$c, 'subject'];
        }
    }

    return [null, 'none'];
}

/**
 * A country, only when the message states one. Deliberately narrow: the
 * form leaves country blank on most rows, and an invented one is worse
 * than a blank.
 */
function inbox_country(string $body): ?string
{
    static $map = [
        'United Kingdom' => '/\b(united kingdom|england|scotland|wales|u\.?k\.?)\b/i',
        'United States'  => '/\b(united states|u\.?s\.?a\.?|america)\b/i',
        'Spain'          => '/\b(spain|espa(?:n|ñ)a)\b/iu',
        'Netherlands'    => '/\b(netherlands|holland|nederland)\b/i',
        'Germany'        => '/\b(germany|deutschland)\b/i',
        'France'         => '/\b(france)\b/i',
        'Brazil'         => '/\b(brazil|brasil)\b/i',
        'Australia'      => '/\b(australia)\b/i',
        'Canada'         => '/\b(canada)\b/i',
        'Ireland'        => '/\b(ireland)\b/i',
        'South Africa'   => '/\b(south africa)\b/i',
        'New Zealand'    => '/\b(new zealand)\b/i',
        'UAE'            => '/\b(united arab emirates|u\.?a\.?e\.?|dubai)\b/i',
    ];
    /* "I'm from Brazil" / "we are based in Spain" / "ship to Germany" are
       statements about the customer. A bare mention is not - "we also sell
       in France" says nothing about where they are - so require a cue. */
    foreach ($map as $country => $rx) {
        if (!preg_match($rx, $body, $m, PREG_OFFSET_CAPTURE)) continue;
        $before = substr($body, max(0, $m[0][1] - 40), min(40, $m[0][1]));
        if (preg_match('/\b(from|based in|located in|here in|in|to|ship(?:ping)? to|'
                     . 'deliver(?:y)? to|send to|i am in|we are in)\s*$/i', $before)) {
            return $country;
        }
    }
    return null;
}

/**
 * The query itself: their words, quoting stripped, whitespace flattened.
 * Longer than the old 500-character snippet - a real enquiry runs past it,
 * and the point of importing is to read what they asked.
 */
function inbox_query(string $body, int $max = 2000): string
{
    $t = inbox_strip_quoted($body);
    $t = trim(preg_replace('/\s+/', ' ', strip_tags($t)));
    return mb_substr($t, 0, $max);
}
