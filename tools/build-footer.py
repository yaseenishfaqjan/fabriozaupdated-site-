# -*- coding: utf-8 -*-
"""One policy footer, built from one source, applied to every static page.

    python -I tools/build-footer.py              # dry run, reports every page
    python -I tools/build-footer.py --apply

WHY THIS EXISTS

Safepay reviews a merchant's website before approving it, and expects the
policy pages to be reachable from every page rather than buried. This repo has
145 static pages carrying THREE different footer markups:

    <footer class="site-footer">   84 pages
    <footer class="footer">        43 pages
    <footer>                       16 pages
    (no footer at all)              2 pages  - dist/blog/index.html and the
                                              homepage, whose footer React
                                              renders from the minified bundle

Hand-editing that is how the nav came to have 35 distinct link-sets (see
build-nav.py). One source, applied everywhere, is the only version that stays
true a month from now.

FIVE THINGS THAT WOULD SILENTLY BREAK, all handled below:

1. LINE ENDINGS are mixed per file in this repo and git converts nothing. The
   injected markup is therefore a SINGLE LINE containing no newline at all, so
   no file's endings can change and no diff can balloon.

2. ENCODING. The six translated footers contain real accented characters. Every
   label here is written as an HTML ENTITY (&uuml;, &iacute;) rather than a
   literal, so this file is pure ASCII and no editor, console or pipe can turn
   "Ruckgaberichtlinie" into mojibake on 84 pages.

3. THE SIX LANGUAGES MUST NOT BE OVERWRITTEN WITH ENGLISH. Language is read
   from <html lang="xx">, and a page in a language with no label set is SKIPPED
   rather than given English links.

4. NO STYLESHEET IS TOUCHED. Each page carries its own inline <style>, and
   there is no one CSS file every page loads. The block is styled with inline
   style attributes, which is what the existing footers already do
   (style="color:inherit;text-decoration:underline").

5. IDEMPOTENCE. The block is wrapped in a sentinel comment. Re-running replaces
   the existing block rather than appending a second one, so this is safe to
   run after every content change.
"""
import pathlib
import re
import sys

REPO = pathlib.Path(__file__).resolve().parent.parent
DIST = REPO / "dist"
APPLY = "--apply" in sys.argv

SENTINEL_OPEN = "<!--policy-footer-->"
SENTINEL_CLOSE = "<!--/policy-footer-->"

ADDRESS = ("Saro Street, near Fateh Garh Road, Sialkot 51310, Punjab, Pakistan")
EMAIL = "info@fabrioza.com"
PHONE_DISPLAY = "+92 312 4815601"
PHONE_WA = "923124815601"

# Policy pages are English-only; the LABELS are translated so a localised
# footer does not suddenly read half in English.
LABELS = {
    "en": ["Refund Policy", "Terms &amp; Conditions", "Privacy Policy",
           "Shipping Policy", "Payment Methods", "Contact"],
    "fr": ["Politique de remboursement", "Conditions g&eacute;n&eacute;rales",
           "Confidentialit&eacute;", "Politique d&rsquo;exp&eacute;dition",
           "Moyens de paiement", "Contact"],
    "de": ["R&uuml;ckgaberichtlinie", "AGB", "Datenschutz",
           "Versandrichtlinie", "Zahlungsmethoden", "Kontakt"],
    "it": ["Politica di rimborso", "Termini e condizioni", "Privacy",
           "Politica di spedizione", "Metodi di pagamento", "Contatti"],
    "es": ["Pol&iacute;tica de reembolso", "T&eacute;rminos y condiciones",
           "Privacidad", "Pol&iacute;tica de env&iacute;o",
           "M&eacute;todos de pago", "Contacto"],
    "nl": ["Retourbeleid", "Algemene voorwaarden", "Privacybeleid",
           "Verzendbeleid", "Betaalmethoden", "Contact"],
    "pt": ["Pol&iacute;tica de reembolso", "Termos e condi&ccedil;&otilde;es",
           "Privacidade", "Pol&iacute;tica de envio", "M&eacute;todos de pagamento",
           "Contacto"],
}

HREFS = ["/refund-policy", "/terms", "/privacy", "/shipping-policy",
         "/payment-methods", "/contact"]

# "Secure payments by Safepay" - Safepay is a product name and stays as-is.
SECURED_BY = {
    "en": "Secure payments by Safepay",
    "fr": "Paiements s&eacute;curis&eacute;s par Safepay",
    "de": "Sichere Zahlungen mit Safepay",
    "it": "Pagamenti sicuri con Safepay",
    "es": "Pagos seguros con Safepay",
    "nl": "Veilige betalingen via Safepay",
    "pt": "Pagamentos seguros com Safepay",
}

LINK_STYLE = "color:inherit;text-decoration:underline"
ROW = "margin:10px 0 0;font-size:0.85rem;line-height:1.9"

# Inline SVG only - no external images, per the brief. Visa as its wordmark,
# Mastercard as its two interlocking circles, both at 20px tall and both
# labelled for screen readers.
VISA_SVG = (
    '<svg role="img" aria-label="Visa" viewBox="0 0 48 20" width="38" height="16"'
    ' style="vertical-align:middle">'
    '<rect width="48" height="20" rx="3" fill="#ffffff"/>'
    '<text x="24" y="14.5" text-anchor="middle" font-family="Arial,Helvetica,sans-serif"'
    ' font-size="12" font-weight="700" font-style="italic" fill="#1a1f71">VISA</text>'
    "</svg>"
)
MASTERCARD_SVG = (
    '<svg role="img" aria-label="Mastercard" viewBox="0 0 48 20" width="38" height="16"'
    ' style="vertical-align:middle">'
    '<rect width="48" height="20" rx="3" fill="#ffffff"/>'
    '<circle cx="20" cy="10" r="6.5" fill="#eb001b"/>'
    '<circle cx="28" cy="10" r="6.5" fill="#f79e1b"/>'
    '<path d="M24 4.9a6.5 6.5 0 0 0 0 10.2 6.5 6.5 0 0 0 0-10.2z" fill="#ff5f00"/>'
    "</svg>"
)


def block_for(lang):
    labels = LABELS[lang]
    links = " &middot; ".join(
        f'<a href="{h}" style="{LINK_STYLE}">{t}</a>'
        for h, t in zip(HREFS, labels)
    )
    return (
        f'{SENTINEL_OPEN}'
        f'<div class="pf-policy" style="margin-top:14px">'
        f'<p style="{ROW}">{links}</p>'
        f'<p style="{ROW}">FABRIOZA &middot; {ADDRESS} &middot; '
        f'<a href="mailto:{EMAIL}" style="{LINK_STYLE}">{EMAIL}</a> &middot; '
        f'<a href="https://wa.me/{PHONE_WA}" rel="nofollow" style="{LINK_STYLE}">'
        f'{PHONE_DISPLAY}</a></p>'
        # The payment row is the last line on every page now, and the WhatsApp
        # button is fixed to the bottom-right corner. At 375px it sat on top of
        # "Safepay". The extra bottom margin lifts it clear; on desktop it is
        # just footer whitespace. Measured in a browser at 375x812, not guessed.
        f'<p style="{ROW};margin-bottom:46px">{VISA_SVG} {MASTERCARD_SVG} '
        f'<span style="opacity:.75">{SECURED_BY[lang]}</span></p>'
        f'</div>'
        f'{SENTINEL_CLOSE}'
    )


# Assert the markup really is one line, because point 1 above is the whole
# reason this script exists in this shape.
for _lang in LABELS:
    assert "\n" not in block_for(_lang), f"{_lang} block contains a newline"


def main():
    pages = sorted(DIST.rglob("index.html"))
    stats = {"inserted": 0, "replaced": 0, "no-footer": 0, "no-lang": 0}
    print(f"mode: {'APPLY' if APPLY else 'DRY RUN'}   pages: {len(pages)}\n")

    for p in pages:
        raw = p.read_bytes()
        h = raw.decode("utf-8")
        rel = "/" + p.parent.relative_to(DIST).as_posix().replace(".", "")

        m = re.search(r'<html[^>]*\blang="([a-zA-Z-]+)"', h)
        lang = (m.group(1).split("-")[0].lower() if m else "")
        if lang not in LABELS:
            stats["no-lang"] += 1
            print(f"  SKIP  {rel:<42} lang={lang or '?'} has no label set")
            continue

        blk = block_for(lang)

        if "</footer>" not in h:
            # /blog has footer CONTENT (the trust line and the dark language
            # bar) but no <footer> element, and no .site-footer rule in its own
            # inline <style> - so a bare <footer class="site-footer"> appended
            # here would render unstyled. Wrap the block in the same inline
            # styling .lang-bar-inline already uses on that page instead, which
            # depends on no stylesheet at all.
            #
            # The homepage has no footer markup of any kind because React
            # renders it from the minified bundle; it is deliberately left to a
            # separate, hand-checked edit of that bundle.
            if "lang-bar-inline" not in h:
                stats["no-footer"] += 1
                print(f"  SKIP  {rel:<42} no footer markup (React-rendered)")
                continue
            wrapped = (
                '<div class="pf-standalone" style="background:#1a1a1a;color:#999;'
                'text-align:center;padding:4px 20px 20px;font-size:0.85rem">'
                + blk + "</div>"
            )
            if SENTINEL_OPEN in h:
                new = re.sub(
                    r'<div class="pf-standalone".*?</div>' + re.escape(SENTINEL_CLOSE) + "</div>",
                    lambda _: wrapped, h, count=1, flags=re.S)
                action = "replaced"
            else:
                i = h.rfind("</body>")
                new = h[:i] + wrapped + "\n" + h[i:]
                action = "inserted"
            out = new.encode("utf-8")
            if raw.count(b"\r\n") != out.count(b"\r\n"):
                sys.exit(f"FATAL: {rel} line endings changed. Refusing to write.")
            if APPLY:
                p.write_bytes(out)
            stats[action] += 1
            print(f"  {action:<8} {rel:<42} lang={lang} (standalone, no <footer>)")
            continue

        if SENTINEL_OPEN in h:
            new = re.sub(
                re.escape(SENTINEL_OPEN) + ".*?" + re.escape(SENTINEL_CLOSE),
                lambda _: blk, h, count=1, flags=re.S,
            )
            action = "replaced"
        else:
            # Insert before the LAST </footer>, so a nested one cannot catch it.
            i = h.rfind("</footer>")
            new = h[:i] + blk + h[i:]
            action = "inserted"

        before_crlf = raw.count(b"\r\n")
        out = new.encode("utf-8")
        after_crlf = out.count(b"\r\n")
        if before_crlf != after_crlf:
            sys.exit(f"FATAL: {rel} line endings changed "
                     f"({before_crlf} -> {after_crlf}). Refusing to write.")

        if APPLY:
            p.write_bytes(out)
        stats[action] += 1
        print(f"  {action:<8} {rel:<42} lang={lang}")

    print("\n" + "  ".join(f"{k}={v}" for k, v in stats.items()))
    if not APPLY:
        print("\nDry run. Re-run with --apply to write.")


if __name__ == "__main__":
    main()
