# -*- coding: utf-8 -*-
"""Build /precios-fabricacion-ropa, the Spanish price list.

WHY. A Spanish customer started contacting us, and pricing is what 90% of
68 mined enquiries ask for - yet /pricing is English-only. A Spanish buyer
who gets as far as wanting numbers has to switch language to read them.

THE TABLE IS PARSED OUT OF /pricing, NOT RETYPED. Fifteen products times
five price columns is 75 figures; a hand-copied second price list would
disagree with the first within one edit, and this project has already
paid for exactly that with the MOQ wording. Only the LABELS are
translated - every number comes from the English page at build time.

STRUCTURE IS CLONED from coste-fabricacion-camiseta so the header, nav,
language switcher, quote form, attribution hook and footer are
byte-identical to the rest of the Spanish cluster. Only the head metadata
and the hero-to-FAQ block are replaced. The cta-box is KEPT because it
holds the working form.

The slice being replaced opens <div class="container"> and
<div class="content"> without closing them (11 opens, 9 closes) - they
close after the form - so the replacement leaves them open too.
"""
import io
import pathlib
import re
import sys

sys.stdout = io.TextIOWrapper(sys.stdout.buffer, encoding='utf-8')
ROOT = pathlib.Path(r'C:\Users\PCL\Downloads\fabrioza-deployment-v3\dist')
SLUG = 'precios-fabricacion-ropa'

# ---------------------------------------------------------------- the data
pr = (ROOT / 'pricing' / 'index.html').read_text(encoding='utf-8')
tbl = re.search(r'<table.*?</table>', pr, re.S).group(0)


def cell_text(c):
    t = re.sub(r'<[^>]+>', ' ', c)
    return re.sub(r'\s+', ' ', t).strip()


rows = []
for row in re.findall(r'<tr[^>]*>.*?</tr>', tbl, re.S):
    if '<th' in row:
        continue
    cells = [cell_text(c) for c in re.findall(r'<t[dh][^>]*>(.*?)</t[dh]>', row, re.S)]
    if len(cells) == 9:
        rows.append(cells)
assert len(rows) == 15, 'expected 15 priced rows, parsed %d' % len(rows)

# product name + its spec line, translated. Numbers never touched.
ES = {
    'Trial Order (Any Product)': ('Pedido de prueba (cualquier producto)',
                                  '20 uds para comprobar la calidad antes de producir'),
    'Heavyweight T-Shirt': ('Camiseta heavyweight', 'Algodón 180-220 g/m², corte oversize/boxy'),
    'Premium Hoodie': ('Sudadera con capucha premium', 'French terry / felpa 280-350 g/m²'),
    'Crewneck Sweatshirt': ('Sudadera de cuello redondo', 'Felpa interior 260-300 g/m²'),
    'Sports Jersey (Sublimated)': ('Camiseta deportiva (sublimada)',
                                   'Poliéster, estampado integral, nombres y dorsales'),
    'Soccer Kit (Full Set)': ('Equipación de fútbol (completa)',
                              'Camiseta + pantalón + medias, sublimado'),
    'Tracksuit Set': ('Chándal (conjunto)', 'Chaqueta con cremallera + pantalón a juego'),
    'Gym / Activewear Set': ('Conjunto deportivo / activewear', 'Leggings + top, sin costuras'),
    'Corporate Polo Shirt': ('Polo corporativo', 'Piqué de algodón, logo bordado'),
    'Bomber / Varsity Jacket': ('Bomber / chaqueta varsity', 'Cuerpo de lana, mangas de piel, chenilla'),
    'Baseball Cap': ('Gorra', 'Bordado puff 3D, snapback'),
    'Streetwear Acid Wash Tee': ('Camiseta streetwear acid wash', 'Tintado en prenda, acabado vintage, boxy'),
    'Windbreaker Jacket': ('Cortavientos', 'Nylon ligero, resistente al agua'),
    'Cut & Sew Custom Design': ('Corte y confección a medida', 'Del patrón a la prenda acabada'),
    'Cycling Jersey': ('Maillot de ciclismo', 'Sublimación total, 3 bolsillos traseros'),
}
UNITS = {'pcs': 'uds', 'sets': 'conjuntos', 'weeks': 'semanas'}
# the "Includes" column and the trial-order cells are prose, not numbers,
# so they need translating too - missed on the first build and caught by
# scanning the rendered table for cells still containing English words.
PHRASES = {
    'Custom label': 'Etiqueta propia',
    'Same 4-stage QC': 'Mismos 4 controles de calidad',
    'Full customization': 'Personalización completa',
    'Full set included': 'Conjunto completo incluido',
    'Embroidery': 'Bordado',
    'Chenille patches': 'Parches de chenilla',
    'Full embroidery': 'Bordado completo',
    'Pattern making': 'Patronaje incluido',
    'Full sublimation': 'Sublimación total',
    '+20% of the MOQ price': '+20 % sobre el precio al MOQ',
    'Quoted in 24h': 'Presupuesto en 24 h',
    'Premium credited at bulk': 'Recargo descontado en el pedido grande',
}


def es_phrase(s):
    return PHRASES.get(s.strip(), s)


def es_units(s):
    for a, b in UNITS.items():
        s = re.sub(r'\b%s\b' % a, b, s)
    return s


body_rows = []
for c in rows:
    name = next((k for k in ES if c[0].startswith(k)), None)
    assert name, 'untranslated product: %r' % c[0][:40]
    es_name, es_spec = ES[name]
    highlight = ' class="highlight"' if 'Hoodie' in name or 'T-Shirt' in name else ''
    body_rows.append(
        '<tr%s>\n'
        '<td><span class="product-name">%s</span><br><small style="color:#6B6B6B">%s</small></td>\n'
        '<td class="moq">%s</td>\n<td class="price">%s</td>\n<td class="price">%s</td>\n'
        '<td class="price">%s</td>\n<td class="price">%s</td>\n<td class="price">%s</td>\n'
        '<td class="moq">%s</td>\n<td class="check">%s</td>\n</tr>'
        % (highlight, es_name, es_spec, es_units(c[1]), es_phrase(c[2]), es_phrase(c[3]),
           es_phrase(c[4]), es_phrase(c[5]), es_phrase(c[6]),
           es_units(c[7]), es_phrase(c[8])))

TABLE = ('<div class="table-wrap">\n<table>\n<thead>\n<tr>\n'
         '<th>Producto</th>\n<th>MOQ</th>\n<th>20 uds</th>\n<th>Precio (al MOQ)</th>\n'
         '<th>100 uds</th>\n<th>250 uds</th>\n<th>500 uds</th>\n<th>Plazo</th>\n<th>Incluye</th>\n'
         '</tr>\n</thead>\n<tbody>\n' + '\n'.join(body_rows) + '\n</tbody>\n</table>\n</div>')

print('  parsed %d priced rows from /pricing' % len(rows))

# ---------------------------------------------------------------- the page
tpl = (ROOT / 'coste-fabricacion-camiseta' / 'index.html').read_text(encoding='utf-8')
a = tpl.index('<div class="hero">')
b = tpl.index('<div class="cta-box"')
head_end = tpl.index('</head>')

TITLE = 'Precios de fabricación de ropa 2026 | Lista completa | FABRIOZA'
DESC = ('Lista de precios de FABRIOZA para marcas españolas: 15 productos con precio '
        'por unidad a 20, 50, 100, 250 y 500 uds, plazos y MOQ. Precios FOB fábrica, '
        'IVA y aranceles explicados. Presupuesto en 24 h.')
URL = 'https://fabrioza.com/' + SLUG

NEW = '''<div class="hero">
<div class="container">
<h1>Precios de fabricación de ropa 2026</h1>
<p style="max-width:720px;margin:14px auto 0;opacity:.92">Quince productos con el precio por unidad en cada tramo de cantidad. Sin registro, sin «consultar precio»: los mismos números que damos por correo. Compáralos con quien quieras.</p>
<div class="hero-stats">
<div class="hero-stat"><div class="num">Desde 20</div><div class="label">Unidades MOQ</div></div>
<div class="hero-stat"><div class="num">24 h</div><div class="label">Presupuesto</div></div>
<div class="hero-stat"><div class="num">0 %</div><div class="label">Arancel UE (SPG+)</div></div>
<div class="hero-stat"><div class="num">DDP</div><div class="label">Entrega en España</div></div>
</div>
</div>
</div>

<div class="container">
<div class="content">
<div class="breadcrumb"><a href="/">Inicio</a> / <a href="/fabricante-de-ropa">Fabricante de ropa</a> / <span>Precios</span></div>

<h2>Precio por unidad, FOB fábrica</h2>
<p>Todos los precios son por unidad y FOB fábrica (Sialkot, Pakistán). El descuento por volumen ya está aplicado en cada columna. Pide un presupuesto DDP y añadimos transporte y aranceles para que la cifra que veas sea tu coste en destino.</p>

''' + TABLE + '''

<p style="font-size:13px;color:#6B6B6B;margin-top:10px"><strong>20 uds</strong> es nuestro tramo de prueba para marcas nuevas: misma línea de producción y los mismos 4 controles de calidad, con un 20 % de recargo que se descuenta en tu primer pedido de 50 unidades o más. El descuento por volumen es del <strong>10 % a 100, 20 % a 250, 30 % a 500 y 40 % a partir de 1.000</strong> unidades, la misma escala publicada en nuestra <a href="/pedido-minimo-ropa" style="color:#4A7C59">política de MOQ</a>.</p>

<h2>Lo que cuesta importar a España</h2>
<p>El precio de fábrica no es tu coste final. Estas son las dos cifras que lo completan, y conviene confirmarlas con tu transitario antes de cerrar un presupuesto:</p>
<ul>
<li><strong>Arancel: 0 %</strong> en la mayoría de las partidas textiles. Pakistán tiene estatus SPG+ con la Unión Europea, así que la ropa confeccionada entra sin arancel presentando el certificado de origen, que emitimos nosotros. Sin ese documento pagarías el tipo general, en torno al 12 %.</li>
<li><strong>IVA: 21 %</strong> a la importación, sobre el valor en aduana más el transporte. Es IVA soportado y deducible en tu declaración si estás dado de alta; no es un coste si vendes con IVA repercutido. Si presentas el IVA mensualmente puedes acogerte al <strong>régimen de diferimiento del IVA a la importación</strong> y liquidarlo en el modelo 303 en vez de pagarlo en la aduana, lo que te evita adelantar el dinero.</li>
</ul>
<p>Si prefieres una sola cifra, pide presupuesto <strong>DDP</strong>: transporte, arancel y despacho incluidos, entregado en tu dirección en España.</p>

<h2>Extras de personalización</h2>
<p>Los precios de la tabla incluyen la prenda y tu etiqueta. Estos son los añadidos más habituales:</p>
<ul>
<li><strong>Serigrafía:</strong> 1,50-3,00 $ por ubicación</li>
<li><strong>Bordado puff 3D:</strong> 2,50-5,00 $</li>
<li><strong>Etiquetas tejidas, hangtags y packaging con tu marca:</strong> incluidos en private label</li>
<li><strong>Ficha técnica (tech pack):</strong> gratis, a partir de cualquier boceto o foto de referencia</li>
</ul>

<h2>Lo que incluye cualquier presupuesto</h2>
<ul>
<li>Muestra pre-producción en 5-7 días laborables por DHL, con el coste descontado al 100 % en pedidos desde 50 unidades</li>
<li>Cuatro controles de calidad: tejido, corte, confección y producto acabado</li>
<li>Pago 50 % por adelantado y 50 % antes del envío, por transferencia o tarjeta — <a href="/condiciones-de-pago" style="color:#4A7C59">cómo está protegido tu pedido</a></li>
<li>Documentación de origen para que puedas acogerte al arancel preferente</li>
</ul>

<div class="faq-section">
<h2>Preguntas sobre precios</h2>
<div class="faq-item"><h4>¿Son precios reales o un punto de partida para negociar?</h4><p>Son los precios que aplicamos. Lo que los mueve es el gramaje, la complejidad del patrón y el método de estampación, no el regateo. Por eso publicamos la tabla entera en vez de pedirte que escribas para saber el rango.</p></div>
<div class="faq-item"><h4>¿Puedo mezclar tallas y colores dentro del mínimo?</h4><p>Tallas sí: 50 unidades de un modelo pueden repartirse entre todas las tallas. El color no, porque cada color es una partida de tintura distinta: tres colores son tres mínimos.</p></div>
<div class="faq-item"><h4>¿Por qué hay productos sin precio a 20 unidades?</h4><p>Las gorras y el corte y confección a medida tienen un mínimo de 100 unidades. En las gorras el molde y la preparación no se amortizan en 20 piezas, y en corte y confección tampoco el patronaje y el escalado. El resto del catálogo sí admite el tramo de prueba.</p></div>
<div class="faq-item"><h4>¿Qué pasa si el pedido en masa no sale como la muestra?</h4><p>Lo refabricamos sin coste. Es la razón de que la muestra se apruebe por escrito antes de empezar la producción.</p></div>
</div>

'''

# ---- head metadata -----------------------------------------------------
head = tpl[:head_end]
head = re.sub(r'<title>.*?</title>', '<title>%s</title>' % TITLE, head, count=1, flags=re.S)
head = re.sub(r'(<meta name="description" content=")[^"]*(")', r'\g<1>%s\g<2>' % DESC, head, count=1)
head = re.sub(r'(<link rel="canonical" href=")[^"]*(")', r'\g<1>%s\g<2>' % URL, head, count=1)
head = re.sub(r'(<meta property="og:title" content=")[^"]*(")', r'\g<1>%s\g<2>' % TITLE, head, count=1)
head = re.sub(r'(<meta property="og:description" content=")[^"]*(")', r'\g<1>%s\g<2>' % DESC, head, count=1)
head = re.sub(r'(<meta property="og:url" content=")[^"]*(")', r'\g<1>%s\g<2>' % URL, head, count=1)
head = re.sub(r'(<meta name="twitter:title" content=")[^"]*(")', r'\g<1>%s\g<2>' % TITLE, head, count=1)
head = re.sub(r'(<meta name="twitter:description" content=")[^"]*(")', r'\g<1>%s\g<2>' % DESC, head, count=1)
# two-page hreflang cluster with /pricing
head = head.replace('</head>', '') + (
    '<link rel="alternate" hreflang="es" href="%s">\n'
    '<link rel="alternate" hreflang="en" href="https://fabrioza.com/pricing">\n'
    '<link rel="alternate" hreflang="x-default" href="https://fabrioza.com/pricing">\n' % URL)
# the page-level JSON-LD should describe THIS page
head = re.sub(r'<script type="application/ld\+json">.*?</script>', '', head, flags=re.S)
head += ('<script type="application/ld+json">{"@context":"https://schema.org",'
         '"@type":"WebPage","name":%s,"url":%s,"inLanguage":"es",'
         '"description":%s,"isPartOf":{"@type":"WebSite","name":"FABRIOZA",'
         '"url":"https://fabrioza.com"}}</script>\n'
         % (repr(TITLE).replace("'", '"'), repr(URL).replace("'", '"'),
            repr(DESC).replace("'", '"')))

# This template defines `table` but NOT .table-wrap/.hero-stats/.hero-stat -
# those live in the product-page stylesheet. Borrowing the markup without the
# CSS made a 9-column table push the whole PAGE sideways and stacked the hero
# stats vertically. Caught by rendering it, not by reading it. Self-contained
# so no shared stylesheet is touched.
EXTRA_CSS = """
.table-wrap{overflow-x:auto;-webkit-overflow-scrolling:touch;margin:24px 0}
.table-wrap table{margin:0;min-width:760px}
.hero-stats{display:flex;justify-content:center;gap:26px;flex-wrap:wrap;margin-top:26px}
.hero-stat{text-align:center}
.hero-stat .num{font-size:1.7rem;font-weight:700;line-height:1.1}
.hero-stat .label{font-size:11px;opacity:.72;text-transform:uppercase;letter-spacing:1px}
.product-name{font-weight:600}
td.price{font-weight:700;color:#2d5a3d;white-space:nowrap}
td.moq,td.check{white-space:nowrap;font-size:13px}
"""
head = head + '<style>' + EXTRA_CSS + '</style>' + chr(10)

page = head + '</head>' + tpl[head_end + 7:a] + NEW + tpl[b:]
page = page.replace('source_page:\'/coste-fabricacion-camiseta\'', "source_page:'/%s'" % SLUG)

out = ROOT / SLUG
out.mkdir(exist_ok=True)
(out / 'index.html').write_text(page, encoding='utf-8', newline='')

# ---- checks -------------------------------------------------------------
o = int(len(re.findall(r'<div\b', page))), int(len(re.findall(r'</div>', page)))
print('  div balance: %d open / %d close  %s' % (o[0], o[1], 'OK' if o[0] == o[1] else 'MISMATCH'))
print('  bytes: %d' % len(page.encode('utf-8')))
print('  table rows: %d' % len(re.findall(r'<tr>\n<td><span class="product-name">', page)))
for need in ('Desde 20', 'source_page:\'/%s\'' % SLUG, 'hreflang="en"', '<form', 'fabAttribution'):
    print('  %-34s %s' % (need[:32], 'present' if need in page else 'MISSING'))
assert o[0] == o[1]
