# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this repository is

This is the **deployment folder** for fabrioza.com (a B2B clothing-manufacturer marketing site), NOT the source project. `dist/` contains the built output that gets uploaded to cPanel. There is no build system, package.json, or test suite here — the Vite/React source lives elsewhere. Edits made here are edits to the live-site payload; any change made only in `dist/` will be lost if the site is ever rebuilt from source, so mirror important changes (e.g. `vite.config` `base: '/'`, nav links) back into the source project.

## Deployment

- Target: **VPS via Docker** (switched from cPanel in July 2026). `Dockerfile` (php:8.3-apache with rewrite/headers/expires/deflate enabled and `AllowOverride All`) + `docker-compose.yml` in the repo root are the deploy unit: `docker compose up -d --build` serves on port 8080.
- An SSL-terminating reverse proxy (Caddy/nginx/Traefik) must sit in front and forward `X-Forwarded-Proto: https` — the `.htaccess` HTTPS 301 checks that header to avoid a redirect loop behind the proxy.
- The full routing behavior was verified against this exact image with a 126-check URL matrix (see scratchpad test script pattern in git history / verification section below).
- After deploying SEO-relevant changes, submit the CHANGED URLs to IndexNow --
  **after the container is actually serving them, never straight after the
  commit.** `https://fabrioza.com/indexnow.php` submits all 161 sitemap URLs
  at once; prefer one call per changed URL:

  ```bash
  K=6e0726a1783a50994f70e2ffd2987f22
  curl -s -o /dev/null -w '%{http_code}
' \n    "https://api.indexnow.org/indexnow?url=https://fabrioza.com/<path>&key=$K&keyLocation=https://fabrioza.com/$K.txt"
  ```

  **This bit us on 15 Nov 2026 and cost a page 15 hours of indexing.**
  `/custom-equestrian-apparel` was committed at 00:35:42; indexnow.php fired at
  00:36 with all 161 URLs; Bing crawled at 00:37 -- while `docker compose up
  --build` was still running. Bing recorded **"Indexing allowed? No -- URL
  cannot appear on Bing"** and kept it until the record was refreshed by hand.
  Nothing on our side was ever wrong: the live page serves 200 with
  `index, follow` and a self-canonical, and BWT's own **Live URL** test said
  "URL can be indexed by Bing". **The Bing Index tab shows the LAST CRAWL,
  not the page** -- always run Live URL before believing it, then
  Request indexing (quota 100/day) to refresh the stale record.
- Verify a deploy by curling the change, never by a script's exit code --
  **and cache-bust when the file is static.** `robots.txt` is served by
  Cloudflare with `Cache-Control: max-age=14400`, so for up to 4 hours after
  a correct deploy `curl https://fabrioza.com/robots.txt` returns the OLD
  file with `cf-cache-status: HIT` while the container already has the new
  one. Measured 15 Nov 2026. HTML pages update immediately; static files do
  not. Check all three before concluding anything:

  ```bash
  curl -sI https://fabrioza.com/robots.txt | grep -i 'cf-cache-status\|age:'
  curl -s "https://fabrioza.com/robots.txt?cb=$(date +%s)" | head -3
  ssh root@209.145.55.76 'docker exec fabrioza-web head -3 /var/www/html/robots.txt'
  ```

  Purging the Cloudflare cache is the only way to make it immediate, and that
  is an owner action in the Cloudflare dashboard.

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
- **The live SPA bundle's filename is not recorded here, on purpose.** This line named `index-geU9U1Fa.js` for two generations of bundle while the homepage requested something else, and nothing can catch that: a wrong filename in a markdown file throws nothing and builds green. Ask the homepage instead — ``grep -o 'index-[A-Za-z0-9_-]*\.js' dist/index.html | sort -u`` (measured 23 Nov 2026: `index-C6-wz3bb.js`, with `index-BGDnZsgf.css`). Every bundle so far shipped with `/page/index.html` or `/page/` style hrefs and was **patched post-build** to clean no-slash URLs (nav array, footer, blog cards, `` `/blog/${p.slug}` `` template). Re-apply on any new bundle, or fix the source components.
- `dist/assets/` holds exactly ONE js and ONE css. This line used to list `index-DgoEfwip.js`, `index-sSz8uoIy.js` and `index-CFJ81nDD.css` as kept copies that were "safe to delete after a few days" — all three are already gone, so it described cleanup that had been done. `ls dist/assets/` is the answer, not a list in this file.
- **CACHE-BUSTING: the bundle keeps its filename across patches, so the `?v=` query in `dist/index.html` is the only cache-busting there is.** Bump it whenever anything in `dist/assets/index-*` changes. `dist/assets/.htaccess` serves that directory `public, max-age=31536000, immutable` — a YEAR, and `immutable` means the browser will not revalidate even on an explicit reload. **Do not read that figure off `dist/.htaccess`**, which says `application/javascript "access plus 1 month"`; the per-directory file overrides it, and grepping only the root one is how this came to be written down as a month. Cloudflare caches it as well (measured 23 Nov 2026: `cf-cache-status: HIT`, `Age: 70722`). **There are FOUR `?v=` occurrences on three files and the bundle carries TWO of them** — the `modulepreload` href and the script `src` — so a half-bump leaves the preload fetching the old URL. Measured 23 Nov 2026: the js is `?v=20261108`, the two css files `?v=20260927`; they move independently, so bump only what changed. `grep -oE '(src|href)="[^"]*\?v=[0-9]+"' dist/index.html` lists them. This is ENFORCED, not just documented: `bash tools/verify-live.sh --deploy` refuses the deploy when `dist/assets/index-*` changed and the `?v=` did not. The real fix is content-hashed filenames from the build, which would retire this rule entirely.
- **NEVER RUN A BULK OR REGEX CLEANUP OVER `dist/assets/*.js`. It cost a blank homepage once (9 Sep 2026, during the sitewide ISO-9001 claim scrub): a tidy-up rule rewriting `..` to `.` also ate the `...` spread operators, and the page rendered nothing.** The hazard is still live and the ratio is the argument — measured in the current bundle on 23 Nov 2026, `...` appears **57** times while `..` that is *not* part of `...` appears **3** times, so that rule would have fixed three things and silently broken fifty-seven. And you cannot review the damage: the file is 18 lines with a longest line of 202,835 characters, so its diff is unreadable and nothing in the build or the test suite fails — the page just comes up empty. **The bundle gets exact, hand-checked string swaps only, and then an actual browser load**, not a build, not a grep. `node --check` is not enough either: it parses a file whose runtime `ReferenceError` still blanks the page on hydration.
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

## Uptime monitoring, and the DOWN alerts that were never outages

The `ALERT: 1 site(s) DOWN -- fabrioza.com (HTTP 000)` emails, followed ~5
minutes later by `Recovered`, are a **false alarm with a known cause**. Twice
on record the site was serving normally throughout. Read this before
investigating the site itself.

The monitor is **shared infrastructure, not in this repo**:
`/opt/scalaro/site-monitor.sh` on `209.145.55.76`, cron `*/5`, logging to
`/var/log/site-monitor.log`, state in `/var/lib/site-monitor/`, credentials
read from `/opt/fabrioza/.env` (Resend + Telegram). It watches 13 sites on
that box.

**fabrioza.com is the only one of the 13 that does not resolve to the box.**
Measured 23 Nov 2026:

```
fabrioza.com      -> 104.21.82.233, 172.67.208.221   (Cloudflare)
the other twelve  -> 209.145.55.76                   (the box itself)
```

So twelve of those probes never leave the machine, while fabrioza's goes out
to a Cloudflare edge and hairpins back in. That round trip is the only fragile
path in the whole monitor, and it is the only one that has ever failed:
**2 failures in 5,132 probes since 5 Nov 2026 — 0.039%** (11 Nov 16:10:41,
23 Nov 11:10:40), both fabrioza, each lasting exactly one pass.

**Neither was an outage, and the proof is in nginx's own log.** During the
23 Nov event nginx served a real visitor `/blog/tshirt-manufacturing-cost`
and its assets at **200 in the same seconds the probe was timing out**; the
container had `restarts=0` and 23h uptime, nginx had `restarts=0` and had
started hours earlier, and there were zero nginx errors in the window. The ten
probes that ran after fabrioza in that pass all reached the origin and
returned 200 — fabrioza's two attempts produced **no origin log line at all**,
so they died between this box and Cloudflare.

**HTTP 000 is not a server error.** curl reports `000` when it got no HTTP
response whatsoever — connection refused, DNS failure, TLS handshake failure
or timeout. It is never a 5xx from the app, so "HTTP 000" in an alert is a
reason to check the path in front of the site, not the site.

### Fixed 23 Nov 2026 — and do NOT simplify it

The probe now has three outcomes instead of two. When both public attempts
fail it asks the origin directly, with
`curl --resolve <host>:443:209.145.55.76`, which bypasses public DNS while
keeping SNI and Host set to the real name so the correct vhost answers and the
certificate is still validated:

| | verdict | behaviour |
|---|---|---|
| public probe OK | `ok` | up |
| public fails twice, origin answers | `edge` | **silent on the first pass**; alerts on the second (10 min) as `unreachable from OUTSIDE` |
| public fails, origin fails too | `down` | pages immediately, unchanged |

Requiring two consecutive passes kills both false alarms on record (each
lasted one pass) while a genuine Cloudflare/DNS outage still reaches you.
**Do not "simplify" this to suppressing the alert whenever the origin is
healthy** — that is silence during exactly the outage customers can see and
the owner cannot. All 13 sites were measured to serve a valid certificate on
a `--resolve` probe, so the fallback is not fabrioza-only and a site that
stops doing so reads as DOWN rather than silently passing.

The log now carries a verdict column, so a hairpin stall appears as
`000-origin200  edge` without sending anything. Tests:
`bash /opt/scalaro/site-monitor.test.sh /opt/scalaro/site-monitor.sh` — 18
assertions driving the real state machine with the single HTTP call stubbed,
against a scratch state dir and a non-existent env file, so it sends nothing.
Two of them are mutation checks (`EDGE_CONFIRM=1` must alert on pass 1; a dead
origin must never be downgraded to `edge`) without which "no alert on pass 1"
would also pass against a script that never alerts at all. Rollback copy:
`/opt/scalaro/site-monitor.sh.bak-20261123-143354`.

### Two measurement traps hit while diagnosing this

- **Grepping the nginx access log for `fabrioza` finds nothing and proves
  nothing.** That string only appears in the **referer** field, so a request
  to `/` with no referer — which is exactly what the monitor sends — is
  invisible. Search by user agent (`curl/`) instead. Doing that is what
  produced the decisive evidence above; the first pass had been reading the
  wrong field and would have concluded the probe never ran.
- **`docker logs` is not the record of what happened.** The containers restart
  and take the history with them. The durable record is
  `/var/log/site-monitor.log` plus its logrotated `.gz` files, which is where
  the 5,132-probe count came from.

**Timestamps do not line up, and the clocks are both correct.** The server
runs `America/Chicago` while the webmail client displays 7 hours ahead, so log
`11:10:40` is the `18:10` email. Reconcile the timezone before treating two
entries as separate events.

**Still unexplained, deliberately:** both failures landed at minute `:10`, at
`:10:40` and `:10:41`. Nothing in cron runs then. With two data points that is
not a pattern, and it is recorded here as an open question rather than a
cause.
