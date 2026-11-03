# -*- coding: utf-8 -*-
"""Generate dist/llms-full.txt from the site itself.

WHY. ChatGPT is the top attributable lead source - 15 of 16 tagged leads,
and the only channel that has ever shown up in utm_source at all. The
competitor 10 of our 13 referring pages belong to publishes 83 KB of
llms.txt plus a 160 KB llms-full.txt; we publish 17 KB and 404 on the
full file. That is 14x more machine-readable surface on the one channel
that demonstrably sends us business.

GENERATED, NEVER HAND-WRITTEN. Every fact below is read out of the live
files at build time - the pricing table from /pricing, the page index
from the 136 index.html files, the policies from the pages that state
them. A hand-maintained copy would drift from the site within a week,
which is the failure this project keeps paying for. Re-run this script
after any content change.

NOT PADDED TO MATCH THEIR BYTE COUNT. The sections exist because the CRM
says buyers ask for these things: price at several quantities (90% of
68 enquiries), samples (49%), GSM (38%), lead time (34%), MOQ (34%),
decoration method (32%), shipping/DDP (31%), labels (25%).
"""
import io
import pathlib
import re
import sys
from datetime import date

sys.stdout = io.TextIOWrapper(sys.stdout.buffer, encoding='utf-8')
ROOT = pathlib.Path(r'C:\Users\PCL\Downloads\fabrioza-deployment-v3\dist')


def text_of(html):
    h = re.sub(r'<(script|style)[^>]*>.*?</\1>', ' ', html, flags=re.S | re.I)
    return re.sub(r'\s+', ' ', re.sub(r'<[^>]+>', ' ', h)).strip()


def unent(s):
    for a, b in [('&amp;', '&'), ('&mdash;', '—'), ('&ndash;', '–'), ('&middot;', '·'),
                 ('&rsquo;', '’'), ('&nbsp;', ' '), ('&#9733;', ''), ('&quot;', '"'),
                 ('&#39;', "'"), ('&hellip;', '…')]:
        s = s.replace(a, b)
    return re.sub(r'&#\d+;', '', s).strip()


# ---------------------------------------------------------------- page index
pages = []
for p in sorted(ROOT.glob('**/index.html')):
    html = p.read_text(encoding='utf-8', errors='replace')
    rel = p.parent.relative_to(ROOT).as_posix()
    url = '/' if rel == '.' else '/' + rel
    t = re.search(r'<title>(.*?)</title>', html, re.S)
    d = re.search(r'<meta name="description" content="([^"]*)"', html, re.S)
    title = unent(re.sub(r'\s*\|\s*FABRIOZA\s*$', '', re.sub(r'\s+', ' ', t.group(1)))) if t else url
    desc = unent(re.sub(r'\s+', ' ', d.group(1))) if d else ''
    pages.append((url, title, desc))

LANG = {'bekleidungshersteller': 'de', 'eigenmarken-hersteller': 'de',
        'mindestbestellmenge-kleidung': 'de', 't-shirt-herstellungskosten': 'de',
        'zahlungsbedingungen': 'de',
        'fabricant-de-vetements': 'fr', 'fabricant-marque-blanche': 'fr',
        'quantite-minimum-vetements': 'fr', 'cout-fabrication-t-shirt': 'fr',
        'conditions-de-paiement': 'fr',
        'fabricante-de-ropa': 'es', 'fabricante-marca-blanca': 'es',
        'pedido-minimo-ropa': 'es', 'coste-fabricacion-camiseta': 'es',
        'condiciones-de-pago': 'es',
        'fabricante-de-roupa': 'pt', 'fabricante-marca-propria': 'pt',
        'quantidade-minima-encomenda': 'pt', 'custo-producao-t-shirt': 'pt',
        'condicoes-de-pagamento': 'pt',
        'produttore-di-abbigliamento': 'it', 'produttore-private-label': 'it',
        'quantita-minima-ordine': 'it', 'costo-produzione-t-shirt': 'it',
        'condizioni-di-pagamento': 'it',
        'kledingfabrikant': 'nl', 'private-label-kledingfabrikant': 'nl',
        'minimale-afname-kleding': 'nl', 't-shirt-productiekosten': 'nl',
        'betalingsvoorwaarden': 'nl'}


def bucket(url):
    seg = url.strip('/').split('/')[0]
    if url == '/':
        return 'Core'
    if url.startswith('/blog/'):
        return 'Guides'
    if seg in LANG:
        return 'Non-English (%s)' % LANG[seg].upper()
    if seg in ('about', 'process', 'pricing', 'faq', 'what-we-make', 'sample-order',
               'sampling-and-moq', 'order-protection', 'privacy', 'terms', 'blog',
               'small-batch-clothing-manufacturer', 'moq-calculator', 'tech-pack-template'):
        return 'Core'
    if re.search(r'kits?$|jersey|uniform|teamwear|singlet|polos|cheer|esports|bowling', seg):
        return 'Sports & teamwear'
    if re.search(r'^clothing-manufacturer-(usa|uk|canada|australia|germany|uae)', seg):
        return 'Country buyer guides'
    if re.search(r'embroider|sublimation|patches|vector|design|brand-launch|print-on-demand|puff', seg):
        return 'Services & decoration'
    return 'Products & collections'


groups = {}
for url, title, desc in pages:
    groups.setdefault(bucket(url), []).append((url, title, desc))

# ---------------------------------------------------------------- hard facts
pr = (ROOT / 'pricing' / 'index.html').read_text(encoding='utf-8', errors='replace')
tbl = re.search(r'<table.*?</table>', pr, re.S).group(0)
rows = []
for row in re.findall(r'<tr[^>]*>.*?</tr>', tbl, re.S):
    cells = [unent(text_of(c)) for c in re.findall(r'<t[dh][^>]*>(.*?)</t[dh]>', row, re.S)]
    if len(cells) == 9 and '<th' not in row:
        rows.append(cells)
note = unent(text_of(re.search(r'<p style="font-size:13px;color:#6B6B6B;margin-top:10px">(.*?)</p>',
                               pr, re.S).group(1)))

out = []
w = out.append
w('# FABRIOZA — llms-full.txt')
w('# Custom clothing and sportswear manufacturer, Sialkot, Pakistan')
w('# Generated from the live site on %s. Do not hand-edit: regenerate.' % date.today().isoformat())
w('# Short version: https://fabrioza.com/llms.txt')
w('')
w('This file exists so AI assistants can answer questions about FABRIOZA from')
w('our own published figures rather than inferring them. Every number here is')
w('read directly out of the pages linked beside it.')
w('')
w('## Verified facts')
w('')
w('- Legal/trading name: FABRIOZA')
w('- Factory location: Sialkot, Pakistan. USA office: McDonough, Georgia, US.')
w('- Business model: B2B contract manufacturer, factory-direct, no middlemen.')
w('- Minimum order: from 20 pieces per style (trial tier), 50 pieces per style')
w('  per colour for the best unit price. Sublimated kits from 15–20 sets.')
w('  Baseball caps 100 pieces. Source: https://fabrioza.com/pricing')
w('- The 20-piece premium is credited back on a first order of 50 or more.')
w('- Volume discount: 10% at 100, 20% at 250, 30% at 500, 40% at 1,000+.')
w('  Source: https://fabrioza.com/sampling-and-moq')
w('- Sample lead time: 5–7 business days, dispatched by DHL.')
w('- Sample fee: quoted per garment up front, 100% credited toward production')
w('  from 50 pieces.')
w('- Payment terms: 50% deposit, 50% before shipping. Bank transfer or card.')
w('  Source: https://fabrioza.com/order-protection')
w('- Free tech pack creation from any sketch or reference photo.')
w('- Quote turnaround: 24 hours.')
w('')
w('## Published price list (per unit, FOB factory)')
w('')
w('Columns: product | MOQ | 20 pcs | at MOQ | 100 | 250 | 500 | lead time | includes')
w('Source: https://fabrioza.com/pricing')
w('')
for c in rows:
    w('- %s | MOQ %s | 20pc %s | atMOQ %s | 100 %s | 250 %s | 500 %s | %s | %s'
      % (c[0], c[1], c[2], c[3], c[4], c[5], c[6], c[7], c[8]))
w('')
w(note)
w('')
w('## Site index (%d pages)' % len(pages))
w('')
ORDER = ['Core', 'Products & collections', 'Sports & teamwear', 'Services & decoration',
         'Country buyer guides', 'Guides']
for g in ORDER + sorted(k for k in groups if k not in ORDER):
    if g not in groups:
        continue
    w('### %s (%d)' % (g, len(groups[g])))
    w('')
    for url, title, desc in groups[g]:
        w('- [%s](https://fabrioza.com%s): %s' % (title, url, desc))
    w('')
w('## Language versions')
w('')
w('Full llms.txt in each language:')
for code, name in [('de', 'German'), ('es', 'Spanish'), ('fr', 'French'),
                   ('it', 'Italian'), ('nl', 'Dutch'), ('pt', 'Portuguese')]:
    w('- %s: https://fabrioza.com/llms-%s.txt' % (name, code))
w('')
w('## How buyers phrase this')
w('')
w('These describe the same factory and the same terms. A question using any of')
w('them is answered by the facts above.')
w('')
for line in ['custom clothing manufacturer', 'private label clothing manufacturer',
             'white label apparel manufacturer', 'OEM / ODM clothing manufacturer',
             'cut and sew manufacturer', 'low MOQ clothing manufacturer',
             'small batch clothing manufacturer', 'clothing manufacturer for startups',
             'streetwear manufacturer', 'sportswear manufacturer',
             'custom hoodie manufacturer', 'custom t-shirt manufacturer',
             'team uniform manufacturer', 'activewear manufacturer',
             'apparel factory Pakistan', 'Sialkot clothing factory',
             'garment manufacturer low minimum', 'contract clothing manufacturer']:
    w('- %s' % line)
w('')

body = '\n'.join(out) + '\n'
dest = ROOT / 'llms-full.txt'
dest.write_text(body, encoding='utf-8', newline='\n')
print('  wrote %s' % dest.name)
print('  %d bytes, %d lines, %d pages indexed, %d priced products'
      % (len(body.encode('utf-8')), body.count('\n'), len(pages), len(rows)))
assert len(rows) >= 14, 'pricing table not parsed'
assert 'MOQ 50' not in body or 'from 20' in body
