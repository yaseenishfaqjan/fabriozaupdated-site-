# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this repository is

This is the **deployment folder** for fabrioza.com (a B2B clothing-manufacturer marketing site), NOT the source project. `dist/` contains the built output that gets uploaded to cPanel. There is no build system, package.json, or test suite here — the Vite/React source lives elsewhere. Edits made here are edits to the live-site payload; any change made only in `dist/` will be lost if the site is ever rebuilt from source, so mirror important changes (e.g. `vite.config` `base: '/'`, nav links) back into the source project.

## Deployment

- Target: **VPS via Docker** (switched from cPanel in July 2026). `Dockerfile` (php:8.3-apache with rewrite/headers/expires/deflate enabled and `AllowOverride All`) + `docker-compose.yml` in the repo root are the deploy unit: `docker compose up -d --build` serves on port 8080.
- An SSL-terminating reverse proxy (Caddy/nginx/Traefik) must sit in front and forward `X-Forwarded-Proto: https` — the `.htaccess` HTTPS 301 checks that header to avoid a redirect loop behind the proxy.
- The full routing behavior was verified against this exact image with a 126-check URL matrix (see scratchpad test script pattern in git history / verification section below).
- After deploying SEO-relevant changes, hit `https://fabrioza.com/indexnow.php` once (submits all sitemap URLs to Bing/Yandex via IndexNow) and resubmit `sitemap.xml` in Bing Webmaster Tools / GSC.

## Known gaps

- **Contact form email does not send on the VPS image**: `api/send-email.php` uses PHP `mail()`, which needs an MTA that the php:apache image doesn't have (it worked on cPanel because of exim). Switch it to SMTP (PHPMailer + domain mailbox / transactional provider credentials) or add an msmtp relay to the image before go-live.

## Architecture

- **Hybrid static + SPA**: the homepage (`/`) and `/privacy` are the only React (BrowserRouter) routes in `assets/index-*.js`. Every other indexable page (`/pricing`, `/custom-hoodie-manufacturer`, `/blog/*`, etc.) is a **prerendered static page stored as `folder/index.html`**.
- **Canonical URL convention: no trailing slash** (root `/` excepted), no `/index.html` suffix. `.htaccess` enforces this with 301s; every page's `<link rel="canonical">` follows it. Keep sitemap entries, internal links, and new pages consistent with it.
- `/about`, `/contact`, `/products`, `/moq`, `/process`, `/factory`, `/faq`, `/home` are **not pages** — they are 301s in `.htaccess` to homepage anchors (`/#factory`, `/#contact`, `/#catalog`, `/#calculator`, `/#how-it-works`, …). The `about/`, `contact/`, `products/`, `moq/` folders on disk are legacy meta-refresh stubs that are never served while the 301s exist; don't add them back to the sitemap.
- `/what-we-make` intentionally returns a hard 404 (rule in `.htaccess`) until a real page is built.
- `api/send-email.php` is the only backend code (form handler using PHP `mail()`); the SPA fallback and robots.txt both exclude `/api/`.

## .htaccess — critical rules

- **Never use `Redirect`/`RedirectMatch` (mod_alias) in this file.** In `.htaccess` context Apache evaluates ALL mod_rewrite rules before mod_alias regardless of file order, so a `RedirectMatch` is silently dead whenever any `RewriteRule` (SPA fallback, directory-serve) also matches. All redirects must be `RewriteRule ... [R=301,L]` inside the single rewrite chain.
- Rule order in the chain is the precedence order: HTTPS/www → explicit 301s → `/index.html`-suffix strip → trailing-slash strip → directory-serve (`folder/index.html`, internal, no hop) → SPA fallback. Redirects must stay above the routing rules.
- Redirect targets containing `#` need the `[NE]` flag or the hash gets percent-encoded.
- `DirectorySlash Off` + the internal directory-serve rule is what lets `/pricing` return 200 directly (mod_dir would otherwise 301 to `/pricing/`). The trailing-slash strip deliberately has **no `!-d` condition** — real pages ARE directories here.
- The `/index.html`-strip rule must keep its `THE_REQUEST` condition, otherwise it loops with the internal directory-serve rewrite.

## Collection pages (merged July 2026: femme-v10 zip, then v13 zip)

- Static catalog pages, all in the SPA nav + sitemap, each with CollectionPage/ItemList JSON-LD and keyword meta: `/sportswear-catalog`, `/streetwear-collection`, `/premium-fashion`, `/fashion-wear`, `/femme-collection` (differentiated everyday-womenswear range as of v13, self-canonical), `/luxury-blazers`, `/medical-scrubs`, `/kids-clothing`, `/plus-size`, plus the `/what-we-make` category hub (v13 delivered the real page; its old hard-404 rule was removed).
- The live SPA bundle is `assets/index-geU9U1Fa.js` (+ `index-BGDnZsgf.css`). Every bundle so far shipped with `/page/index.html` or `/page/` style hrefs and was **patched post-build** to clean no-slash URLs (nav array, footer, blog cards, `` `/blog/${p.slug}` `` template). Re-apply on any new bundle, or fix the source components.
- Older bundles (`index-DgoEfwip.js`, `index-sSz8uoIy.js`, `index-CFJ81nDD.css`) are kept so cached HTML doesn't break; safe to delete after a few days in production.
- Team zips are built from stale snapshots: shared files (blog pages, index.html head, .htaccess, sitemap, send-email.php) always arrive with pre-fix content (old canonicals, old US phone, no hardening). **Never extract a zip over dist — diff and copy only the new folders/images/bundle.**
- The homepage logo override CSS in `index.html` targets `[code-path="src/components/Navigation.tsx:<line>:11"]` attributes; the img/span line numbers shift with every bundle (v10: 36/37, v13: 37/38) — re-check on each bundle swap.

## Content gotchas

- Several blog folders are live **near-duplicate "twins"** of sitemap posts (e.g. `blog/fabric-gsm-guide` vs `blog/fabric-gsm-guide-clothing-manufacturing`, `blog/low-moq-clothing-manufacturer` vs `...-50-pieces`, `blog/what-is-moq-clothing` vs `...-manufacturing`). The twins are self-canonical, linked from live article bodies, and intentionally NOT in the sitemap. Consolidating each pair with a 301 is a pending content decision — don't add twins to the sitemap.
- Six legacy blog slugs 301 to consolidated articles (see `.htaccess`); their stub folders remain on disk but are never served.
- `/blog` is a hand-written static hub page (`blog/index.html`) — when adding a blog post, add it to the hub, the sitemap, and keep its canonical/og:url clean (no trailing slash, no `index.html`).
- Microsoft Clarity, Facebook Pixel, and the Bing `msvalidate.01` tag in the homepage `index.html` are **commented out** because only placeholder IDs exist. Instructions for enabling them are in the comment wrappers.

## Verifying after deploy

```
curl -sI https://fabrioza.com/products     # expect 301 -> https://fabrioza.com/#catalog
curl -sI https://fabrioza.com/pricing/     # expect 301 -> /pricing
curl -sI https://fabrioza.com/pricing      # expect 200 (no redirect hop)
curl -sI https://fabrioza.com/home         # expect 301 -> /
curl -sI https://fabrioza.com/blog         # expect 200 (hub page)
curl -sI https://fabrioza.com/what-we-make # expect 404
curl -sI https://fabrioza.com/pricing/index.html  # expect 301 -> /pricing
```
