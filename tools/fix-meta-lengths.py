# -*- coding: utf-8 -*-
"""Bring every title under 60 chars and every description under 160.

WHY THESE RULES AND NOT A REWRITE: 136 of the 138 over-length titles end
" | FABRIOZA". That suffix is 11 characters of a 60-character budget spent
on a brand with 30 impressions in three months, and search engines show the
site name separately anyway. Dropping it fixes 82 titles on its own; also
dropping the trailing qualifier segment fixes 21 more. Only 12 need writing,
and every one of those is an English blog title.

TRANSFORMATIONS RUN ON THE RAW FILE STRING, never on anything retyped. Six
pages are French, Italian, Spanish, Portuguese, German or Dutch; retyping
"Fabricant de Vetements pour Marques Francaises" would silently strip the
accents. Slicing the string the file already holds cannot.

LENGTHS ARE MEASURED UNESCAPED. "&amp;" is five characters in the file and
one on screen, so a title with two ampersands measures eight characters
longer than Bing sees it, and the fix would cut copy that was never too long.
"""
import io, html, json, pathlib, re, sys

APPLY = '--apply' in sys.argv
ROOT = pathlib.Path('dist')
TMAX, DMAX, DMIN = 60, 160, 70
DASH = '\u2013'

# The 12 that need a human. Written out, because a slice of these loses the
# question form that matches how people actually search.
HAND_TITLE = {
 '/blog/pakistan-vs-china-vs-bangladesh-vs-vietnam-vs-india':
   'Pakistan vs China vs Bangladesh vs Vietnam vs India (2026)',
 '/blog/what-certifications-should-a-clothing-manufacturer-have':
   'What Certifications Should a Clothing Manufacturer Have?',
 '/blog/hoodie-manufacturing-cost-pakistan':
   'How Much Does a Hoodie Cost to Make? Pakistan 2026',
 '/blog/best-custom-sportswear-manufacturers':
   '9 Best Custom Sportswear Manufacturers in 2026',
 '/blog/clothing-manufacturer-vs-wholesale':
   'Clothing Manufacturer vs Wholesale: Which Is Better?',
 '/blog/alibaba-vs-direct-clothing-manufacturer':
   'Alibaba vs Direct Clothing Manufacturer: 2026 Guide',
 '/blog/best-low-moq-clothing-manufacturers':
   '7 Best Low-MOQ Clothing Manufacturers in 2026',
 '/blog/pakistan-vs-bangladesh-clothing-manufacturing':
   'Pakistan vs Bangladesh Clothing Manufacturing 2026',
 '/blog/custom-basketball-uniforms-guide':
   'Custom Basketball Uniforms: Design, Cost &amp; Ordering 2026',
 '/blog/clothing-manufacturer-directories-vs-direct':
   "Sewport, Maker's Row &amp; Foursource vs Going Direct",
 # The site's best page: 425 impressions, 10 clicks, 1.5K AI citations, and
 # its top grounding query is "how much does it cost to make a t-shirt".
 # The old title said "Manufacture"; this one matches the query verbatim.
 '/blog/tshirt-manufacturing-cost':
   'How Much Does It Cost to Make a T-Shirt? 2026 Prices',
 '/blog/best-fabric-for-gym-wear':
   'Best Fabric for Gym Wear &amp; Leggings: Manufacturer Guide',
}

# One description the rules get wrong: it is a FIVE-country comparison and a
# clause break leaves it naming three, which reads as the article's scope.
HAND_DESC = {
 '/blog/pakistan-vs-china-vs-bangladesh-vs-vietnam-vs-india':
   'MOQs, per-unit costs, product strengths, lead times and duty access across '
   'all five: Pakistan, China, Bangladesh, Vietnam and India.',
}

def shorten_title(raw):
    t = raw
    if len(html.unescape(t)) > TMAX and t.endswith(' | FABRIOZA'):
        t = t[:-11]
    if len(html.unescape(t)) > TMAX:
        i = t.rfind(' | ')
        if i > 0:
            t = t[:i]
    return t

def shorten_desc(d):
    """d is UNESCAPED text; returns unescaped text."""
    parts = re.split(r'(?<=[.!?])\s+', d)
    kept = ''
    for p in parts:
        c = (kept + ' ' + p).strip() if kept else p
        if len(c) <= DMAX:
            kept = c
        else:
            break
    if len(kept) >= DMIN:
        return kept
    # A dash, colon or semicolon ends a thought; a comma can cut a list in
    # half, so it is only used when no stronger break leaves enough text.
    strong = max(d.rfind(s, 0, DMAX) for s in (' - ', ' ' + DASH + ' ', '; ', ': '))
    cut = strong if strong >= DMIN else d.rfind(', ', 0, DMAX)
    # NO raw-word fallback. Cutting at an arbitrary space produced
    # "...si la serie ne correspond pas a votre." on /conditions-de-paiement
    # - a French sentence broken mid-phrase, which a word-boundary check
    # cannot see because every word IS complete. A description that cannot
    # be cut at a punctuation break has to be written, not sliced.
    if cut < DMIN:
        raise ValueError('no clean break: ' + d[:90])
    return d[:cut].rstrip(' ,;:-' + DASH) + '.'

changed = tchanged = dchanged = 0
report = []
for p in sorted(ROOT.rglob('index.html')):
    s = io.open(p, encoding='utf-8', newline='').read()
    url = '/' + str(p.parent.relative_to(ROOT)).replace(chr(92), '/')
    if url == '/.':
        url = '/'
    orig = s

    m = re.search(r'<title>(.*?)</title>', s, re.S)
    if m and len(html.unescape(m.group(1).strip())) > TMAX:
        old = m.group(1).strip()
        new = HAND_TITLE.get(url) or shorten_title(old)
        assert len(html.unescape(new)) <= TMAX, '%s title still %d' % (url, len(html.unescape(new)))
        assert len(html.unescape(new)) >= 20, '%s title gutted' % url
        s = s.replace(old, new)          # <title>, og:title, twitter:title, JSON-LD name
        tchanged += 1
        report.append(('T', url, len(html.unescape(old)), len(html.unescape(new)), html.unescape(new)))

    m = re.search(r'<meta[^>]+name="description"[^>]+content="(.*?)"', s, re.S)
    if m and len(html.unescape(m.group(1).strip())) > DMAX:
        old = m.group(1).strip()
        plain = html.unescape(old)
        assert '"' not in plain, '%s description contains a quote' % url
        newplain = HAND_DESC.get(url) or shorten_desc(plain)
        assert len(newplain) <= DMAX, '%s desc still %d' % (url, len(newplain))
        assert len(newplain) >= DMIN, '%s desc too short (%d)' % (url, len(newplain))
        new = newplain.replace('&', '&amp;')
        s = s.replace(old, new)          # meta, og:, twitter:, JSON-LD description
        dchanged += 1
        report.append(('D', url, len(plain), len(newplain), newplain))

    if s != orig:
        changed += 1
        if APPLY:
            io.open(p, 'w', encoding='utf-8', newline='').write(s)

print('files touched: %d   titles: %d   descriptions: %d' % (changed, tchanged, dchanged))
print('APPLIED' if APPLY else 'DRY RUN - nothing written. Pass --apply.')
pathlib.Path('../meta-report.json').write_text(json.dumps(report, indent=0, ensure_ascii=False), encoding='utf-8')
