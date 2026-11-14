# -*- coding: utf-8 -*-
"""Convert the 48 equestrian renders to the configurator's photo format and
derive GARMENT_BOX / PHOTO_BOX for the four new garments.

THE PHOTO IS PLACED AS <image x=0 y=0 width=400 height=400> inside a
400x440 viewBox, so a pixel in the 900x900 photo is simply px * 400/900
in canvas units. Everything below falls out of that one fact.

GARMENT_BOX IS MEASURED FROM THE BLACK COLOURWAY ONLY, and that is not
laziness. The box is per product per view, not per colour - the existing
fourteen products each have one - and a threshold against the background
is reliable on a dark garment and hopeless on a pale one: measuring the
off-white show shirt against a 218-grey background finds almost nothing,
which is exactly how an earlier attempt in this session mis-measured a
polo as 42,000 pixels when it is 370,000. Black against 218-grey is
unambiguous, and the generations share a locked geometry.

THE SOFT SHADOW under each garment would inflate the box if a bare
threshold were used, so a row or column only counts once at least
MIN_RUN pixels in it clear the threshold. Shadows are gradual and
low-contrast; garment edges are neither.

PHOTO_BOX IS NOT INVENTED. Measured across the fourteen existing
products, every placement box is a fixed proportion of that product's
own garment box, identical front and back (cx and w have a standard
deviation of 0.001 or less). Each new garment therefore borrows the
ratios of its nearest existing analogue - a show shirt from the polo, a
softshell from the fleece-lined jacket, breeches from the tapered
joggers, a base layer from the long-sleeve tee - rather than from a
global mean, because a hoodie's back print sits lower than a tee's and
averaging the two would split the difference for no reason.
"""
import io
import json
import os
import pathlib
import re
import sys

import numpy as np
from PIL import Image

sys.stdout = io.TextIOWrapper(sys.stdout.buffer, encoding='utf-8')

# The 2048px renders live outside the repo (~6 MB each). Set EQ_RENDERS to
# wherever they are if these ever need rebuilding; the measurement and
# conversion below are the part worth keeping.
SRC = pathlib.Path(os.environ.get('EQ_RENDERS') or pathlib.Path(__file__).resolve().parent)
ROOT = pathlib.Path(__file__).resolve().parent.parent
OUT = ROOT / 'dist' / 'images' / 'design'
PAGE = ROOT / 'dist' / 'design' / 'index.html'

SCALE = 400.0 / 900.0      # photo px -> canvas units
SIZE = 900                 # every existing design photo is 900x900
THRESH = 60                # colour distance from background
MIN_RUN = 6                # px in a row/column before it counts as garment

WANT = {
    'show-shirt':      ["off-white", "navy", "black", "burgundy", "bottle-green", "royal-blue"],
    'riding-breeches': ["off-white", "navy", "black", "charcoal", "burgundy", "sand"],
    'club-softshell':  ["navy", "black", "charcoal", "burgundy", "bottle-green", "royal-blue"],
    'base-layer':      ["off-white", "navy", "black", "charcoal", "burgundy", "bottle-green"],
}
ANALOGUE = {
    'show-shirt':      'polo-shirt',
    'riding-breeches': 'tapered-joggers',
    'club-softshell':  'fleece-lined-jacket',
    'base-layer':      'long-sleeve-tee',
}
PLACEMENTS = {
    'show-shirt':      ['left-chest', 'full-front', 'back', 'sleeve'],
    'riding-breeches': ['back', 'left-leg'],
    'club-softshell':  ['left-chest', 'full-front', 'back', 'sleeve'],
    'base-layer':      ['left-chest', 'full-front', 'back', 'sleeve'],
}

APPLY = '--apply' in sys.argv


# ------------------------------------------------------------ 1. convert
def convert(src, dst):
    im = Image.open(src).convert('RGB')
    if im.size != (SIZE, SIZE):
        im = im.resize((SIZE, SIZE), Image.LANCZOS)
    # Quality chosen to land inside the existing files' 7-15 KB band; the
    # whole set is served immutable for a year, so weight matters once.
    for q in (82, 76, 70, 64):
        buf = io.BytesIO()
        im.save(buf, 'WEBP', quality=q, method=6)
        if buf.tell() <= 16000:
            break
    if APPLY:
        dst.write_bytes(buf.getvalue())
    return buf.tell(), q


sizes = []
for slug, cols in WANT.items():
    for col in cols:
        for suf in ('', '--back'):
            name = '%s--%s%s' % (slug, col, suf)
            src = SRC / (name + '.png')
            assert src.exists(), 'missing render: %s' % name
            n, q = convert(src, OUT / (name + '.webp'))
            sizes.append(n)
print('converted %d photos  %d-%d bytes (mean %d)'
      % (len(sizes), min(sizes), max(sizes), sum(sizes) // len(sizes)))


# ------------------------------------------------------- 2. garment box
def garment_box(path):
    """Bounding box of the garment in CANVAS units, shadow excluded."""
    # Measure on the SIZE x SIZE image that is actually shipped, not the
    # 2048px render: SCALE maps 900 -> 400 and nothing else.
    im = Image.open(path).convert('RGB')
    if im.size != (SIZE, SIZE):
        im = im.resize((SIZE, SIZE), Image.LANCZOS)
    a = np.asarray(im).astype(float)
    bg = np.concatenate([a[:14, :].reshape(-1, 3), a[-14:, :].reshape(-1, 3),
                         a[:, :14].reshape(-1, 3), a[:, -14:].reshape(-1, 3)]).mean(0)
    # THRESH is 60, not a tighter value, because the softshell renders
    # carry a background gradient: that image's top-left corner sits 38.6
    # from its own background mean while the show shirt's corners sit
    # under 1.0, so a threshold of 38 read the gradient as garment and
    # returned a box starting at [0,0]. Verified insensitive - every box
    # moves under 2.7 canvas units between THRESH 60 and 85.
    hit = np.sqrt(((a - bg) ** 2).sum(2)) > THRESH
    rows = np.where(hit.sum(1) >= MIN_RUN)[0]
    cols = np.where(hit.sum(0) >= MIN_RUN)[0]
    assert len(rows) and len(cols), 'nothing found in %s' % path
    x0, x1 = cols[0], cols[-1] + 1
    y0, y1 = rows[0], rows[-1] + 1
    return [round(v * SCALE, 1) for v in (x0, y0, x1, y1)], hit


GB = {'front': {}, 'back': {}}
for slug in WANT:
    for view, suf in (('front', ''), ('back', '--back')):
        # black is the one colourway where the threshold cannot lie
        box, _ = garment_box(SRC / ('%s--black%s.png' % (slug, suf)))
        GB[view][slug] = box
        print('%-18s %-5s  %s  (%.0f x %.0f canvas units)'
              % (slug, view, box, box[2] - box[0], box[3] - box[1]))


# --------------------------------------------- 3. placement boxes by ratio
s = open(PAGE, encoding='utf-8', newline='').read()
old_gb = json.loads(re.search(r'var GARMENT_BOX=(\{.*?\});', s, re.S).group(1))
old_pb = json.loads(re.search(r'var PHOTO_BOX=(\{.*?\});', s, re.S).group(1))

PB = {'front': {}, 'back': {}}
for slug, cols in WANT.items():
    ana = ANALOGUE[slug]
    for view in ('front', 'back'):
        g = old_gb[view][ana]
        agw, agh = g[2] - g[0], g[3] - g[1]
        n = GB[view][slug]
        ngw, ngh = n[2] - n[0], n[3] - n[1]
        out = {}
        for pl in PLACEMENTS[slug]:
            b = old_pb[view][ana][pl]
            cx = (b['x'] + b['w'] / 2 - g[0]) / agw      # ratios of the analogue
            cy = (b['y'] + b['h'] / 2 - g[1]) / agh
            w = b['w'] / agw
            nw = w * ngw
            out[pl] = {'x': round(n[0] + cx * ngw - nw / 2, 1),
                       'y': round(n[1] + cy * ngh - nw / 2, 1),
                       'w': round(nw, 1), 'h': round(nw, 1)}
        PB[view][slug] = out

# Every box must sit inside its garment, or clampBox() will shunt it on
# first render and the preset the buyer sees is not the preset we set.
for view in ('front', 'back'):
    for slug, pls in PB[view].items():
        g = GB[view][slug]
        for pl, b in pls.items():
            assert b['x'] >= g[0] - .05 and b['y'] >= g[1] - .05 \
                and b['x'] + b['w'] <= g[2] + .05 and b['y'] + b['h'] <= g[3] + .05, \
                '%s %s %s escapes the garment: %s vs %s' % (slug, view, pl, b, g)
print('placement boxes derived and bounds-checked: %d'
      % sum(len(v) for d in PB.values() for v in d.values()))

if APPLY:
    for view in ('front', 'back'):
        old_gb[view].update(GB[view])
        old_pb[view].update(PB[view])
    s = re.sub(r'var GARMENT_BOX=\{.*?\};',
               'var GARMENT_BOX=' + json.dumps(old_gb, separators=(',', ':')) + ';', s, count=1, flags=re.S)
    s = re.sub(r'var PHOTO_BOX=\{.*?\};',
               'var PHOTO_BOX=' + json.dumps(old_pb, separators=(',', ':')) + ';', s, count=1, flags=re.S)
    open(PAGE, 'w', encoding='utf-8', newline='').write(s)
    print('APPLIED to', PAGE.name)
else:
    print('DRY RUN - nothing written. Pass --apply.')
