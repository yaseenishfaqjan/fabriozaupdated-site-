# -*- coding: utf-8 -*-
"""Build /custom-equestrian-apparel from the proven sport-page template.

    python -I tools/build-equestrian-page.py            # dry run
    python -I tools/build-equestrian-page.py --apply

It CLONES dist/custom-cheer-uniforms/index.html, which is the newest and
best-performing of the sport-page templates, and replaces the head and the
content sections. The <style>, the quote form, the footer and the scripts
come across untouched so the new page cannot drift from the others.

THE RISK THIS SCRIPT IS BUILT AROUND: cloning a page and editing the visible
copy leaves the SOURCE page's invisible parts behind. That has already
happened on this site - the UK product pages shipped with the UK landing
page's Service description, its breadcrumb and five FAQ questions that were
not on the page at all, which is a structured-data violation nobody can see
in a browser. So the head is rebuilt from scratch rather than patched, and
the script refuses to write if any cheer-specific word survives anywhere.

PRODUCT SCOPE IS DELIBERATELY NARROW. The owner confirmed on 15 Nov 2026
that the factory makes the apparel set only - show shirts, base layers,
riding tights and breeches, club softshells, yard polos and team hoodies.
Saddle pads, horse rugs and riding gloves are NOT claimed. Sialkot is known
for all three, which makes it tempting; a page that wins an enquiry the
factory cannot fulfil costs more than the enquiry is worth.

NO PRICES ARE STATED. The template's table carries fabric, MOQ and lead
time only, and points at /pricing - the same choice every other sport page
makes. Equestrian breeches and show shirts are not line items in the
published price list, and inventing a number here would put a figure in
front of a buyer that nobody has agreed to honour.
"""
import io
import json
import pathlib
import re
import sys

sys.stdout = io.TextIOWrapper(sys.stdout.buffer, encoding='utf-8')

ROOT = pathlib.Path(__file__).resolve().parent.parent
DIST = ROOT / 'dist'
TEMPLATE = DIST / 'custom-cheer-uniforms' / 'index.html'
SLUG = 'custom-equestrian-apparel'
OUT = DIST / SLUG / 'index.html'
APPLY = '--apply' in sys.argv

TITLE = 'Custom Equestrian Apparel &amp; Riding Clothing Manufacturer | FABRIOZA'
DESC = ('Custom equestrian apparel: competition show shirts, riding tights and breeches, '
        'technical base layers, club softshells, yard polos and team hoodies for riding '
        'clubs, yards and equestrian brands. From 20 pieces per style, design mockup in '
        '48h, DDP delivery.')

CARDS = [
    ('Competition Show Shirts',
     'Long and short-sleeve show shirts with a stand collar, mesh side and underarm panels '
     'and a wicking finish. Club crest at the chest, rider name at the collar or cuff.'),
    ('Riding Tights &amp; Breeches',
     'Full-seat and knee-patch styles in four-way stretch, with silicone grip, a high '
     'contoured waist, phone pockets and elastic or sock-style bottoms.'),
    ('Technical Base Layers',
     'Close-fit long and short-sleeve base layers with flatlock seams, thumb loops and '
     'mesh venting - the layer riders live in from autumn through spring.'),
    ('Club Softshells &amp; Jackets',
     'Bonded softshells, lightweight showerproof jackets and padded gilets, cut for riding '
     'with a longer back and articulated sleeves. Embroidered club crests.'),
    ('Yard Polos &amp; Team Hoodies',
     'The everyday yard kit: piqué polos, hoodies and quarter-zips for staff, grooms, '
     'liveries and supporters, in the same colours as the competition range.'),
    ('Crests, Names &amp; Numbers',
     'Embroidery, 3D puff, sublimation and heat-transfer applied in-house. Club crest, '
     'sponsor logos, rider names and horse names on the same order.'),
]

SPECS = [
    ('Competition show shirt', 'Wicking poly piqué with mesh panels, 150-180 GSM', '20 pcs', '2-3 weeks'),
    ('Riding tights / breeches', 'Four-way stretch poly-spandex, 240-280 GSM, silicone grip', '20 pcs', '3-4 weeks'),
    ('Technical base layer', 'Poly-spandex, flatlock seams, 150-180 GSM', '20 pcs', '2-3 weeks'),
    ('Club softshell / jacket', 'Three-layer bonded softshell, 280-320 GSM', '20 pcs', '3-4 weeks'),
    ('Yard polo', 'Cotton or poly piqué, 180-220 GSM', '20 pcs', '2-3 weeks'),
    ('Team hoodie', 'Brushed-back French terry, 280-350 GSM', '20 pcs', '2-3 weeks'),
]

PHOTOS = [
    ('/images/eq-show-shirts.jpg',
     'Custom equestrian competition show shirts in green and white, flat lay'),
    ('/images/eq-breeches-tights.jpg',
     'Custom riding breeches and knee-patch riding tights in green and white, flat lay'),
    ('/images/eq-jacket-baselayer.jpg',
     'Custom equestrian club softshell jacket and technical base layer, flat lay'),
]

FAQ = [
    ('What is the minimum order for custom equestrian apparel?',
     'Twenty pieces per style. A riding club ordering show shirts for its members, or a '
     'new equestrian brand testing one breech style, both clear that comfortably - you do '
     'not need to commit to a full container to start.'),
    ('Can you make full-seat and knee-patch breeches?',
     'Yes, both. Silicone grip is applied as a printed pattern in the seat or at the knee, '
     'in a colour that matches or contrasts with the fabric. Tell us which discipline the '
     'breeches are for and we will suggest the grip layout riders in it expect.'),
    ('Can you put our club crest and riders’ names on?',
     'Yes. Crests are usually embroidered, and names, horse names and sponsor logos can be '
     'embroidered, sublimated or heat-applied on the same order. Send the crest in any '
     'format you have and we will redraw it for stitching at no charge.'),
    ('What fabrics do you use for show shirts?',
     'A wicking poly piqué at 150-180 GSM with mesh side and underarm panels. If you want '
     'a heavier or a softer handle, or a specific stretch, say so on the enquiry - the '
     'fabric is chosen per order rather than fixed.'),
    ('Can you match our club or brand colours?',
     'Yes. Send a Pantone reference and we match to it. Without one we will match to a '
     'physical sample or a printed swatch you post to us, and confirm with a lab dip '
     'before bulk production starts.'),
    ('How long does production take?',
     'Two to four weeks depending on the garment, after the sample is approved. Breeches '
     'and softshells sit at the longer end because of the grip application and the bonded '
     'fabric. Shipping is on top and depends on the method you choose.'),
    ('Can we order a sample before committing?',
     'Yes, and we recommend it for breeches in particular, where fit is the whole product. '
     'See <a href="/sample-order" style="color:#4A7C59">trial orders</a> for how that works.'),
    ('Do you ship to the UK, EU and United States?',
     'Yes, DDP - duty and tax are included in the quote so the price you approve is the '
     'price you pay. See the <a href="/clothing-manufacturer-uk" style="color:#4A7C59">UK</a>, '
     '<a href="/clothing-manufacturer-germany" style="color:#4A7C59">Germany and EU</a> and '
     '<a href="/clothing-manufacturer-usa" style="color:#4A7C59">USA</a> pages for the duty '
     'position in each.'),
]


def strip_tags(x):
    return re.sub(r'<[^>]+>', '', x)


def build_head():
    faq_schema = [{
        "@type": "Question",
        "name": strip_tags(q),
        "acceptedAnswer": {"@type": "Answer", "text": strip_tags(a)}
    } for q, a in FAQ]

    graph = [
        {"@type": "Organization", "@id": "https://fabrioza.com/#organization",
         "name": "FABRIOZA", "url": "https://fabrioza.com",
         "logo": "https://fabrioza.com/images/fabrioza-logo.jpg",
         "sameAs": ["https://www.facebook.com/fabriozadotcom",
                    "https://www.instagram.com/fabriozadotcom",
                    "https://www.linkedin.com/company/fabrioza/",
                    "https://www.tiktok.com/@fabriozadotcom"]},
        {"@type": "Service",
         "@id": "https://fabrioza.com/%s#service" % SLUG,
         "name": "Custom Equestrian Apparel Manufacturing",
         "serviceType": "Custom riding apparel, show shirt, breech and club kit manufacturing",
         "description": strip_tags(DESC).replace('&amp;', '&'),
         "provider": {"@id": "https://fabrioza.com/#organization"},
         "areaServed": ["United States", "Canada", "United Kingdom", "Ireland",
                        "Germany", "France", "Netherlands", "Australia",
                        "New Zealand", "United Arab Emirates"],
         "hasOfferCatalog": {
             "@type": "OfferCatalog",
             "name": "Custom equestrian apparel",
             "itemListElement": [
                 {"@type": "Offer",
                  "itemOffered": {"@type": "Service", "name": strip_tags(n).replace('&amp;', '&')},
                  "eligibleQuantity": {"@type": "QuantitativeValue",
                                       "value": 20, "unitText": "pieces per style"}}
                 for n, _ in CARDS]}},
        {"@type": "FAQPage",
         "@id": "https://fabrioza.com/%s#faq" % SLUG,
         "mainEntity": faq_schema},
        {"@type": "BreadcrumbList",
         "@id": "https://fabrioza.com/%s#breadcrumb" % SLUG,
         "itemListElement": [
             {"@type": "ListItem", "position": 1, "name": "Home",
              "item": "https://fabrioza.com/"},
             {"@type": "ListItem", "position": 2, "name": "Sportswear",
              "item": "https://fabrioza.com/sportswear-catalog"},
             {"@type": "ListItem", "position": 3, "name": "Custom Equestrian Apparel",
              "item": "https://fabrioza.com/%s" % SLUG}]},
    ]
    ld = json.dumps({"@context": "https://schema.org", "@graph": graph},
                    indent=1, ensure_ascii=False)
    return """<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>%s</title>
<meta name="description" content="%s">
<meta name="robots" content="index, follow">
<link rel="canonical" href="https://fabrioza.com/%s">
<link rel="icon" type="image/jpeg" href="/images/fabrioza-logo-sm.jpg">
<meta property="og:type" content="website">
<meta property="og:url" content="https://fabrioza.com/%s">
<meta property="og:title" content="%s">
<meta property="og:description" content="%s">
<meta property="og:image" content="https://fabrioza.com/images/sw-equestrian.jpg">
<meta property="og:site_name" content="FABRIOZA">
<script type="application/ld+json">
%s
</script>
""" % (TITLE, DESC, SLUG, SLUG, TITLE, DESC, ld)


def build_body():
    cards = '\n'.join(
        '<div class="card"><h3>%s</h3><p>%s</p></div>' % (n, d) for n, d in CARDS)
    rows = '\n'.join(
        '<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>' % r for r in SPECS)
    photos = '\n'.join(
        '<figure style="margin:0"><img src="%s" alt="%s" loading="lazy" width="1344" '
        'height="768" style="width:100%%;height:auto;border-radius:12px;display:block"></figure>'
        % (src, alt) for src, alt in PHOTOS)
    faqs = '\n'.join(
        '<div class="faq-item"><h3>%s</h3><p>%s</p></div>' % (q, a) for q, a in FAQ)

    return """
<section class="hero">
  <div class="container">
    <h1>Custom Equestrian Apparel &amp; Riding Clothing</h1>
    <p>Competition show shirts, breeches and riding tights, base layers and club softshells for
    <strong style="color:#fff">riding clubs, yards, trainers and equestrian brands</strong>.
    Four-way stretch where it matters, silicone grip where you ask for it, your crest stitched in.</p>
    <a href="/?from=/%(slug)s#contact" class="btn-primary">Get an Equestrian Quote in 24h</a>
  </div>
</section>

<section class="section">
  <div class="container">
    <h2>Everything a Yard or Riding Brand Needs</h2>
    <div class="grid-3">
%(cards)s
    </div>
  </div>
</section>

<section class="section" style="background:#fff">
  <div class="container">
    <h2>The Range</h2>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:18px;max-width:1100px;margin:0 auto">
%(photos)s
    </div>
    <p style="text-align:center;margin-top:14px;font-size:13.5px;color:#666">
      Shown in our own colours to illustrate construction and finish. Every order is made in
      your colours, with your crest and your trims.</p>
  </div>
</section>

<section class="section">
  <div class="container">
    <h2>Specs &amp; Terms</h2>
    <div style="overflow-x:auto">
    <table>
      <thead><tr><th>Item</th><th>Fabric / spec</th><th>MOQ</th><th>Lead time</th></tr></thead>
      <tbody>
%(rows)s
      </tbody>
    </table>
    </div>
    <p style="text-align:center;margin-top:14px;font-size:13.5px">Per-style quotes in 24 hours;
    comparable garments are costed on our <a href="/pricing" style="color:#4A7C59">published pricing page</a>.</p>
  </div>
</section>

<section class="section" style="background:#fff">
  <div class="container">
    <h2>Why Clubs and Equestrian Brands Order Factory-Direct</h2>
    <div style="max-width:760px;margin:0 auto;font-size:15px">
<p style="margin-bottom:14px">Riding kit is bought in small numbers and worn hard. A club needs
twenty show shirts in its own colours, not two hundred; a new brand needs one breech style proved
on real riders before it commits to a size run. Most suppliers answer both with a catalogue
garment and a printed logo, because their minimums leave no room for anything else.</p>
<p>Ordering direct from the factory changes what is negotiable: the fabric weight, the grip
layout, the length of the back hem, where the crest sits. Start with a
<a href="/sample-order" style="color:#4A7C59">trial order</a>, or look at the wider
<a href="/sportswear-catalog" style="color:#4A7C59">sportswear range</a> if you also kit out
other teams.</p>
    </div>
  </div>
</section>

<section class="section">
  <div class="container">
    <h2>Questions</h2>
    <div style="max-width:760px;margin:0 auto">
%(faqs)s
    </div>
  </div>
</section>

<section class="cta-section">
  <div class="container">
    <h2>Kit Your Yard or Launch Your Riding Brand</h2>
    <p>Send your colours and crest - design mockup in 48 hours, from 20 pieces per style, DDP to your door.</p>
    <a href="/?from=/%(slug)s#contact" class="btn-primary">Get Your Equestrian Quote</a>
  </div>
</section>

<div style="max-width:900px;margin:26px auto;padding:0 20px;text-align:center;font-size:14px">
  <p>Related ranges: <a href="/custom-golf-polos" style="color:#4A7C59;font-weight:600">Golf polos</a>
  &middot; <a href="/activewear-manufacturer" style="color:#4A7C59;font-weight:600">Activewear</a>
  &middot; <a href="/custom-teamwear" style="color:#4A7C59;font-weight:600">Teamwear</a>
  &middot; <a href="/sportswear-catalog" style="color:#4A7C59;font-weight:600">All sportswear</a></p>
</div>
""" % {'slug': SLUG, 'cards': cards, 'rows': rows, 'photos': photos, 'faqs': faqs}


# ---------------------------------------------------------------- assemble
src = open(TEMPLATE, encoding='utf-8', newline='').read()

style_start = src.find('<style')
assert style_start != -1, 'no <style> in the template'
head_tail_start = style_start                      # keep <style> onwards

body_open = src.find('</header>')
assert body_open != -1, 'no </header> in the template'
content_start = body_open + len('</header>')

lq = src.find('<section class="lq-section')
assert lq != -1, 'no quote form in the template'

page = build_head() + src[head_tail_start:content_start] + build_body() + src[lq:]

# the cloned tail still points at the template's own page
page = page.replace('custom-cheer-uniforms', SLUG)

# ---- refuse to ship the source page's content ---------------------------
BANNED = ['cheer', 'squad', 'pom ', 'rhinestone', 'bodysuit', 'all-star', 'sequin']
leaks = sorted({w for w in BANNED if re.search(re.escape(w), page, re.I)})
assert not leaks, ('the template\'s own content survived: %s - a cloned page that keeps the '
                   'source\'s copy or schema is the exact fault the UK pages shipped' % leaks)

# the schema must parse, and must describe THIS page
for blob in re.findall(r'<script type="application/ld\+json">(.*?)</script>', page, re.S):
    data = json.loads(blob)
    assert SLUG in json.dumps(data), 'schema does not reference this page'

for must in ['/images/eq-show-shirts.jpg', '/images/sw-equestrian.jpg',
             'canonical" href="https://fabrioza.com/' + SLUG,
             '?from=/' + SLUG + '#contact', 'lq-section', '</html>']:
    assert must in page, 'missing from the built page: %s' % must

assert page.count('<h1') == 1, 'expected exactly one h1, got %d' % page.count('<h1')

print('built %s  (%d bytes, %d FAQ, %d cards, %d photos)'
      % (SLUG, len(page), len(FAQ), len(CARDS), len(PHOTOS)))
print('  no template content leaked; schema parses and names this page')

if APPLY:
    OUT.parent.mkdir(parents=True, exist_ok=True)
    open(OUT, 'w', encoding='utf-8', newline='').write(page)
    print('APPLIED -> %s' % OUT.relative_to(ROOT))
else:
    print('DRY RUN - nothing written. Pass --apply.')
