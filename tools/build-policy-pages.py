# -*- coding: utf-8 -*-
"""Build the Safepay-required policy pages from one shell and one content file each.

    python -I tools/build-policy-pages.py            # dry run, reports every page
    python -I tools/build-policy-pages.py --apply

WHY A GENERATOR AND NOT FIVE HAND-WRITTEN FILES

Each static page on this site is self-contained: two shared stylesheets plus its
own ~8.6 KB inline <style>, a single-line fz-nav header, a hero, the content, a
footer, the WhatsApp button and three script tags. That is ~26 KB of identical
shell per page. Hand-copying it five times guarantees the five pages drift from
each other and from the rest of the site.

THE SHELL IS READ FROM A LIVE PAGE, NOT RETYPED.

SHELL_SRC below is an existing, known-good English page. Every shared part is
extracted from it verbatim at build time. That matters for one specific reason:
the footer's language row contains "Francais", "Espanol" and "Portugues" with
their real accented characters, and the repo's own history shows a Windows
console will happily render those as mojibake. Retyping them from a terminal
dump would have written the mojibake into five new pages. Reading the bytes
cannot make that mistake.

Every extraction is asserted. A regex that silently matches nothing would
otherwise produce a page with no stylesheet, or no footer, and still exit 0.

LINE ENDINGS: this repo has mixed endings per file and git converts nothing, so
every file written here is explicitly LF via newline='\\n'.
"""
import pathlib
import re
import sys

REPO = pathlib.Path(__file__).resolve().parent.parent
DIST = REPO / "dist"
SHELL_SRC = DIST / "order-protection" / "index.html"
CONTENT_DIR = REPO / "tools" / "policy-content"
SITE = "https://fabrioza.com"
UPDATED = "26 November 2026"

APPLY = "--apply" in sys.argv


# --------------------------------------------------------------------------
# The pages. `content` is the file in tools/policy-content/ holding the prose
# that sits inside .content > .container, after the breadcrumb.
# Descriptions are kept under 160 characters: tools/fix-meta-lengths.py exists
# in this repo because that limit has been missed before.
# --------------------------------------------------------------------------
PAGES = [
    {
        "slug": "refund-policy",
        "content": "refund-policy.html",
        "title": "Refund and Return Policy | FABRIOZA",
        "og_title": "Refund and Return Policy",
        "h1": "Refund and Return Policy",
        "tag": "Before you order",
        "desc": "FABRIOZA refund and return policy: custom production, four approval "
                "stages, cancellation and deposits, and a free remake for any "
                "manufacturing defect.",
        "keywords": "clothing manufacturer refund policy, custom clothing returns, "
                    "garment defect remake, clothing order cancellation, "
                    "manufacturer deposit refund",
        "chips": ["Custom production", "7-day defect window", "Remake at our cost"],
    },
    {
        "slug": "terms",
        "content": "terms.html",
        "title": "Terms and Conditions | FABRIOZA",
        "og_title": "Terms and Conditions",
        "h1": "Terms and Conditions",
        "tag": "The agreement",
        "desc": "FABRIOZA terms and conditions: quotations, payment terms, your "
                "artwork rights, measurement tolerances, delivery and risk, and "
                "governing law.",
        "keywords": "clothing manufacturer terms and conditions, garment "
                    "manufacturing agreement, measurement tolerance clothing, "
                    "cut and sew terms, apparel production terms",
        "chips": ["30-day quotes", "&plusmn;1 cm tolerance", "Pakistan law"],
    },
    {
        "slug": "privacy",
        "content": "privacy.html",
        "title": "Privacy Policy | FABRIOZA",
        "og_title": "Privacy Policy",
        "h1": "Privacy Policy",
        "tag": "Your data",
        "desc": "What FABRIOZA collects, why, and who else sees it. We never see "
                "your full card number, we never sell data, and you can have "
                "yours deleted by email.",
        "keywords": "clothing manufacturer privacy policy, fabrioza privacy, "
                    "garment manufacturer data protection, customer artwork "
                    "confidentiality",
        "chips": ["No data selling", "No card numbers held", "Deletion on request"],
    },
    {
        "slug": "shipping-policy",
        "content": "shipping-policy.html",
        "title": "Shipping and Delivery Policy | FABRIOZA",
        "og_title": "Shipping and Delivery Policy",
        "h1": "Shipping and Delivery Policy",
        "tag": "Getting your order",
        "desc": "FABRIOZA ships worldwide by DHL Express, UPS and FedEx with "
                "tracking on every shipment. Transit times, duty options, and "
                "lost or damaged claims.",
        "keywords": "clothing manufacturer shipping policy, DHL clothing "
                    "manufacturer, DDP clothing shipping, garment delivery times, "
                    "apparel import duty",
        "chips": ["DHL &middot; UPS &middot; FedEx", "3 to 8 business days", "DDP available"],
    },
    {
        "slug": "payment-methods",
        "content": "payment-methods.html",
        "title": "Payment Methods | FABRIOZA",
        "og_title": "Payment Methods",
        "h1": "Payment Methods",
        "tag": "How to pay",
        "desc": "Pay FABRIOZA by card link via Safepay, bank transfer in USD, "
                "Remitly or Western Union. Payment terms, invoice references and "
                "a short fraud warning.",
        "keywords": "pay clothing manufacturer by card, safepay clothing "
                    "manufacturer, clothing factory bank transfer, western union "
                    "clothing factory, clothing manufacturer payment terms",
        "chips": ["Visa &middot; Mastercard", "Secured by Safepay", "USD &middot; EUR &middot; GBP"],
    },
    {
        "slug": "contact",
        "content": "contact.html",
        "title": "Contact FABRIOZA | Clothing Manufacturer in Sialkot",
        "og_title": "Contact FABRIOZA",
        "h1": "Contact FABRIOZA",
        "tag": "Talk to the factory",
        "page_type": "ContactPage",
        "form": True,
        "desc": "Contact FABRIOZA directly: Saro Street, Sialkot, Pakistan. "
                "Email info@fabrioza.com or WhatsApp +92 312 4815601. Monday to "
                "Saturday, 10:00 to 19:00 PKT.",
        "keywords": "contact clothing manufacturer, fabrioza contact, "
                    "clothing manufacturer sialkot contact, whatsapp clothing "
                    "factory pakistan",
        "chips": ["Sialkot, Pakistan", "Mon&ndash;Sat 10:00&ndash;19:00 PKT", "Reply in 1 working day"],
    },
    {
        "slug": "bulk-clothing-manufacturer",
        "updated": "2026-11-27",
        "content": "bulk-clothing-manufacturer.html",
        "title": "Bulk Clothing Manufacturer | 1,000+ Units, 46% at 5,000",
        "og_title": "Bulk and Contract Clothing Manufacturing",
        "h1": "Bulk and Contract Clothing Manufacturing",
        "tag": "1,000 pieces and up",
        "form": True,
        "desc": "Unit prices to 10,000+, 8 production lines, AQL 2.5 inspection "
                "and Letter of Credit at sight. Test a style at 50 pieces before "
                "committing to 5,000.",
        "keywords": "bulk clothing manufacturer, contract clothing manufacturer, "
                    "wholesale clothing manufacturer, large order clothing "
                    "manufacturer, 5000 units clothing manufacturer, volume "
                    "apparel production",
        "chips": ["1,000 to 10,000+ units", "46% at 5,000 pcs", "L/C at sight"],
    },
    {
        "slug": "university-club-kits",
        "content": "university-club-kits.html",
        "updated": "2026-11-27",
        "title": "University &amp; Club Kit Supplier | 20 Per Design, Names Free",
        "og_title": "Kit Supplier for Universities, Clubs and Teams",
        "h1": "Kit Supplier for Universities, Clubs and Teams",
        "tag": "One squad, not one container",
        "form": True,
        "desc": "Team kit from 20 pieces per design, 15 per full kit. Player names "
                "and numbers free on sublimation, XS-3XL size run to try first, "
                "every sport from one factory.",
        "keywords": "university kit supplier, college team uniforms, club kit "
                    "manufacturer, team kit supplier, university sports kit, "
                    "custom club teamwear, school sports kit manufacturer",
        "chips": ["20 per design", "Names &amp; numbers free", "XS&ndash;3XL size run"],
    },
    {
        "slug": "wholesale-clothing-distributor",
        "content": "wholesale-clothing-distributor.html",
        "updated": "2026-11-27",
        "title": "Wholesale Clothing Supplier for Distributors | Private Label",
        "og_title": "Wholesale and Distributor Supply",
        "h1": "Wholesale Supply for Distributors and Resellers",
        "tag": "Buying to resell",
        "form": True,
        "desc": "Factory-direct private label supply: your labels not ours, no "
                "published customer work, 46% at 5,000, L/C at sight, and a "
                "50-piece minimum to test a line.",
        "keywords": "wholesale clothing supplier, clothing distributor supplier, "
                    "private label clothing manufacturer, white label clothing "
                    "supplier, bulk apparel wholesale, reseller clothing supplier",
        "chips": ["Your label, never ours", "46% at 5,000 pcs", "Test a line at 50"],
    },
]


# --------------------------------------------------------------------------
# Extract the shell, verbatim, from a real page.
# --------------------------------------------------------------------------
def grab(pattern, text, what, flags=re.S):
    m = re.search(pattern, text, flags)
    if not m:
        sys.exit(f"FATAL: could not find {what} in {SHELL_SRC}. "
                 "The template page changed shape; fix this script rather than "
                 "shipping pages without it.")
    return m.group(0)


shell = SHELL_SRC.read_text(encoding="utf-8")

CSS_LINKS = "\n".join(re.findall(r'<link rel="stylesheet"[^>]*>', shell))
assert CSS_LINKS.count("stylesheet") == 2, f"expected 2 stylesheets, got {CSS_LINKS!r}"

STYLE = grab(r"<style[^>]*>.*?</style>", shell, "the inline <style> block")
HEADER = grab(r'<header class="fz-nav">.*?</header>', shell, "the fz-nav header")
FOOTER = grab(r'<footer class="site-footer">.*?</footer>', shell, "the site-footer")
FAB = grab(r'<a class="wa-fab".*?</a>', shell, "the WhatsApp button")
SCRIPTS = grab(r'<script src="/assets/site-nav\.js.*?</script>(?=\s*</body>)', shell,
               "the trailing script tags")

# Everything between </style> and </head>: Google Analytics 4, the first-touch
# attribution capture, and Microsoft Clarity. The first version of this script
# left these out, which would have shipped five pages with no analytics while
# every other page on the site has them - and made the new Privacy Policy's
# description of the site's own tracking untrue on the page stating it.
_i = shell.index("</style>") + len("</style>")
HEAD_TAIL = shell[_i:shell.index("</head>")].strip("\n")
for _needle in ("googletagmanager.com", "gtag(", "clarity", "attribution"):
    assert _needle in HEAD_TAIL, f"HEAD_TAIL is missing {_needle!r}"

# The enquiry-form wiring. It returns early when no #lqForm is on the page, so
# it is harmless everywhere and required on /contact.
FORM_WIRING = grab(r"<script>\s*\(function\(\)\{\s*var f=document\.getElementById\('lqForm'\).*?</script>",
                   shell, "the enquiry-form wiring script")
FORM = grab(r'<form[^>]*class="lq-form".*?</form>', shell, "the enquiry form")
assert "csrf.php" in FORM_WIRING and "send-email.php" in FORM_WIRING

# These two carry the template page's own slug and must become per-page.
assert "from=/order-protection" in HEADER, "header CTA no longer carries ?from="
assert "order-protection" in FAB, "WhatsApp button no longer carries the page slug"


def page_html(p):
    url = f"{SITE}/{p['slug']}"
    header = HEADER.replace("from=/order-protection", f"from=/{p['slug']}")
    wa_text = (
        "Hi%20FABRIOZA%2C%20I%20have%20a%20question%20about%20your%20"
        + p["h1"].replace(" ", "%20")
        + f".%20%28via%20fabrioza.com%2F{p['slug']}%29"
    )
    fab = re.sub(r"\?text=[^\"]*", "?text=" + wa_text, FAB, count=1)

    chips = "<span>&middot;</span>".join(f"<span>{c}</span>" for c in p["chips"])
    body = (CONTENT_DIR / p["content"]).read_text(encoding="utf-8").rstrip("\n")

    # Only /contact carries the enquiry form. It is the site's existing form
    # markup, reused verbatim and posting to the same endpoints, so nothing
    # about the lead pipeline or the CRM changes.
    form_html = ""
    if p.get("form"):
        form_html = (
            '\n<div class="cta-box" id="enquiry" style="margin-top:34px">\n'
            "  <h2 style=\"margin-top:0\">Send us an enquiry</h2>\n"
            "  <p>Tell us what you want to make. We reply within one working day,\n"
            "  Monday to Saturday.</p>\n"
            f"  {FORM}\n"
            "</div>\n"
        )

    return f"""<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>{p['title']}</title>
<meta name="description" content="{p['desc']}">
<meta name="keywords" content="{p['keywords']}">
<meta name="robots" content="index, follow, max-snippet:-1, max-image-preview:large">
<link rel="canonical" href="{url}">
<link rel="alternate" hreflang="en" href="{url}">
<link rel="alternate" hreflang="x-default" href="{url}">
<link rel="icon" type="image/jpeg" href="/images/fabrioza-logo-sm.jpg">

<meta property="og:type" content="website">
<meta property="og:url" content="{url}">
<meta property="og:title" content="{p['og_title']}">
<meta property="og:description" content="{p['desc']}">
<meta property="og:site_name" content="FABRIOZA">

<script type="application/ld+json">
{{
 "@context": "https://schema.org",
 "@graph": [
  {{
   "@type": "Organization",
   "@id": "{SITE}/#organization",
   "name": "FABRIOZA",
   "url": "{SITE}",
   "logo": "{SITE}/images/fabrioza-logo.jpg",
   "email": "info@fabrioza.com",
   "telephone": "+92-312-4815601",
   "taxID": "3130171989577",
   "founder": {{"@type": "Person", "name": "Muhammad Yasin Ishfaq"}},
   "address": {{
    "@type": "PostalAddress",
    "streetAddress": "Saro Street, near Fateh Garh Road",
    "addressLocality": "Sialkot",
    "postalCode": "51310",
    "addressRegion": "Punjab",
    "addressCountry": "PK"
   }},
   "paymentAccepted": ["Credit Card", "Bank Transfer"]
  }},
  {{
   "@type": "{p.get('page_type', 'WebPage')}",
   "name": "{p['og_title']}",
   "description": "{p['desc']}",
   "url": "{url}",
   "inLanguage": "en",
   "dateModified": "{p.get('updated', '2026-11-26')}",
   "isPartOf": {{"@id": "{SITE}/#organization"}},
   "publisher": {{"@id": "{SITE}/#organization"}}
  }},
  {{
   "@type": "BreadcrumbList",
   "itemListElement": [
    {{"@type": "ListItem", "position": 1, "name": "Home", "item": "{SITE}/"}},
    {{"@type": "ListItem", "position": 2, "name": "{p['og_title']}", "item": "{url}"}}
   ]
  }}
 ]
}}
</script>

{CSS_LINKS}
{STYLE}
{HEAD_TAIL}
</head>
<body>

{header}

<div class="hero">
  <div class="container">
    <div class="category-tag">{p['tag']}</div>
    <h1>{p['h1']}</h1>
    <div class="meta">{chips}</div>
  </div>
</div>

<div class="content">
  <div class="container">
    <div class="breadcrumb"><a href="/">Home</a> &rsaquo; <span>{p['og_title']}</span></div>

{body}
{form_html}
  </div>
</div>

{FOOTER}
{fab}
{FORM_WIRING}
{SCRIPTS}
</body>
</html>
"""


def main():
    print(f"shell source : {SHELL_SRC.relative_to(REPO)}")
    print(f"style block  : {len(STYLE)} chars")
    print(f"mode         : {'APPLY' if APPLY else 'DRY RUN'}\n")

    for p in PAGES:
        out = DIST / p["slug"] / "index.html"
        html = page_html(p)

        # Every page must carry a date, but not all the same one: a page added
        # later says when IT was written. Asserting one shared constant would
        # either reject the new page or force a false date onto the old ones.
        stamp = re.search(r"Last updated: (\d{1,2} \w+ \d{4})", html)
        if not stamp:
            sys.exit(f"FATAL: {p['slug']} has no 'Last updated: <date>' line.")

        existed = out.exists()
        before = out.stat().st_size if existed else 0
        state = f"overwrite ({before} B)" if existed else "create"

        if APPLY:
            out.parent.mkdir(parents=True, exist_ok=True)
            out.write_text(html, encoding="utf-8", newline="\n")

        print(f"  {'wrote' if APPLY else 'would write'}  /{p['slug']:<17} "
              f"{len(html.encode()):>6} B   [{state}]")

    print(f"\n{len(PAGES)} pages."
          + ("" if APPLY else "  Re-run with --apply to write them."))
    print("\nNOW RUN:  python -I tools/build-footer.py --apply")
    print("This script does not own the policy footer; build-footer.py does, for")
    print("all 150 pages at once. Note the shell is read from a page that has")
    print("ALREADY been footer-processed, so a generated page usually arrives")
    print("with the block already in it - but that is a side effect of the")
    print("template, not a guarantee. build-footer.py is sentinel-based and")
    print("idempotent, so running it again is always safe and always correct.")


if __name__ == "__main__":
    main()
