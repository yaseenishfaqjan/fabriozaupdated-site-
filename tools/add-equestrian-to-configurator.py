# -*- coding: utf-8 -*-
"""Add four equestrian garments to the /design configurator.

WHAT IS NEW, and why these four and not the six on
/custom-equestrian-apparel: that page sells show shirts, breeches, base
layers, softshells, yard polos and team hoodies. The last two are already
in this configurator as "Polo shirt" and "Pullover hoodie" - adding them
again under equestrian names would be two more products, forty more
photographs and no new capability. The other four have no equivalent
here: a show shirt is not a polo, breeches are not joggers, a bonded
softshell is not a fleece-lined jacket, and a base layer is not a
long-sleeve tee - each has its own fabric and its own GSM band on that
page's specs table, which is what the tech pack has to carry.

THREE THINGS THAT WOULD SILENTLY BREAK, all handled below:

1. THE DECORATION CLIP IS CHOSEN BY SHAPE, and it was an inline ternary:
       (shape==='joggers')?JOGGERS:((shape==='tee'||shape==='polo')?TEE:TOP)
   Any shape it does not name falls to TOP, the torso silhouette. That is
   right for a softshell and WRONG for breeches - the logo would be
   clipped to a chest on a pair of legs, and nothing would error. A new
   shape had to find and extend that expression. It is a lookup now, so
   adding a shape is one entry in one place.

2. COLOURS ARE GLOBAL BUT PHOTOS ARE NOT. photoSrc() builds
   /images/design/<slug>--<colour>.webp, so every product needed all ten
   colours shot or the preview breaks. The new garments carry six each,
   chosen for what is actually made: there are no royal blue competition
   breeches, and sand is the classic breeches colour no top needs. The
   swatch row is filtered per garment, which keeps the preview honest AND
   stops us quoting a garment nobody cuts.

3. data-i ON EACH SWATCH IS AN INDEX INTO D.colours, read by the click
   handler. The filtered row therefore keeps the GLOBAL index on every
   button rather than renumbering 0..n, so that handler is untouched.

No prices here. GSM and fabric come straight off the specs table on
/custom-equestrian-apparel; the quote is still priced by a human.
"""
import io
import json
import pathlib
import re
import sys

sys.stdout = io.TextIOWrapper(sys.stdout.buffer, encoding='utf-8')

P = pathlib.Path(__file__).resolve().parent.parent / 'dist' / 'design' / 'index.html'
s = open(P, encoding='utf-8', newline='').read()
assert 'colourByShape' not in s, 'already applied'

# ---------------------------------------------------------------- 1. data
m = re.search(r'var D=(\{.*?\});', s, re.S)
assert m, 'D literal not found'
raw = m.group(1)
D = json.loads(raw)
# The literal must round-trip byte-identically or this rewrite would
# reformat 7 KB of untouched data and bury the real change in the diff.
assert json.dumps(D, separators=(',', ':'), ensure_ascii=False) == raw, 'D does not round-trip'

before_products = len(D['products'])
D['products'] += [
    {"slug": "show-shirt",      "name": "Competition show shirt",  "shape": "show-shirt"},
    {"slug": "riding-breeches", "name": "Riding tights / breeches", "shape": "breeches"},
    {"slug": "club-softshell",  "name": "Club softshell jacket",   "shape": "softshell"},
    {"slug": "base-layer",      "name": "Technical base layer",    "shape": "base-layer"},
]

# poly:1 on all three - it is what makes Sublimation selectable, and
# sublimation onto polyester is genuinely how club teamwear is printed.
D['fabrics'] += [
    {"slug": "wicking-poly-pique", "name": "Wicking poly pique", "poly": 1},
    {"slug": "four-way-stretch",   "name": "Four-way stretch poly/spandex", "poly": 1},
    {"slug": "bonded-softshell",   "name": "Three-layer bonded softshell", "poly": 1},
]

# Which placements each new shape offers. Breeches get back and left-leg
# only, exactly as joggers do - there is no chest on a pair of breeches.
PLACEMENT_SHAPES = {
    'left-chest': ['show-shirt', 'softshell', 'base-layer'],
    'full-front': ['show-shirt', 'softshell', 'base-layer'],
    'back':       ['show-shirt', 'softshell', 'base-layer', 'breeches'],
    'sleeve':     ['show-shirt', 'softshell', 'base-layer'],
    'left-leg':   ['breeches'],
}
for pl in D['placements']:
    for add in PLACEMENT_SHAPES.get(pl['slug'], []):
        assert add not in pl['shapes'], '%s already on %s' % (add, pl['slug'])
        pl['shapes'].append(add)

# Straight off the specs table on /custom-equestrian-apparel.
D['gsm'].update({
    "show-shirt": [150, 165, 180],
    "breeches":   [240, 260, 280],
    "softshell":  [280, 300, 320],
    "base-layer": [150, 165, 180],
})
D['fabricByShape'].update({
    "show-shirt": ["wicking-poly-pique", "performance-poly", "cotton-pique", "poly-cotton"],
    "breeches":   ["four-way-stretch", "performance-poly"],
    "softshell":  ["bonded-softshell", "performance-poly"],
    "base-layer": ["four-way-stretch", "performance-poly", "poly-cotton"],
})

# Only shapes listed here are restricted; everything else keeps all ten.
# Every combination named here has a photograph - the check at the bottom
# of build-equestrian-configurator.py enforces that, because a colour
# offered without a photo renders an empty garment.
D['colourByShape'] = {
    "show-shirt": ["off-white", "navy", "black", "burgundy", "bottle-green", "royal-blue"],
    "breeches":   ["off-white", "navy", "black", "charcoal", "burgundy", "sand"],
    "softshell":  ["navy", "black", "charcoal", "burgundy", "bottle-green", "royal-blue"],
    "base-layer": ["off-white", "navy", "black", "charcoal", "burgundy", "bottle-green"],
}

s = s.replace(raw, json.dumps(D, separators=(',', ':'), ensure_ascii=False), 1)
print('products %d -> %d' % (before_products, len(D['products'])))

# ------------------------------------------------- 2. clip: ternary -> map
old_clip = "  var clip=(shape==='joggers')?JOGGERS:((shape==='tee'||shape==='polo')?TEE:TOP);"
assert s.count(old_clip) == 1, 'clip anchor: %d' % s.count(old_clip)
new_clip = (
    "  /* Which silhouette the decoration is clipped to. A shape that is not\n"
    "     listed falls to TOP, which is right for jackets, hoodies and the new\n"
    "     softshell and WRONG for legwear - breeches clipped to a torso put the\n"
    "     logo in mid-air, with nothing thrown and nothing logged. This was an\n"
    "     inline ternary that a new shape had to find and extend; as a lookup,\n"
    "     adding a shape is one entry in one place. */\n"
    "  var clip=CLIP_BY_SHAPE[shape]||TOP;"
)
s = s.replace(old_clip, new_clip, 1)

# The map must be declared after TOP/TEE/JOGGERS exist.
anchor = "var JOGGERS='M128 58 L272 58 L290 404 L214 404 L200 170 L186 404 L110 404 Z';"
assert s.count(anchor) == 1, 'JOGGERS anchor: %d' % s.count(anchor)
s = s.replace(anchor, anchor + "\nvar CLIP_BY_SHAPE={joggers:JOGGERS,breeches:JOGGERS,"
              "tee:TEE,polo:TEE,'show-shirt':TEE,'base-layer':TEE};", 1)

# --------------------------------------------- 3. per-garment colour row
old_build = """  $('#dzColour').innerHTML=D.colours.map(function(c,i){
    return '<button type="button" data-i="'+i+'" aria-pressed="'+(i===0?'true':'false')+'" title="'+c.name+'" aria-label="'+c.name+'" style="background:'+c.hex+'"></button>';
  }).join('');
  fillFabrics(); fillGsm(); fillPlacements(); render();"""
assert s.count(old_build) == 1, 'build anchor: %d' % s.count(old_build)
s = s.replace(old_build, "  fillColours(); fillFabrics(); fillGsm(); fillPlacements(); render();", 1)

fill_colours = '''/* Colours are per garment, like fabrics. Competition breeches are made
   in beige, white, navy, black, charcoal and burgundy - not royal blue -
   and no top is made in breeches beige. Offering the full row would quote
   a garment nobody cuts AND break the preview: photoSrc() builds
   /images/design/<slug>--<colour>.webp and there is no file for a
   combination we do not offer, so the stage would render an empty frame.
   Shapes absent from colourByShape keep all ten, which is every garment
   that was here before this. */
function fillColours(){
  var allowed=(D.colourByShape||{})[state.product.shape]||D.colours.map(function(c){return c.slug});
  var keep=state.colour&&allowed.indexOf(state.colour.slug)>-1?state.colour:null;
  state.colour=keep||D.colours.filter(function(c){return allowed.indexOf(c.slug)>-1})[0]||D.colours[0];
  /* data-i stays the GLOBAL index into D.colours: the click handler reads
     it to look the colour up, so a filtered row must not renumber. */
  $('#dzColour').innerHTML=D.colours.map(function(c,i){
    if(allowed.indexOf(c.slug)<0) return '';
    return '<button type="button" data-i="'+i+'" aria-pressed="'+(c.slug===state.colour.slug?'true':'false')+'" title="'+c.name+'" aria-label="'+c.name+'" style="background:'+c.hex+'"></button>';
  }).join('');
}
function build(){'''
assert s.count('function build(){') == 1, 'build fn anchor'
s = s.replace('function build(){', fill_colours, 1)

old_change = ("$('#dzProduct').addEventListener('change',function(e){state.product=D.products[+e.target.value];"
              "fillFabrics();fillGsm();fillPlacements();render()});")
assert s.count(old_change) == 1, 'product change anchor: %d' % s.count(old_change)
s = s.replace(old_change, ("$('#dzProduct').addEventListener('change',function(e){state.product=D.products[+e.target.value];"
                           "fillColours();fillFabrics();fillGsm();fillPlacements();render()});"), 1)

open(P, 'w', encoding='utf-8', newline='').write(s)
d = P.read_bytes()
crlf = d.count(bytes([13, 10]))
print('patched /design  (%d bytes)' % len(d))
print('line endings: CRLF %d  LF %d' % (crlf, d.count(bytes([10])) - crlf))
