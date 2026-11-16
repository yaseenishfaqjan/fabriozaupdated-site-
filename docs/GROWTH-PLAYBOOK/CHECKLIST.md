# The checklist — run this on any site

Each item has **the command that proves it**. Tick nothing you have not run.
`SITE` below is the bare domain, e.g. `fabrioza.com`.

---

## 1. Reachable

- [ ] **Every sitemap URL returns 200.** Not "the homepage works".

  ```bash
  curl -s https://SITE/sitemap.xml | grep -o '<loc>[^<]*</loc>' | sed 's|</*loc>||g' \
    | while read u; do echo "$(curl -s -o /dev/null -w '%{http_code}' --max-time 25 "$u") $u"; done \
    | grep -v '^200' || echo "all 200"
  ```

- [ ] **One URL form, everything else 301s to it.** Pick slash or no-slash and
      be consistent. Test all five forms, not one:

  ```bash
  for u in https://SITE/faq https://SITE/faq/ https://SITE/faq/index.html \
           http://SITE/faq https://www.SITE/faq; do
    echo "$(curl -s -o /dev/null -w '%{http_code} -> %{redirect_url}' --max-time 25 "$u")  $u"
  done
  ```

- [ ] **robots.txt has 0 errors** in Bing's robots.txt Tester, and `Sitemap:`
      is declared **above** any non-standard line. Bing flags `LLMs:` and
      `AI-Content-Preference:` as errors; real parsers ignore what they don't
      know, but they must not sit above the one line that has to be read.

- [ ] **Every page has exactly one self-referencing canonical.**

  ```bash
  # strip comments first, or a canonical inside one counts
  python -I -c "
import glob,io,re
for f in glob.glob('dist/**/*.html',recursive=True):
    c=re.sub(r'<!--.*?-->','',io.open(f,encoding='utf-8',errors='replace').read(),flags=re.S)
    n=len(re.findall(r'rel=\"canonical\"',c))
    if n!=1: print(n,f)"
  ```

## 2. Measurable

- [ ] **Analytics on EVERY page, counted — not assumed.** Fabrioza had GA4 on
      160 of 163 and nobody knew; one of the three was a product page.

  ```bash
  python -I -c "
import glob,io,re
t=g=c=0
for f in glob.glob('dist/**/*.html',recursive=True):
    live=re.sub(r'<!--.*?-->','',io.open(f,encoding='utf-8',errors='replace').read(),flags=re.S)
    t+=1; g+='G-XXXXXXX' in live
    c+=('clarity.ms/tag/' in live and '\"CLARITYID\"' in live)
print('pages',t,'ga',g,'clarity',c)"
  ```

  **Strip comments before counting, and match a runtime-built URL as two
  tokens.** Both mistakes happened here, in opposite directions.

- [ ] **Confirm the tag FIRES, in a browser.** Source presence is not proof:

  ```js
  typeof window.clarity;  typeof window.gtag;
  performance.getEntriesByType('resource').map(e=>e.name)
    .filter(u=>/clarity\.ms|googletagmanager|google-analytics/.test(u))
  ```

  You want to see `o.clarity.ms/collect` and `google-analytics.com/g/collect`.

- [ ] **Search Console + Bing Webmaster verified.** In Bing the "Not verified"
      label is unreliable — open any tool; *"User is unauthorized to access the
      site"* is the real answer. The Bing token is **one per account**, reused
      across every site.

- [ ] **IndexNow key live**, and pinged only **after** curling the change live.
      Firing it mid-deploy gets the OLD page crawled and recorded.

- [ ] **Sitemap submitted** in both consoles, and re-submitted after big
      changes. Check "last crawled" — fabrioza and nodevant were both months
      stale without any error showing. fabrioza's showed **136 URLs** against a
      live file of 174 until it was resubmitted; nothing was flagged as wrong.

- [ ] **Only ONE hostname's sitemap is registered.** fabrioza had both
      `https://fabrioza.com/sitemap.xml` and `https://www.fabrioza.com/sitemap.xml`
      registered in Bing — the same file reached through a hostname that 301s to
      the apex. It reported 0 errors and 0 warnings, so nothing ever complained,
      while quietly encouraging the engine to keep rediscovering `www` variants
      of every URL. Those variants are the "Page with redirect" bucket that
      makes a not-indexed count look alarming. Register the canonical hostname
      only; delete the rest (Sitemaps → tick the row → Delete; the row's own
      ⋮ menu offers Re-submit and nothing else, which is easy to read as "it
      cannot be removed").

## 3. Understandable

- [ ] Title ≤ **60** chars, description ≤ **160**, measured **unescaped**
      (`&amp;` is 5 chars in the file, 1 on screen).
- [ ] **Exactly one `<h1>` per page**, and it is the heading the visitor sees.
      Count with comments stripped — a `<h1>` inside a comment fools the check.
- [ ] No duplicate titles or descriptions across the site.
- [ ] Schema for what the page *is*: `Product`, `Service`, `FAQPage`,
      `BreadcrumbList`, `Organization`. **FAQPage is the AEO workhorse** — it
      is what wins the expandable answer in the result.
- [ ] `llms.txt` + `llms-full.txt` for GEO: a plain-text summary of what the
      business does, who it serves, and the real constraints (MOQ, lead time,
      shipping). This is what an AI assistant quotes.

- [ ] **A generator with a hardcoded list is a hand-maintained list wearing a
      costume.** nodevant's `gen-llms-full.mjs` looked automated and held three
      blog slugs in an array while seven posts were published — four full
      articles missing from `llms-full.txt`, silently. Derive the list from the
      data file and throw if it parses nothing. After the fix: 28,864 → 57,577
      bytes, 7 of 7 article bodies.

- [ ] **Re-generate the AI files after ANY page is added.** They go stale
      silently and nothing reports it. On fabrioza, 30 new pages covering the
      entire winter range across six markets were invisible to `llms.txt` and
      `llms-full.txt` — on the channel the CRM says is the **top attributable
      lead source** (15 of 16 tagged leads). Regenerating took one command and
      added 56 URLs. Check with `grep <new-slug> llms*.txt`, not by assuming.

- [ ] **State what you have NOT certified, in words an assistant can quote.**
      If a product sits near a regulated category — PPE, medical, food contact,
      children's safety — say explicitly what the listed price does and does
      not include. An assistant repeating a certification you never made is
      worse than not being cited at all.
- [ ] Images: real `alt` on anything meaningful. **`alt=""` on a decorative
      `aria-hidden` background is CORRECT** — Bing reports it as "missing";
      ignore that, adding text there makes screen readers worse.

## 4. Deserves to rank

- [ ] **One page per thing you actually sell.** Not a mention inside another
      page — its own URL, title, h1 and schema. A product that lives only as a
      sentence on another page cannot rank for itself.
- [ ] **One page per market you actually want.** `/<product>-manufacturer-<country>`.
- [ ] The intersection of the two is where the money is, and it is the easiest
      thing to get wrong: fabrioza has 5 UK pages and 0 winter-outerwear pages
      while asking for UK winter orders.

## 5. Vouched for  ← usually the real bottleneck

- [ ] Count referring **domains**, not links. Fabrioza: **2**.
- [ ] This is the one item no code change fixes. Directories, suppliers,
      trade bodies, customer case studies, guest posts, supplier listings on
      customers' own sites.

- [ ] **Compare the ROUTES on disk against the sitemap, not the sitemap
      against itself.** A hand-maintained list of paths goes stale the moment
      someone adds a route, and nothing reports it. nodevant's `staticPages`
      held 14 entries while the app had 19 static routes: `/pricing/`,
      `/reverse-audit/` and `/build/` were all returning 200 with
      `index, follow`, all linked from the footer, and none of them were in the
      sitemap. `/pricing/` is a money page.

      Two of the five absentees were absent correctly (`/admin/` and
      `/audit/results/`, both noindex and `Disallow`d) — so the check is
      "every indexable route is listed", not "every route is listed". Confirm
      which is which by reading the page's robots meta, never by the name.

- [ ] **No orphans.** A page in the sitemap that nothing links to gets weak
      crawl priority and receives no internal authority. Count inbound internal
      links per page, not just outbound ones.

- [ ] **The conversion tool is reachable from the pages that create intent,
      and lands on the right thing.** fabrioza's configurator produced 0 of 224
      leads while linked from no nav and no footer; after that was fixed it was
      still reached only through the nav, so a buyer reading about jackets
      landed on a t-shirt. A `?product=<slug>` deep link per page fixes the
      second half. Make an unknown slug fall back silently, and set the
      `<select>` as well as the state — otherwise the page renders one garment
      while the control says another.

## 6. Converts

- [ ] The enquiry form works, and a test submission actually arrives.
- [ ] Analytics records the enquiry as an **event**, or you cannot tell which
      page earns money.
- [ ] Someone replies. Fabrioza had 128 untouched leads and 180 overdue
      follow-ups — no amount of traffic survives that.

---

## Things that cost us time, kept so they only cost once

1. **A 200 can mean "file missing".** If the server falls back to an app shell,
   every missing path returns 200 with HTML. Test an invented filename as a
   control and compare the body and Content-Type.
2. **Grep proves nothing on a built bundle.** Component names are minified;
   runtime-built URLs never appear as literals; orphaned chunks from old builds
   still match. Resolve the file the page actually loads, or search a
   user-visible string.
3. **`docker restart` does not re-read an env file.** Use `compose up -d`.
4. **Static files sit behind a CDN cache.** Cloudflare served fabrioza's old
   `robots.txt` for 4 hours after a correct deploy. Cache-bust and check the
   container too before believing a deploy failed.
5. **Line endings are per file.** Check the file you are about to edit; writing
   the wrong one turns a 1-line change into a whole-file diff.
6. **Never report a deploy from an exit code.** Curl the change.
