# -*- coding: utf-8 -*-
"""Wire the 12 dead category cards on /sportswear-catalog.

The page advertises 21 sportswear categories and shows 16 cards. Four of
them link somewhere; the other twelve are identical markup MINUS the closing
<a>, so a visitor clicks a category and nothing happens. Eight of those
twelve already have a page on this site that simply was never linked.

Four categories genuinely have no page - Ice Hockey, 7 on 7, Fishing, Sports
Bags - and neither does a GENERIC tracksuit page (there are six country
variants, no hub). Those five point at the quote form with wording that
promises a conversation rather than a page that does not exist. Sending them
to a country-specific page instead would be worse: a US visitor landing on
"Tracksuit Manufacturer for UK Brands" from a general catalogue is a bounce.

    python -I fix_catcards.py            # dry run
    python -I fix_catcards.py --apply
"""
import io
import pathlib
import re
import sys

sys.stdout = io.TextIOWrapper(sys.stdout.buffer, encoding='utf-8')

P = pathlib.Path(r"C:\Users\PCL\Downloads\fabrioza-deployment-v3\dist\sportswear-catalog\index.html")
APPLY = '--apply' in sys.argv
QUOTE = '/?from=/sportswear-catalog#contact'

# card id -> (href, link text).  Checked against the live site: every path
# in the left column returns 200 today.
WIRE = {
    'hockey':      (QUOTE, 'Request ice hockey uniforms'),
    'rugby':       ('/rugby-kit-manufacturer', 'View rugby kit range'),
    'cheer':       ('/custom-cheer-uniforms', 'View cheer uniform range'),
    '7on7':        (QUOTE, 'Request 7 on 7 uniforms'),
    'compression': ('/activewear-manufacturer', 'View compression & activewear'),
    'polo':        ('/custom-golf-polos', 'View polo shirt range'),
    'fishing':     (QUOTE, 'Request fishing wear'),
    'sublimation': ('/sublimation-printing', 'See sublimation printing'),
    'hoodies':     ('/custom-hoodie-manufacturer', 'View team hoodie range'),
    'tracksuits':  (QUOTE, 'Request tracksuits'),
    'bags':        (QUOTE, 'Request sports bags'),
    'accessories': ('/custom-patches', 'View patches & accessories'),
}

STYLE = ('display:inline-block;margin-top:12px;color:#4A7C59;font-weight:600;'
         'font-size:13px;text-decoration:none')

s = open(P, encoding='utf-8', newline='').read()

# Find every card and its id, so the patch is anchored on the card itself
# rather than on a text match that could appear twice on the page.
cards = list(re.finditer(r'<div class="cat-card" id="([a-z0-9]+)">.*?</div>\s*</div>', s, re.S))
print('cards found: %d' % len(cards))

wired, already, unknown, missing_id = 0, 0, [], []
out = s
for m in reversed(cards):          # reversed so earlier offsets stay valid
    cid = m.group(1)
    block = m.group(0)
    if '<a ' in block:
        already += 1
        continue
    if cid not in WIRE:
        unknown.append(cid)
        continue
    href, label = WIRE[cid]
    link = '<a href="%s" style="%s">%s &rarr;</a>' % (href, STYLE, label)
    # insert at the end of .cat-card-body, i.e. before the final two </div>
    close = block.rfind('</div>')
    close = block.rfind('</div>', 0, close)
    assert close != -1, 'could not locate the card body close for %s' % cid
    patched = block[:close] + link + block[close:]
    out = out[:m.start()] + patched + out[m.end():]
    wired += 1
    print('  wired  %-12s -> %s' % (cid, href))

for u in unknown:
    print('  !! card id "%s" is not in the mapping - left alone' % u)

print()
print('already linked : %d' % already)
print('newly wired    : %d' % wired)

if APPLY and out != s:
    open(P, 'w', encoding='utf-8', newline='').write(out)
    # every card must now carry exactly one link
    chk = re.findall(r'<div class="cat-card" id="([a-z0-9]+)">.*?</div>\s*</div>', out, re.S)
    dead = [c for c in re.finditer(r'<div class="cat-card" id="([a-z0-9]+)">.*?</div>\s*</div>', out, re.S)
            if '<a ' not in c.group(0)]
    print('cards still with no link: %d' % len(dead))
    assert not dead, 'a card is still dead: %s' % [d.group(1) for d in dead]
    print('APPLIED')
else:
    print('DRY RUN - nothing written. Pass --apply.')
