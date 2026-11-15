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
      stale without any error showing.

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
