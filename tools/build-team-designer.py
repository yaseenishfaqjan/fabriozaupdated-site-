# -*- coding: utf-8 -*-
"""Build /team-designer from the site shell plus tools/team-designer/body.html.

    python -I tools/build-team-designer.py            # dry run
    python -I tools/build-team-designer.py --apply

Why this is not part of build-policy-pages.py: that generator drops its content
inside `.content > .container`, which is max-width:860px. The designer needs the
full 1180px and carries its own <style> and <script>, so it is placed directly
inside `.content` instead. Everything else - the nav line, the two stylesheets,
the 8.6 KB inline style block, the footer, the WhatsApp button and the trailing
scripts - is read verbatim from a live page, exactly as the policy generator
does, so the page cannot drift from the rest of the site.
"""
import pathlib
import re
import sys

REPO = pathlib.Path(__file__).resolve().parent.parent
DIST = REPO / "dist"
SHELL_SRC = DIST / "order-protection" / "index.html"
BODY = REPO / "tools" / "team-designer" / "body.html"
SITE = "https://fabrioza.com"
SLUG = "team-designer"
APPLY = "--apply" in sys.argv


def grab(pattern, text, what):
    m = re.search(pattern, text, re.S)
    if not m:
        sys.exit(f"FATAL: could not find {what} in {SHELL_SRC}")
    return m.group(0)


shell = SHELL_SRC.read_bytes().decode("utf-8")
CSS_LINKS = "\n".join(re.findall(r'<link rel="stylesheet"[^>]*>', shell))
assert CSS_LINKS.count("stylesheet") == 2
STYLE = grab(r"<style[^>]*>.*?</style>", shell, "the inline <style> block")
HEADER = grab(r'<header class="fz-nav">.*?</header>', shell, "the fz-nav header")
FOOTER = grab(r'<footer class="site-footer">.*?</footer>', shell, "the site-footer")
FAB = grab(r'<a class="wa-fab".*?</a>', shell, "the WhatsApp button")
SCRIPTS = grab(r'<script src="/assets/site-nav\.js.*?</script>(?=\s*</body>)', shell,
               "the trailing script tags")
_i = shell.index("</style>") + len("</style>")
HEAD_TAIL = shell[_i:shell.index("</head>")].strip("\n")
for needle in ("googletagmanager.com", "clarity"):
    assert needle in HEAD_TAIL, f"HEAD_TAIL missing {needle}"

header = HEADER.replace("from=/order-protection", f"from=/{SLUG}")
fab = re.sub(r"\?text=[^\"]*",
             "?text=Hi%20FABRIOZA%2C%20I%20am%20designing%20a%20team%20kit."
             f"%20%28via%20fabrioza.com%2F{SLUG}%29", FAB, count=1)

TITLE = "Team Uniform Designer | Design Your Kit Online | FABRIOZA"
DESC = ("Design a soccer, basketball or polo kit online: set your colours, add your "
        "club logo, upload the squad list and send it to the factory for a quotation.")
OG = "Team Uniform Designer"

html = f"""<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>{TITLE}</title>
<meta name="description" content="{DESC}">
<meta name="keywords" content="team uniform designer, design your own kit, custom jersey designer, team kit builder, online jersey design, custom teamwear designer">
<meta name="robots" content="index, follow, max-snippet:-1, max-image-preview:large">
<link rel="canonical" href="{SITE}/{SLUG}">
<link rel="alternate" hreflang="en" href="{SITE}/{SLUG}">
<link rel="alternate" hreflang="x-default" href="{SITE}/{SLUG}">
<link rel="icon" type="image/jpeg" href="/images/fabrioza-logo-sm.jpg">

<meta property="og:type" content="website">
<meta property="og:url" content="{SITE}/{SLUG}">
<meta property="og:title" content="{OG}">
<meta property="og:description" content="{DESC}">
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
   "address": {{
    "@type": "PostalAddress",
    "streetAddress": "Saro Street, near Fateh Garh Road",
    "addressLocality": "Sialkot",
    "postalCode": "51310",
    "addressRegion": "Punjab",
    "addressCountry": "PK"
   }}
  }},
  {{
   "@type": "WebApplication",
   "name": "{OG}",
   "description": "{DESC}",
   "url": "{SITE}/{SLUG}",
   "applicationCategory": "DesignApplication",
   "operatingSystem": "Any modern browser",
   "inLanguage": "en",
   "dateModified": "2026-11-27",
   "isPartOf": {{"@id": "{SITE}/#organization"}},
   "publisher": {{"@id": "{SITE}/#organization"}}
  }},
  {{
   "@type": "BreadcrumbList",
   "itemListElement": [
    {{"@type": "ListItem", "position": 1, "name": "Home", "item": "{SITE}/"}},
    {{"@type": "ListItem", "position": 2, "name": "{OG}", "item": "{SITE}/{SLUG}"}}
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
    <div class="category-tag">Design it yourself</div>
    <h1>Team Uniform Designer</h1>
    <div class="meta"><span>Soccer &middot; Basketball &middot; Polo</span><span>&middot;</span><span>Names &amp; numbers free</span><span>&middot;</span><span>Quotation in 1 working day</span></div>
  </div>
</div>

<div class="content">
  <div class="container">
    <div class="breadcrumb"><a href="/">Home</a> &rsaquo; <span>{OG}</span></div>
  </div>

{BODY.read_text(encoding="utf-8").rstrip()}

</div>

{FOOTER}
{fab}
{SCRIPTS}
</body>
</html>
"""

out = DIST / SLUG / "index.html"
if APPLY:
    out.parent.mkdir(parents=True, exist_ok=True)
    out.write_text(html, encoding="utf-8", newline="\n")

print(f"{'wrote' if APPLY else 'would write'}  /{SLUG}  {len(html.encode())} B"
      f"  [{'overwrite' if out.exists() else 'create'}]")
print("\nNOW RUN:  python -I tools/build-footer.py --apply")
