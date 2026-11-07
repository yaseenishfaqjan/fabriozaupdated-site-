# -*- coding: utf-8 -*-
"""Build a product x country landing page from that country's own page.

    python -I tools/build-country-product-page.py uk hoodie
    python -I tools/build-country-product-page.py usa hoodie --write
    python -I tools/build-country-product-page.py usa all --write

Supersedes build-uk-product-page.py, which hard-coded the UK's duty
position into every page it made.

WHY THE COUNTRY HAD TO BECOME A DIMENSION. The UK and the USA are
opposites on the one fact these pages exist to state. Pakistan sits in
the Enhanced Preferences tier of the UK's DCTS, so most garments enter
Britain at 0% duty. Pakistan has no US trade agreement: cotton knits
carry roughly 26.5% - the 16.5% MFN rate plus the 10% Section 301
tariff applied to Pakistani goods since 24 July 2026. Running the UK
generator against the USA would have published "0% duty" to American
buyers, which is not a cosmetic error - it is a number somebody budgets
against and then pays.

So the duty wording comes from each country's own landing page, which
already states it carefully, rather than being written fresh here. The
product data below is country-agnostic on purpose: a hoodie is a hoodie.

PRICES ARE PARSED FROM /pricing AND ASSERTED, never typed. USD
throughout, matching the rest of the site.
"""
import html
import io
import pathlib
import re
import sys

sys.stdout = io.TextIOWrapper(sys.stdout.buffer, encoding='utf-8')

ROOT = pathlib.Path(__file__).resolve().parent.parent
DIST = ROOT / 'dist'
PRICING = DIST / 'pricing' / 'index.html'


def uk_duty(P, C):
    if P.get('hs'):
        lead = ('Your commodity code for this garment is <strong>HS %s</strong> - '
                '<em>%s</em>.' % (P['hs'], P['hs_label']))
    else:
        lead = P['hs_multi_uk']
    return f"""<p>{lead} Pakistan sits in the Enhanced Preferences tier of the
UK's Developing Countries Trading Scheme, which carries 0% import duty on 92%
of product lines.</p>
<p>Two things we would rather state than have you discover. It is 92% of lines,
not every line, so <strong>the rate follows your specific commodity code</strong> -
we check yours and give you the number instead of estimating it. And duty is
not VAT: <strong>20% import VAT still applies</strong>, though a VAT-registered
UK business normally reclaims it as input tax. Both are verifiable on GOV.UK
rather than on our say-so. The full position, including what changed on
1 January 2026, is on our
<a href="/clothing-manufacturer-uk">UK manufacturing page</a>.</p>"""


def usa_duty(P, C):
    if P.get('hs'):
        lead = ('Your tariff heading for this garment is <strong>HTS %s</strong> - '
                '<em>%s</em>. The full ten-digit code decides the rate.'
                % (P['hs'], P['hs_label']))
    else:
        lead = P['hs_multi_usa']
    return f"""<p>{lead}</p>
<p><strong>US duty on Pakistani apparel is real, and we would rather put the
number in front of you than let it arrive later.</strong> Cotton knitwear
carries roughly <strong>26.5%</strong> as of October 2026: the 16.5% MFN rate,
plus the 10% Section 301 tariff that has applied to Pakistani goods since
24 July 2026. Man-made-fibre knits are higher.</p>
<p>That is not a reason to buy from China, which pays its own Section 301
tariffs on top of a similar base rate - it is a reason to know the figure
before you commit. We confirm the exact rate for your HTS code in writing
before you order, and our <strong>DDP quotes bundle duty, freight and
clearance</strong>, so the number you approve is the number you pay with no
brokerage invoice afterwards. The full breakdown is on our
<a href="/clothing-manufacturer-usa">USA manufacturing page</a>.</p>"""


COUNTRIES = {
    'uk': {
        'slug': 'uk',
        'template': 'clothing-manufacturer-uk',
        'landing': '/clothing-manufacturer-uk',
        'brands': 'UK Brands',
        'brands_lc': 'UK brands',
        'dest': 'the United Kingdom',
        'duty_html': uk_duty,
        'duty_blurb': '0% UK import duty under DCTS',
        'faq_duty_q': 'Will I pay UK import duty on {noun}?',
        'faq_duty_a': ("Most likely 0%, under the UK's Developing Countries Trading "
                       "Scheme. That covers 92% of product lines rather than all of "
                       "them, so we check your commodity code{code} and tell you the "
                       "rate before you order. Import VAT at 20% applies either way "
                       "and is normally reclaimable if you are VAT-registered."),
        'faq_ship_a': ('Production is the lead time shown in the price table; shipping '
                       'is on top. We ship DDP, so the duty and VAT paperwork is ours '
                       'to handle.'),
    },
    'usa': {
        'slug': 'usa',
        'template': 'clothing-manufacturer-usa',
        'landing': '/clothing-manufacturer-usa',
        'brands': 'USA Brands',
        'brands_lc': 'US brands',
        'dest': 'the USA',
        'duty_html': usa_duty,
        'duty_blurb': 'DDP quotes with US duty included',
        'faq_duty_q': 'What duty will I pay on {noun}?',
        'faq_duty_a': ("Cotton knitwear runs about 26.5% as of October 2026 - the "
                       "16.5% MFN rate plus the 10% Section 301 tariff on Pakistani "
                       "goods. Man-made fibres are higher, and your exact HTS code "
                       "decides it{code}. We confirm the rate in writing before you "
                       "order and our DDP quote includes it, so there is no brokerage "
                       "invoice afterwards."),
        'faq_ship_a': ('Express is 3-5 business days, air freight 7-10, sea 30-40 for '
                       'planned inventory. All DDP, so clearance and duty are handled '
                       'and included.'),
    },
}

PRODUCTS = {
    'hoodie': {
        'slug': 'hoodie', 'rows': ['Premium Hoodie', 'Crewneck Sweatshirt'],
        'hs': '6110', 'hs_label': 'Jerseys, pullovers, cardigans and sweatshirts, knitted',
        'gsm': '280-350gsm', 'tag': 'Hoodies &amp; Sweatshirts',
        'noun': 'hoodies and sweatshirts', 'title_noun': 'Hoodie',
        'hero_img': '/images/cat-hoodies-hero.jpg',
    },
    't-shirt': {
        'slug': 't-shirt', 'rows': ['Heavyweight T-Shirt'],
        'hs': '6109', 'hs_label': 'T-shirts, singlets and other vests, knitted',
        'gsm': '180-220gsm', 'tag': 'T-Shirts',
        'noun': 't-shirts', 'title_noun': 'T-Shirt',
        'hero_img': '/images/st-graphic-tee.jpg',
    },
    'tracksuit': {
        'slug': 'tracksuit', 'rows': ['Tracksuit Set'],
        'hs': '6112', 'hs_label': 'Track suits, ski suits and swimwear, knitted',
        'gsm': '280-340gsm', 'tag': 'Tracksuits',
        'noun': 'tracksuits', 'title_noun': 'Tracksuit',
        'hero_img': '/images/sw-tracksuit.jpg',
    },
    'streetwear': {
        'slug': 'streetwear',
        'rows': ['Streetwear Acid Wash Tee', 'Heavyweight T-Shirt', 'Premium Hoodie'],
        # A CATEGORY HAS NO SINGLE CODE. Naming one would be a confidently
        # wrong answer to the question the page exists to answer.
        'hs': None,
        'hs_multi_uk': ('Streetwear spans several commodity codes rather than one: tees '
                        'sit under <strong>HS 6109</strong>, hoodies and crews under '
                        '<strong>HS 6110</strong>, and knitted cargo trousers under '
                        '<strong>HS 6103</strong>. We confirm the code for each garment '
                        'in your range before quoting.'),
        'hs_multi_usa': ('Streetwear spans several tariff headings rather than one: tees '
                         'sit under <strong>HTS 6109</strong>, hoodies and crews under '
                         '<strong>HTS 6110</strong>, and knitted cargo trousers under '
                         '<strong>HTS 6103</strong>. The full ten-digit code decides the '
                         'rate, and we confirm each one in writing before quoting.'),
        'hs_label': '', 'gsm': '180-350gsm', 'tag': 'Streetwear',
        'noun': 'streetwear', 'title_noun': 'Streetwear',
        'hero_img': '/images/st-acidwash-tee.jpg',
    },
}


def price_rows():
    s = PRICING.read_text(encoding='utf-8')
    out = []
    for r in re.findall(r'<tr[^>]*>(.*?)</tr>', s, re.S):
        cells = [html.unescape(re.sub(r'<[^>]+>', ' ', c)).strip()
                 for c in re.findall(r'<t[hd][^>]*>(.*?)</t[hd]>', r, re.S)]
        cells = [re.sub(r'\s+', ' ', c) for c in cells if c != '']
        if cells:
            out.append(cells)
    return out


def faq_for(P, C):
    code = (' (HTS %s for this garment)' if C['slug'] == 'usa'
            else ' (HS %s for this garment)') % P['hs'] if P.get('hs') else ''
    return [
        ('Can I really order 20 pieces?',
         'Yes. 20 pieces per style is the minimum, at the trial-run price in the '
         'table above. Bulk pricing begins at 50 per style per colour.'),
        (C['faq_duty_q'].format(noun=P['noun']),
         C['faq_duty_a'].format(code=code)),
        ('How long does it take to reach %s?' % C['dest'],
         C['faq_ship_a']),
        ('Do you work with brands that have not launched yet?',
         'Constantly - it is most of what we do. If you have no tech pack, no '
         'pattern and one reference photo, that is a normal starting point.'),
    ]


def build_table(P):
    rows = price_rows()
    header = next((r for r in rows if r and r[0].lower() == 'product'), None)
    assert header, 'price table header not found on /pricing'
    wanted = []
    for name in P['rows']:
        hit = next((r for r in rows if r[0].startswith(name)), None)
        assert hit, 'price row not found on /pricing: %s' % name
        wanted.append(hit)

    ths = ''.join('<th>%s</th>' % html.escape(c) for c in header)
    trs = ''
    for r in wanted:
        m = re.match(r'^(.*?)(\d{3}-\d{3}gsm.*|Zip jacket.*|Garment dyed.*)$', r[0])
        label = ('<strong>%s</strong><br><span class="spec">%s</span>'
                 % (html.escape(m.group(1).strip()), html.escape(m.group(2).strip()))
                 if m else '<strong>%s</strong>' % html.escape(r[0]))
        trs += '<tr><td>%s</td>%s</tr>' % (
            label, ''.join('<td>%s</td>' % html.escape(c) for c in r[1:]))

    table = ('<div class="table-wrap"><table><thead><tr>%s</tr></thead>'
             '<tbody>%s</tbody></table></div>' % (ths, trs))
    src = PRICING.read_text(encoding='utf-8')
    missing = [p for p in re.findall(r'\$[0-9]+\.[0-9]{2}', table) if p not in src]
    assert not missing, 'prices not on /pricing: %s' % missing
    return table, len(re.findall(r'\$[0-9]+\.[0-9]{2}', table)), wanted


def body_html(P, C, table):
    faq_html = ''.join('<div class="faq-item"><h3>%s</h3><p>%s</p></div>'
                       % (html.escape(q), html.escape(a)) for q, a in faq_for(P, C))
    return f"""
<p><strong>Custom {P['noun']} made in Sialkot, Pakistan, for brands selling in
{C['dest']}.</strong> Minimum 20 pieces per style, {P['gsm']} fabric, your own
labels and tags, delivered DDP with the import paperwork handled.</p>

<h2>Prices, at the quantities new brands actually start with</h2>
<p>These are the live figures from our <a href="/pricing">full price list</a> -
the same table, not a sales version of it. Prices are per piece in USD and
include a custom label.</p>
{table}
<p class="note">The 20-piece column is the trial-run price. Bulk pricing starts
at 50 pieces per style per colour and keeps falling from there. A sample comes
first either way, so you approve the fit and the fabric before a full run.</p>

<h2>What import actually costs you</h2>
{C['duty_html'](P, C)}

<h2>Fabric and specification</h2>
<p>{P['gsm']} is the weight range most brands ask us for, and the one these
prices are built on. Heavier costs more, lighter costs less. We put the actual
GSM and composition on the sample docket rather than calling it "premium",
which tells you nothing.</p>
<ul>
<li>Your own woven labels, care labels and hang tags</li>
<li>Embroidery, screen print, puff print, DTF and sublimation</li>
<li>Custom patterns and fits from your tech pack, or we draft one from a reference</li>
<li>Colour matched to Pantone on request</li>
</ul>

<h2>How an order runs</h2>
<ol>
<li><strong>Send your tech pack or a reference photo.</strong> No tech pack is
fine - most first-time brands do not have one, and we will draft it.</li>
<li><strong>Quote within 24 hours</strong>, with the duty rate for your code
rather than a guess.</li>
<li><strong>Sample</strong>, revised until the fit and fabric are right.</li>
<li><strong>Production</strong>, then DDP delivery to your address with the
clearance paperwork handled.</li>
</ol>

<div class="cta-box">
<h3>Get a quote for your {P['noun']}</h3>
<p>Send a tech pack, a sketch, or a photo of something close. Quote back within
24 hours, with the real duty rate for your code.</p>
<p><a class="btn" href="/#contact">Request a quote</a>
&nbsp; <a href="/sample-order">Order a sample first</a></p>
</div>

<h2>Questions brands ask</h2>
<div class="faq-section">
{faq_html}
</div>
"""


def build(country, product, write):
    C, P = COUNTRIES[country], PRODUCTS[product]
    slug = '%s-manufacturer-%s' % (P['slug'], C['slug'])
    h1 = '%s Manufacturer for %s' % (P['title_noun'], C['brands'])
    title = '%s | MOQ from 20, DDP | FABRIOZA' % h1
    desc = ('Custom %s for %s: %s, MOQ from 20 pieces, %s, DDP delivery. Real '
            'prices and tariff codes, not estimates.'
            % (P['noun'], C['brands_lc'], P['gsm'], C['duty_blurb']))

    table, nprices, wanted = build_table(P)
    tpl = (DIST / C['template'] / 'index.html').read_text(encoding='utf-8')
    s = tpl

    s = re.sub(r'<title>.*?</title>', '<title>%s</title>' % title, s, count=1, flags=re.S)
    for attr in ('name', 'property'):
        for key, val in (('description', desc), ('og:title', title),
                         ('og:description', desc), ('twitter:title', title),
                         ('twitter:description', desc)):
            s = re.sub(r'(<meta %s="%s" content=")[^"]*(")' % (attr, re.escape(key)),
                       lambda m: m.group(1) + html.escape(val, quote=True) + m.group(2),
                       s, count=1)
    s = s.replace('https://fabrioza.com/' + C['template'], 'https://fabrioza.com/' + slug)
    s = re.sub(r'(<h1[^>]*>).*?(</h1>)', lambda m: m.group(1) + h1 + m.group(2),
               s, count=1, flags=re.S)
    s = re.sub(r'(<div class="category-tag">).*?(</div>)',
               lambda m: m.group(1) + P['tag'] + m.group(2), s, count=1, flags=re.S)
    s = re.sub(r'(<div class="meta">).*?(</div>)',
               lambda m: m.group(1) + 'MOQ from 20 pieces &middot; DDP shipping to '
               + C['dest'] + ' &middot; Quotes in 24h' + m.group(2),
               s, count=1, flags=re.S)
    hero_alt = 'Custom %s manufactured for %s' % (P['noun'], C['brands_lc'])
    s = re.sub(r'(<div class="article-hero"[^>]*>\s*<img[^>]*src=")[^"]*(")',
               lambda m: m.group(1) + P['hero_img'] + m.group(2), s, count=1, flags=re.S)
    s = re.sub(r'(<div class="article-hero"[^>]*>\s*<img[^>]*alt=")[^"]*(")',
               lambda m: m.group(1) + html.escape(hero_alt, quote=True) + m.group(2),
               s, count=1, flags=re.S)
    i = s.find('article-hero')
    assert P['hero_img'] in s[i:i + 320], 'hero image not set'

    css = ('<style>.table-wrap{overflow-x:auto;-webkit-overflow-scrolling:touch;'
           'margin:24px 0}.table-wrap table{margin:0;min-width:640px}'
           '.table-wrap .spec{font-weight:400;font-size:.85em;color:#667}'
           'p.note{font-size:.93em;color:#555}</style>')
    s = s.replace('</head>', css + '</head>', 1)

    start = s.find('<div class="content">')
    assert start != -1, 'content div not found'
    end = s.find('<div class="related-posts">', start)
    if end == -1:
        end = s.find('<footer', start)
    assert end != -1, 'content end not found'
    s = s[:start] + '<div class="content">' + body_html(P, C, table) + '</div>' + s[end:]

    s = rebuild_schema(s, P, C, h1, desc, slug)

    # the landing page's own phrase must not come along
    owned = 'Clothing Manufacturer for %s' % C['brands']
    assert owned not in s, 'page would compete with %s' % C['landing']

    dest = DIST / slug / 'index.html'
    if write:
        dest.parent.mkdir(parents=True, exist_ok=True)
        dest.write_text(s, encoding='utf-8', newline='')
    print('  %-30s %d prices  %s' % (slug, nprices, 'written' if write else '(dry run)'))
    return slug


def rebuild_schema(s, P, C, h1, desc, slug):
    import json as _json
    m = re.search(r'(<script type="application/ld\+json">)(.*?)(</script>)', s, re.S)
    assert m, 'no JSON-LD in template'
    data = _json.loads(m.group(2))
    nodes = data.get('@graph') or (data if isinstance(data, list) else [data])
    url = 'https://fabrioza.com/' + slug
    for node in nodes:
        t = node.get('@type')
        if t == 'Service':
            node['name'], node['description'] = h1, desc
            if 'url' in node:
                node['url'] = url
        elif t == 'BreadcrumbList':
            for it in node.get('itemListElement', []):
                if it.get('position') == 2:
                    it['name'], it['item'] = h1, url
        elif t == 'FAQPage':
            node['mainEntity'] = [
                {'@type': 'Question', 'name': q,
                 'acceptedAnswer': {'@type': 'Answer', 'text': a}}
                for q, a in faq_for(P, C)]
    out = _json.dumps(data, indent=2, ensure_ascii=False)
    return s[:m.start(2)] + out + s[m.end(2):]


def main():
    if len(sys.argv) < 3 or sys.argv[1] not in COUNTRIES:
        print('usage: build-country-product-page.py {%s} {%s|all} [--write]'
              % ('|'.join(COUNTRIES), '|'.join(PRODUCTS)))
        return 1
    country, product = sys.argv[1], sys.argv[2]
    write = '--write' in sys.argv
    targets = list(PRODUCTS) if product == 'all' else [product]
    assert all(t in PRODUCTS for t in targets), 'unknown product'
    for t in targets:
        build(country, t, write)
    if not write:
        print('\n  dry run - nothing written. Pass --write.')
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
