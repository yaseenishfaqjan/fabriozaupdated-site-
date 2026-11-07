# -*- coding: utf-8 -*-
"""Build a product x UK landing page from the existing UK page as template.

    python -I tools/build-uk-product-page.py hoodie
    python -I tools/build-uk-product-page.py hoodie --write

WHY A PRODUCT x UK PAGE AT ALL. /custom-hoodie-manufacturer carries no UK
angle and /clothing-manufacturer-uk carries no product angle, so the query
that combines them is uncovered. That is only worth a page if the page
adds what neither parent has - here, the garment's own commodity code and
duty treatment, and real prices at the quantities a UK brand actually
starts with. Without that it is a doorway page, which hurts.

PRICES ARE PARSED FROM /pricing, NEVER TYPED. The build asserts every
price it emits appears on that page, so the two cannot drift. This repo
has been bitten repeatedly by a second copy of a number.

USD, NOT GBP, DELIBERATELY. The whole site quotes USD (81 price tokens on
/pricing, no GBP). Converting here would invent an exchange rate that goes
stale silently and give the site two prices for one garment.

THE DUTY WORDING IS REUSED FROM THE UK PAGE, NOT REWRITTEN. That page
already states the DCTS position carefully - 92% of lines rather than all,
the 1 Jan 2026 rule change, duty is not VAT. Authoring a second version of
a trade-policy claim is how two pages end up disagreeing about something a
buyer will act on.
"""
import html
import io
import pathlib
import re
import sys

sys.stdout = io.TextIOWrapper(sys.stdout.buffer, encoding='utf-8')

ROOT = pathlib.Path(__file__).resolve().parent.parent
DIST = ROOT / 'dist'
TEMPLATE = DIST / 'clothing-manufacturer-uk' / 'index.html'
PRICING = DIST / 'pricing' / 'index.html'

PRODUCTS = {
    'hoodie': {
        'slug': 'hoodie-manufacturer-uk',
        'h1': 'Hoodie Manufacturer for UK Brands',
        'title': 'Hoodie Manufacturer for UK Brands | 0% Duty, MOQ from 20 | FABRIOZA',
        'desc': ('Custom hoodies and sweatshirts for UK brands: 280-350gsm French terry '
                 'and fleece, MOQ from 20 pieces, 0% UK import duty under DCTS, DDP '
                 'delivery. Real prices and commodity codes, not estimates.'),
        'rows': ['Premium Hoodie', 'Crewneck Sweatshirt'],
        'hs': '6110',
        'hs_label': 'Jerseys, pullovers, cardigans and sweatshirts, knitted or crocheted',
        'gsm': '280-350gsm',
        'tag': 'Hoodies &amp; Sweatshirts',
        # The garment, named properly. Deriving a plural from the H1 produced
        # "hoodie manufacturers" in the FAQ and the CTA - a page asking
        # whether you pay duty on manufacturers rather than on clothes.
        'noun': 'hoodies and sweatshirts',
        'noun_short': 'hoodies',
        'hero_img': '/images/cat-hoodies-hero.jpg',
        'hero_alt': 'Custom hoodies and sweatshirts manufactured for UK brands',
    },
    'tracksuit': {
        'slug': 'tracksuit-manufacturer-uk',
        'h1': 'Tracksuit Manufacturer for UK Brands',
        'title': 'Tracksuit Manufacturer for UK Brands | 0% Duty, MOQ from 20 | FABRIOZA',
        'desc': ('Custom tracksuits for UK brands: matching zip jacket and joggers, '
                 'MOQ from 20 sets, 0% UK import duty under DCTS, DDP delivery. Real '
                 'prices and commodity codes, not estimates.'),
        'rows': ['Tracksuit Set'],
        'hs': '6112',
        'hs_label': 'Track suits, ski suits and swimwear, knitted or crocheted',
        'gsm': '280-340gsm',
        'tag': 'Tracksuits',
        'noun': 'tracksuits',
        'noun_short': 'tracksuits',
        'hero_img': '/images/sw-tracksuit.jpg',
        'hero_alt': 'Custom tracksuits manufactured for UK brands',
    },
    'streetwear': {
        'slug': 'streetwear-manufacturer-uk',
        'h1': 'Streetwear Manufacturer for UK Brands',
        'title': 'Streetwear Manufacturer for UK Brands | 0% Duty, MOQ from 20 | FABRIOZA',
        'desc': ('Custom streetwear for UK brands: garment-dyed acid wash tees, '
                 'heavyweight tees and oversized hoodies, MOQ from 20 pieces, 0% UK '
                 'import duty under DCTS, DDP delivery.'),
        'rows': ['Streetwear Acid Wash Tee', 'Heavyweight T-Shirt', 'Premium Hoodie'],
        # No single code: this page covers several garments, and a category
        # does not have one. Listed rather than invented.
        'hs': None,
        'hs_multi': ('Streetwear spans several commodity codes rather than one: tees sit '
                     'under <strong>HS 6109</strong>, hoodies and crews under '
                     '<strong>HS 6110</strong>, and knitted cargo trousers under '
                     '<strong>HS 6103</strong>. We confirm the code for each garment in '
                     'your range before quoting.'),
        'hs_label': '',
        'gsm': '180-350gsm',
        'tag': 'Streetwear',
        'noun': 'streetwear',
        'noun_short': 'streetwear',
        'hero_img': '/images/st-acidwash-tee.jpg',
        'hero_alt': 'Custom streetwear manufactured for UK brands',
    },
    'tshirt': {
        'slug': 't-shirt-manufacturer-uk',
        'h1': 'T-Shirt Manufacturer for UK Brands',
        'title': 'T-Shirt Manufacturer for UK Brands | 0% Duty, MOQ from 20 | FABRIOZA',
        'desc': ('Custom t-shirts for UK brands: 180-220gsm cotton, oversized and boxy '
                 'fits, MOQ from 20 pieces, 0% UK import duty under DCTS, DDP delivery. '
                 'Real prices and commodity codes, not estimates.'),
        'rows': ['Heavyweight T-Shirt'],
        'hs': '6109',
        'hs_label': 'T-shirts, singlets and other vests, knitted or crocheted',
        'gsm': '180-220gsm',
        'tag': 'T-Shirts',
        'noun': 't-shirts',
        'noun_short': 't-shirts',
        'hero_img': '/images/st-graphic-tee.jpg',
        'hero_alt': 'Custom t-shirts manufactured for UK brands',
    },
}


def price_rows():
    """Every row of the live price table, as a list of cell lists."""
    s = PRICING.read_text(encoding='utf-8')
    out = []
    for r in re.findall(r'<tr[^>]*>(.*?)</tr>', s, re.S):
        cells = [html.unescape(re.sub(r'<[^>]+>', ' ', c)).strip()
                 for c in re.findall(r'<t[hd][^>]*>(.*?)</t[hd]>', r, re.S)]
        cells = [re.sub(r'\s+', ' ', c) for c in cells if c != '']
        if cells:
            out.append(cells)
    return out


def main():
    if len(sys.argv) < 2 or sys.argv[1] not in PRODUCTS:
        print('usage: build-uk-product-page.py {%s} [--write]'
              % '|'.join(PRODUCTS))
        return 1
    key = sys.argv[1]
    write = '--write' in sys.argv
    P = PRODUCTS[key]

    rows = price_rows()
    header = next((r for r in rows if r and r[0].lower() == 'product'), None)
    assert header, 'could not find the price table header on /pricing'

    wanted = []
    for name in P['rows']:
        hit = next((r for r in rows if r[0].startswith(name)), None)
        assert hit, 'price row not found on /pricing: %s' % name
        wanted.append(hit)
    print('price rows taken from /pricing:')
    for r in wanted:
        print('  ' + ' | '.join(r))

    tpl = TEMPLATE.read_text(encoding='utf-8')

    # ---- the price table, built from the parsed rows -------------------
    ths = ''.join('<th>%s</th>' % html.escape(c) for c in header)
    trs = ''
    for r in wanted:
        name = r[0]
        # /pricing concatenates the product name and its spec with no space
        m = re.match(r'^(.*?)(\d{3}-\d{3}gsm.*)$', name)
        label = ('<strong>%s</strong><br><span class="spec">%s</span>'
                 % (html.escape(m.group(1).strip()), html.escape(m.group(2).strip()))
                 if m else '<strong>%s</strong>' % html.escape(name))
        cells = ''.join('<td>%s</td>' % html.escape(c) for c in r[1:])
        trs += '<tr><td>%s</td>%s</tr>' % (label, cells)

    table = ('<div class="table-wrap"><table><thead><tr>%s</tr></thead>'
             '<tbody>%s</tbody></table></div>' % (ths, trs))

    # every price we are about to publish must exist on /pricing
    pricing_src = PRICING.read_text(encoding='utf-8')
    emitted = re.findall(r'\$[0-9]+\.[0-9]{2}', table)
    missing = [p for p in emitted if p not in pricing_src]
    assert not missing, 'prices not found on /pricing: %s' % missing
    print('\n%d price token(s) emitted, all verified against /pricing' % len(emitted))

    out = build_page(tpl, P, table, len(emitted))
    dest = DIST / P['slug'] / 'index.html'
    if write:
        dest.parent.mkdir(parents=True, exist_ok=True)
        dest.write_text(out, encoding='utf-8', newline='')
        print('wrote %s (%d bytes)' % (dest.relative_to(ROOT).as_posix(), len(out)))
    else:
        print('\nDRY RUN - would write %s (%d bytes). Pass --write.'
              % (dest.relative_to(ROOT).as_posix(), len(out)))
    return 0


def build_page(tpl, P, table, nprices):
    s = tpl

    # ---- head ----------------------------------------------------------
    s = re.sub(r'<title>.*?</title>', '<title>%s</title>' % P['title'], s, count=1, flags=re.S)
    s = re.sub(r'(<meta name="description" content=")[^"]*(")',
               lambda m: m.group(1) + html.escape(P['desc'], quote=True) + m.group(2), s, count=1)
    s = re.sub(r'(<meta property="og:title" content=")[^"]*(")',
               lambda m: m.group(1) + html.escape(P['title'], quote=True) + m.group(2), s, count=1)
    s = re.sub(r'(<meta property="og:description" content=")[^"]*(")',
               lambda m: m.group(1) + html.escape(P['desc'], quote=True) + m.group(2), s, count=1)
    # the template writes these as property=, not name= - the first cut
    # targeted name= and silently left the UK page's title in the card
    for attr in ('name', 'property'):
        s = re.sub(r'(<meta %s="twitter:title" content=")[^"]*(")' % attr,
                   lambda m: m.group(1) + html.escape(P['title'], quote=True) + m.group(2), s, count=1)
        s = re.sub(r'(<meta %s="twitter:description" content=")[^"]*(")' % attr,
                   lambda m: m.group(1) + html.escape(P['desc'], quote=True) + m.group(2), s, count=1)
    s = s.replace('https://fabrioza.com/clothing-manufacturer-uk',
                  'https://fabrioza.com/' + P['slug'])

    # ---- h1 and category tag -------------------------------------------
    s = re.sub(r'(<h1[^>]*>).*?(</h1>)', lambda m: m.group(1) + P['h1'] + m.group(2),
               s, count=1, flags=re.S)
    s = re.sub(r'(<div class="category-tag">).*?(</div>)',
               lambda m: m.group(1) + P['tag'] + m.group(2), s, count=1, flags=re.S)

    # ---- hero: the template's own numbers are not this page's ----------
    # The inherited hero read "MOQ 50 pieces", contradicting both this page's
    # copy and the site-wide "from 20" promise - the same contradiction that
    # made the enquiry form unusable for small brands. Caught by looking at
    # the rendered page, not the HTML.
    s = re.sub(r'(<div class="meta">).*?(</div>)',
               lambda m: m.group(1) + 'MOQ from 20 pieces &middot; DDP shipping to the '
                         'United Kingdom &middot; Quotes in 24h' + m.group(2),
               s, count=1, flags=re.S)

    # the template's hero image is a tee; a hoodie page must not show one
    # NOTE the [^>]* - the div carries a style attribute, and a pattern
    # written as <div class="article-hero"> matches nothing. re.sub then
    # changes nothing and reports no error, so the page shipped a t-shirt
    # photo on the hoodie page. The assertions below are what turn a silent
    # no-op into a failed build.
    s = re.sub(r'(<div class="article-hero"[^>]*>\s*<img[^>]*src=")[^"]*(")',
               lambda m: m.group(1) + P['hero_img'] + m.group(2), s, count=1, flags=re.S)
    s = re.sub(r'(<div class="article-hero"[^>]*>\s*<img[^>]*alt=")[^"]*(")',
               lambda m: m.group(1) + html.escape(P['hero_alt'], quote=True) + m.group(2),
               s, count=1, flags=re.S)
    # Assert the RESULT, not that a substitution happened. The first version
    # checked "did the string change", which fails for a page whose hero is
    # already the template's - a correct outcome reported as an error.
    i = s.find('article-hero')
    hero = s[i:i + 320] if i != -1 else ''
    assert P['hero_img'] in hero, 'hero image is not %s' % P['hero_img']
    assert P['hero_alt'] in hero, 'hero alt was not replaced'

    # ---- .table-wrap has NO rule in this template ----------------------
    # Borrowing a class that does not exist is exactly what made the Spanish
    # price page scroll sideways on a phone. The styles ship with the page.
    css = ('<style>.table-wrap{overflow-x:auto;-webkit-overflow-scrolling:touch;'
           'margin:24px 0}.table-wrap table{margin:0;min-width:640px}'
           '.table-wrap .spec{font-weight:400;font-size:.85em;color:#667}'
           'p.note{font-size:.93em;color:#555}</style>')
    s = s.replace('</head>', css + '</head>', 1)

    # ---- body: replace everything inside .content ----------------------
    start = s.find('<div class="content">')
    assert start != -1, 'content div not found in the template'
    end = s.find('<div class="related-posts">', start)
    if end == -1:
        end = s.find('<footer', start)
    assert end != -1, 'could not find the end of the content block'

    s = s[:start] + '<div class="content">' + body_html(P, table) + '</div>' + s[end:]
    s = rebuild_schema(s, P)
    return s


def rebuild_schema(s, P):
    """Replace the inherited structured data with this page's own.

    Inheriting it shipped the UK page's Service description, its breadcrumb
    and five FAQ questions that are not on this page. Google treats FAQPage
    markup that does not match the visible page as a violation, so this is
    a correctness fix, not tidying."""
    import json as _json
    m = re.search(r'(<script type="application/ld\+json">)(.*?)(</script>)', s, re.S)
    assert m, 'no JSON-LD block in the template'
    data = _json.loads(m.group(2))
    graph = data.get('@graph') if isinstance(data, dict) else None
    nodes = graph if graph is not None else (data if isinstance(data, list) else [data])

    url = 'https://fabrioza.com/' + P['slug']
    for node in nodes:
        t = node.get('@type')
        if t == 'Service':
            node['name'] = P['h1']
            node['description'] = P['desc']
            if 'url' in node:
                node['url'] = url
        elif t == 'BreadcrumbList':
            for item in node.get('itemListElement', []):
                if item.get('position') == 2:
                    item['name'] = P['h1']
                    item['item'] = url
        elif t == 'FAQPage':
            node['mainEntity'] = [{
                '@type': 'Question',
                'name': q,
                'acceptedAnswer': {'@type': 'Answer', 'text': a},
            } for q, a in faq_for(P)]

    out = _json.dumps(data, indent=2, ensure_ascii=False)
    return s[:m.start(2)] + out + s[m.end(2):]


def faq_for(P):
    """The page's FAQ, as DATA. Rendered into both the visible HTML and
    the JSON-LD so the two cannot disagree - the first cut inherited the
    template's schema and shipped five questions that were not on the
    page, which is a structured-data violation rather than a typo."""
    return [
        ("Can I really order 20 pieces?",
         "Yes. 20 pieces per style is the minimum, at the trial-run price in "
         "the table above. Bulk pricing begins at 50 per style per colour."),
        ("Will I pay UK import duty on " + P["noun"] + "?",
         "Most likely 0%, under the UK's Developing Countries Trading Scheme. "
         "That covers 92% of product lines rather than all of them, so we check "
         "your commodity code" + (" (HS " + P["hs"] + " for this garment)" if P.get("hs") else "") + " and tell you "
         "the rate before you order. Import VAT at 20% applies either way and is "
         "normally reclaimable if you are VAT-registered."),
        ("How long does it take to reach the UK?",
         "Production is the lead time shown in the price table; shipping is on "
         "top. We ship DDP, so the duty and VAT paperwork is ours to handle."),
        ("Do you work with brands that have not launched yet?",
         "Constantly - it is most of what we do. If you have no tech pack, no "
         "pattern and one reference photo, that is a normal starting point."),
    ]


def body_html(P, table):
    # A single garment has one commodity code. A CATEGORY does not, and
    # claiming one would be a wrong answer to the question a UK buyer is
    # actually asking - so a multi-garment page lists them instead.
    if P.get('hs'):
        hs_sentence = ('Your commodity code for this garment is <strong>HS %s</strong> - '
                       '<em>%s</em>.' % (P['hs'], P['hs_label']))
    else:
        hs_sentence = P['hs_multi']
    faq_html = "".join(
        '<div class="faq-item"><h3>%s</h3><p>%s</p></div>'
        % (html.escape(q), html.escape(a)) for q, a in faq_for(P))
    """The page's own content. Everything factual here is either parsed
    from /pricing or restated from the UK page, which already carries the
    caveats; nothing about duty is authored fresh."""
    return f"""
<p><strong>Custom {P['noun']} made in Sialkot, Pakistan, for brands selling in
the United Kingdom.</strong> Minimum 20 pieces per style, {P['gsm']} fabric,
your own labels and tags, delivered DDP with the import paperwork handled.
Most of what we make enters the UK at <strong>0% import duty</strong> rather
than the UK Global Tariff a Chinese supplier pays.</p>

<h2>Prices, at the quantities UK brands actually start with</h2>
<p>These are the live figures from our <a href="/pricing">full price list</a> -
the same table, not a sales version of it. Prices are per piece in USD and
include a custom label.</p>
{table}
<p class="note">The 20-piece column is the trial-run price. Bulk pricing starts
at 50 pieces per style per colour, and the per-piece cost keeps falling from
there. A sample comes first either way, so you approve the fit and the fabric
before committing to a run.</p>

<h2>What UK import actually costs you</h2>
<p>{hs_sentence} Pakistan sits in the Enhanced Preferences tier of the
UK's Developing Countries Trading Scheme, which carries 0% import duty on 92%
of product lines.</p>
<p>Two things we would rather state than have you discover. It is 92% of lines,
not every line, so <strong>the rate follows your specific commodity code</strong> -
we check yours and give you the number instead of estimating it. And duty is
not VAT: <strong>20% import VAT still applies</strong>, though a VAT-registered
UK business normally reclaims it as input tax. Both are verifiable on GOV.UK
rather than on our say-so. The full position, including what changed on
1 January 2026, is on our
<a href="/clothing-manufacturer-uk">UK manufacturing page</a>.</p>

<h2>Fabric and specification</h2>
<p>{P['gsm']} is the weight range most UK brands ask us for, and the one these
prices are built on. Heavier is available and costs more; lighter is available
and costs less. We will send the actual GSM and composition on the sample
docket rather than describing it as "premium", which tells you nothing.</p>
<ul>
<li>Your own woven labels, care labels and hang tags</li>
<li>Embroidery, screen print, puff print, DTF and sublimation</li>
<li>Custom patterns and fits from your tech pack, or we draft one from a reference</li>
<li>Colour matched to Pantone on request</li>
</ul>

<h2>How a UK order runs</h2>
<ol>
<li><strong>Send your tech pack or a reference photo.</strong> No tech pack is
fine - most first-time brands do not have one, and we will draft it.</li>
<li><strong>Quote within 24 hours</strong>, with the duty rate for your
commodity code rather than a guess.</li>
<li><strong>Sample</strong>, revised until the fit and fabric are right.</li>
<li><strong>Production</strong>, then DDP delivery to your UK address with
duty and VAT paperwork handled.</li>
</ol>

<div class="cta-box">
<h3>Get a quote for your {P['noun']}</h3>
<p>Send a tech pack, a sketch, or a photo of something close. Quote back within
24 hours, with the real duty rate for your commodity code.</p>
<p><a class="btn" href="/#contact">Request a quote</a>
&nbsp; <a href="/sample-order">Order a sample first</a></p>
</div>

<h2>Questions UK brands ask</h2>
<div class="faq-section">
{faq_html}
</div>
"""


if __name__ == '__main__':
    raise SystemExit(main())
