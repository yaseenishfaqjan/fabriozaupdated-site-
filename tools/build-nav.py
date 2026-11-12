# -*- coding: utf-8 -*-
"""One navigation, built from one source, applied to every static page.

    python -I tools/build-nav.py              # dry run, reports every page
    python -I tools/build-nav.py --apply

WHY THIS EXISTS, measured 10 Nov 2026 across 161 pages:

  * 101 pages had a WHITE top bar with grey links, 42 had a NEAR-BLACK one
    with white links, 3 had a dark gradient. Half the site looked like a
    different website from the other half.
  * 13 pages had a header with NO NAVIGATION AT ALL - just a logo and a
    tagline. Among them /moq-calculator, /streetwear-manufacturer,
    /private-label-clothing-manufacturer and /custom-hoodie-manufacturer:
    high-intent pages where a visitor had no route to pricing or the quote
    form. /blog had no header whatsoever.
  * 35 distinct link-sets. The customer's words were that each page shows
    "different new words, different new buttons".

The bar is DARK because the homepage's is: its nav renders white links over
a dark hero, so a white subpage bar is the jolt. Matching it is what makes a
subpage feel like the same site.

THREE THINGS THAT WOULD SILENTLY BREAK, all handled below:

1. The CTA is "/?from=<this page>#contact" - a PER-PAGE value that carries
   attribution into the homepage enquiry form. A constant CTA href would
   destroy that on 161 pages and nothing would error. It is rebuilt per page.
2. The six translated languages must NOT be overwritten with English. Their
   labels here were READ OFF their own landing pages, not translated by me.
   Spanish points at its own /precios-fabricacion-ropa; the others have no
   translated pricing page and point at /pricing, exactly as they already did.
3. Line endings are mixed per file in this repo and git converts nothing.
   The injected markup is therefore a SINGLE LINE containing no newline at
   all, so no file's endings can change and no diff can balloon.
"""
import io
import pathlib
import re
import sys

sys.stdout = io.TextIOWrapper(sys.stdout.buffer, encoding='utf-8')

ROOT = pathlib.Path(__file__).resolve().parent.parent
DIST = ROOT / 'dist'
APPLY = '--apply' in sys.argv
VER = '20261112c'   # bump whenever site-nav/site-motion assets change:
                       # they are served immutable for a year under a fixed
                       # filename, so an unchanged ?v= reaches nobody who has
                       # visited before - the edge keeps answering HIT.

# ---------------------------------------------------------------- link sets
# English: 7 links + CTA. Every destination was checked to exist and to
# already hold strong inbound links. /brand-launch, /design and
# /print-on-demand-alternative are deliberately NOT here - they are funnel
# landing pages, and they keep 4, 6 and 3 body links respectively, so
# dropping them from the nav does not orphan any of them (checked).
EN = [
    ('Home', '/'),
    ('Collections', '/what-we-make'),
    ('Sportswear', '/sportswear-catalog'),
    ('Pricing', '/pricing'),
    ('Samples', '/sample-order'),
    ('Blog', '/blog'),
    ('About', '/about'),
]
EN_CTA = 'Get a Quote'

# Read verbatim off each language's own landing page on 10 Nov 2026.
# Nothing in this table is my translation.
LANGS = {
    'es': ([('Inicio', '/'), ('Colecciones', '/what-we-make'), ('Blog', '/blog'),
            ('Precios', '/precios-fabricacion-ropa')], 'Pedir presupuesto'),
    'de': ([('Startseite', '/'), ('Kollektionen', '/what-we-make'), ('Blog', '/blog'),
            ('Preise', '/pricing')], 'Angebot anfordern'),
    'fr': ([('Accueil', '/'), ('Collections', '/what-we-make'), ('Blog', '/blog'),
            ('Tarifs', '/pricing')], 'Demander un devis'),
    'it': ([('Home', '/'), ('Collezioni', '/what-we-make'), ('Blog', '/blog'),
            ('Prezzi', '/pricing')], 'Richiedi un preventivo'),
    'nl': ([('Home', '/'), ('Collecties', '/what-we-make'), ('Blog', '/blog'),
            ('Prijzen', '/pricing')], 'Offerte aanvragen'),
    # "Início" - the accent is on the SECOND i. Written as "Ínicio"
    # it is a misspelling, which is what this file shipped on the first run.
    'pt': ([('Início', '/'), ('Coleções', '/what-we-make'), ('Blog', '/blog'),
            ('Preços', '/pricing')], 'Pedir orçamento'),
}
CONTACT = {'es': 'Contacto', 'de': 'Kontakt', 'fr': 'Contact',
           'it': 'Contatti', 'nl': 'Contact', 'pt': 'Contacto'}


def esc(s):
    return s.replace('&', '&amp;').replace('<', '&lt;').replace('"', '&quot;')


def nav_html(page_path, lang):
    """The whole top bar for one page, as a single line (see docstring)."""
    quote = '/?from=%s#contact' % page_path
    if lang in LANGS:
        links, cta = LANGS[lang]
        links = list(links) + [(CONTACT[lang], quote)]
    else:
        links, cta = list(EN), EN_CTA

    # Links carrying ?from= are the homepage with an attribution parameter.
    # They declare a canonical to "/" and are correct, but Search Console
    # showed Google crawling 160 of them (10 Nov 2026) - on a site with 161
    # real pages, half the crawl budget spent re-reading the homepage.
    # rel="nofollow" stops them being followed; robots.txt stops them being
    # crawled at all, including the ~85 more that live in page bodies and
    # that this generator does not control. Humans and the attribution
    # itself are unaffected.
    def anchor(label, href, extra=''):
        rel = ' rel="nofollow"' if href.startswith('/?from=') else ''
        return '<a href="%s"%s%s>%s</a>' % (esc(href), rel, extra, esc(label))

    out = []
    for label, href in links:
        cur = ''
        if href != '/' and href.rstrip('/') == page_path.rstrip('/'):
            cur = ' aria-current="page"'
        out.append(anchor(label, href, cur))
    out.append('<a class="fz-nav__cta" href="%s" rel="nofollow">%s</a>'
               % (esc(quote), esc(cta)))

    return (
        '<header class="fz-nav"><div class="fz-nav__bar">'
        '<a class="fz-nav__brand" href="/">'
        '<img src="/images/fabrioza-logo-sm.jpg" alt="FABRIOZA" width="34" height="34">'
        '<span>FABRIOZA</span></a>'
        '<button class="fz-nav__toggle" type="button" aria-expanded="false"'
        ' aria-controls="fz-nav-menu" aria-label="Menu">'
        '<span></span><span></span><span></span></button>'
        '<nav class="fz-nav__menu" id="fz-nav-menu" aria-label="Main">'
        + ''.join(out) +
        '</nav></div></header>'
    )


# Two stylesheets and two scripts, all shared, all cached for a year.
# site-motion.* is the scroll/hover motion. It is separate from the nav on
# purpose: the nav must work even if motion is ever pulled, and motion is the
# piece most likely to be reverted on taste.
ASSETS = [
    ('css', 'site-nav.css'),
    ('css', 'site-motion.css'),
    ('js', 'site-nav.js'),
    ('js', 'site-motion.js'),
    ('js', 'quote-drawer.js'),
]
CSS_LINK = ''.join('<link rel="stylesheet" href="/assets/%s?v=%s">' % (n, VER)
                   for k, n in ASSETS if k == 'css')
JS_TAG = ''.join('<script src="/assets/%s?v=%s" defer></script>' % (n, VER)
                 for k, n in ASSETS if k == 'js')

HEADER_RE = re.compile(r'<header[\s>].*?</header>', re.S)

stats = {'replaced': 0, 'inserted': 0, 'skipped': 0, 'already': 0}
problems = []

pages = sorted(DIST.glob('**/index.html'))
for p in pages:
    rel = p.relative_to(DIST).parent
    if str(rel) == '.':
        continue  # the React homepage renders its own nav from the bundle
    page_path = '/' + rel.as_posix()
    # newline='' so Python performs no line-ending translation on read or
    # write; this repo has mixed CRLF/LF per file and git converts nothing.
    with open(p, 'r', encoding='utf-8', newline='') as fh:
        s = fh.read()

    lm = re.search(r'<html[^>]*\blang="([^"]+)"', s)
    lang = (lm.group(1) if lm else 'en').split('-')[0]
    if lang not in LANGS:
        lang = 'en'

    new_header = nav_html(page_path, lang)

    m = HEADER_RE.search(s)
    if m:
        old = m.group(0)
        # Guard: only replace a SITE header. If the first <header> on a page
        # were an article or section header, replacing it would delete real
        # content. Every site header here carries the logo or a link to "/".
        if 'fz-nav' in old:
            s2 = s[:m.start()] + new_header + s[m.end():]
            stats['already'] += 1
        elif ('fabrioza-logo' in old) or ('href="/"' in old):
            s2 = s[:m.start()] + new_header + s[m.end():]
            stats['replaced'] += 1
        else:
            problems.append('%s: first <header> is not the site header - SKIPPED' % page_path)
            stats['skipped'] += 1
            continue
    else:
        bm = re.search(r'<body[^>]*>', s)
        if not bm:
            problems.append('%s: no <header> and no <body> - SKIPPED' % page_path)
            stats['skipped'] += 1
            continue
        s2 = s[:bm.end()] + new_header + s[bm.end():]
        stats['inserted'] += 1

    # Stylesheets in the head, scripts deferred at the end.
    #
    # Previously injected tags are STRIPPED first and all of them re-added,
    # rather than each being added only when absent. An "insert if missing"
    # guard converges on whatever the file happened to gain first: when
    # site-motion was added later, every page already carrying site-nav.css
    # would have been skipped and never received it. Strip-and-rewrite means
    # a re-run always produces the current set, whatever a page has now.
    s2 = re.sub(r'<link rel="stylesheet" href="/assets/site-(?:nav|motion)\.css\?v=[^"]*">', '', s2)
    s2 = re.sub(r'<script src="/assets/(?:site-nav|site-motion|quote-drawer)\.js\?v=[^"]*" defer></script>', '', s2)

    hm = re.search(r'</head>', s2, re.I)
    if not hm:
        problems.append('%s: no </head> - SKIPPED' % page_path)
        stats['skipped'] += 1
        continue
    s2 = s2[:hm.start()] + CSS_LINK + s2[hm.start():]

    bm = re.search(r'</body>', s2, re.I)
    if bm:
        s2 = s2[:bm.start()] + JS_TAG + s2[bm.start():]
    else:
        s2 = s2 + JS_TAG

    if APPLY and s2 != s:
        with open(p, 'w', encoding='utf-8', newline='') as fh:
            fh.write(s2)

print('pages considered : %d (the React homepage is excluded)' % (len(pages) - 1))
print('header replaced  : %d' % stats['replaced'])
print('header inserted  : %d  (pages that had none)' % stats['inserted'])
print('already fz-nav   : %d  (re-run, refreshed)' % stats['already'])
print('skipped          : %d' % stats['skipped'])
for x in problems:
    print('   !! ' + x)
print()
print('APPLIED' if APPLY else 'DRY RUN - nothing written. Pass --apply.')
