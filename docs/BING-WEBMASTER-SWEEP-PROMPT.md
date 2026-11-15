# Bing Webmaster Tools sweep — paste-ready prompt

Written 15 Nov 2026, after running the full sweep on fabrioza.com. Everything
below is a measured fact from that run, not advice. Swap the site name and
repo path to reuse it on `globalshield360.io` or `nodevant.com`, which are in
the same Bing account.

---

Work through **every tool in Bing Webmaster Tools for otrace.co**, in the
sidebar order, from Home to Microsoft Clarity. Fix what is fixable in code,
and report what needs my decision instead of deciding it yourself.

## The site

- **otrace.co** — AW2026 streetwear store, live behind nginx, has `robots.txt`
  and `sitemap.xml` (both 200).
- Repo: `C:\Users\PCL\Documents\otrace`, remote `github.com/yaseenishfaqjan/otrace`.
- Deploy lives in `deploy/` (docker-compose + nginx on the shared VPS). Read
  it before shipping; do not change nginx.
- otrace.co **is already verified** in Bing Webmaster Tools. No site to add.

## The browser

**Claude in Chrome is NOT connected.** Do not spend calls on
`mcp__claude-in-chrome__*` — it returns "not connected". Use the **built-in
browser pane** (`mcp__Claude_Browser__*`). I am already signed in to Bing in
that pane and the session persists.

```
preview_start {url: "https://www.bing.com/webmasters/home?siteUrl=https%3A%2F%2Fotrace.co%2F"}
```

If it bounces to `/webmasters/about`, you are signed out — ask me to click
**Sign in** in the pane, then carry on.

**Resize to 1440x900 before anything else.** Below about 1000px the left nav
collapses and every `treeitem` vanishes from the accessibility tree. The app
also clears the size when a turn ends, so set it again each turn.

## The 17 tools and their real URLs

Append `?siteUrl=https://otrace.co/` to each. **Note the two that are not
named what the sidebar calls them.**

| # | Sidebar label | Path |
|---|---|---|
| 1 | Home | `/webmasters/home` |
| 2 | Search Performance | `/webmasters/searchperf` ← **not** `searchperformance` |
| 3 | AI Performance | `/webmasters/aiperformance` |
| 4 | URL Inspection | `/webmasters/urlinspection` |
| 5 | Site Explorer | `/webmasters/siteexplorer` |
| 6 | Sitemaps | `/webmasters/sitemaps` |
| 7 | IndexNow | `/webmasters/indexnow` |
| 8 | URL Submission | `/webmasters/submiturl` |
| 9 | Backlinks | `/webmasters/backlinks` |
| 10 | Keyword Research | `/webmasters/keywordresearch` |
| 11 | Recommendations | `/webmasters/seoreports` ← **not** `recommendations` |
| 12 | Site Scan | `/webmasters/sitescan` |
| 13 | Crawl Control | `/webmasters/crawlcontrol` |
| 14 | Block URLs | `/webmasters/searchconfig/blockurl` |
| 15 | robots.txt Tester | `/webmasters/robotstxttester` |
| 16 | Verify Bingbot | `/webmasters/verifybingbot` |
| 17 | Copyright Removal / User management / Clarity | `/webmasters/copyright`, `/webmasters/usermgmt`, `/webmasters/clarity` |

**URL Submission is not in the collapsed sidebar** — it only appears once the
app has loaded a site. Do not skip it.

## Reading an Angular SPA

- **Wait 7–9 seconds after `navigate` before reading.** A read at 0s returns
  an empty body and looks like a failure.
- Every report page carries a ~2,500-character accessible chart table that
  swamps `get_page_text`. `Microsoft Clarity` is the last sidebar item, so
  splitting on it trims the whole nav in one go:

  ```js
  document.body.innerText.split('Microsoft Clarity')[1].replace(/\n{2,}/g,'\n').slice(0,2000)
  ```

- For the data tables underneath:
  ```js
  const t = document.body.innerText; t.slice(t.lastIndexOf('Download all') + 12)
  ```
- Switching tabs (Keywords / Pages / Device / Country):
  ```js
  [...document.querySelectorAll('[role=tab]')].find(e => e.textContent.trim() === 'Pages').click()
  ```
  then wait 4s.
- `find` mostly returns no matches on this app. Use `read_page filter:"interactive"`
  or raw JS.
- `browser_batch` sometimes refuses the **first** navigate with
  "navigation to https://bing.com was denied". Call `navigate` on its own,
  then batch the rest.

## Traps that cost me time on fabrioza — do not repeat them

1. **Omit `?siteUrl=` and you get "No pages found"** on a site with plenty of
   data. It reads as "no data" and it is not.
2. **The Bing Index tab shows the LAST CRAWL, not the live page.** A page
   showed *"Indexing allowed? No — URL cannot appear on Bing"* while serving
   200 with `index, follow` and a self-canonical. Always click **Live URL**
   before believing it, then **Request indexing** to refresh the stale record
   (quota 100/day).
3. **Always inspect a known-good control page first.** The broken result above
   was only trustworthy because a page I knew was indexed came back
   *"Indexed successfully"* from the same tool, the same minute.
4. **A 200 proves the server is fine, not that Bing will index it.** Check
   `meta robots`, `X-Robots-Tag`, the canonical, and fetch once with a bingbot
   user-agent before concluding anything.
5. **Do not spend the Site Scan quota without asking.** It is 10 pages. Audit
   locally instead — faster, and it covers every page rather than ten.
6. **Do not click "Get started" on Microsoft Clarity.** That creates an account
   and installs session recording on a live store. It is a privacy decision for
   the owner, not a setup step.
7. **Do not change account settings** — Crawl Control, removing a sitemap,
   adding a user — without asking. Report them.
8. **robots.txt**: Bing's tester reports non-standard directives (`LLMs:`,
   `AI-Content-Preference:`) as errors. Every real parser ignores what it does
   not know, so they are harmless — but they must never sit **above** the
   `Sitemap:` line, which is the one line that has to be read.

## Audit locally instead of clicking

Faster than the tools and complete rather than sampled. Across every page:

- `<title>` over **60** characters, `meta description` over **160**, missing
  or duplicate `<h1>`.
- **Measure length unescaped.** `&amp;` is five characters in the file and one
  on screen; two ampersands make a title look eight characters too long and you
  will cut copy that was never over.
- **Transform the string the file holds. Never retype copy.** Retyping strips
  accents on non-English pages silently.
- **Never cut a description at an arbitrary word boundary.** Cut only at `.`
  `:` `;` `-` or a comma; if none fits, write the description. A raw word cut
  produced *"…ne correspond pas à votre."* — a French sentence broken
  mid-phrase that no word-boundary check can catch, because every word in it
  is complete.
- Check afterwards that **only `<head>` changed** — a whole-document
  find-and-replace can rewrite an `<h1>` that happens to match a title.

## Deploy discipline

- `git push`, then VPS pull, then rebuild — **three separate ssh commands**.
- **Verify by curling the live page, never by an exit code.**
- **Do not ping IndexNow until you have curled the change live.** On fabrioza
  IndexNow fired one minute after the commit, Bing crawled one minute after
  that while the container was still building, and recorded the page as
  non-indexable for 15 hours.
- Static files may be **Cloudflare-cached**. `robots.txt` came back with
  `cf-cache-status: HIT` and `max-age=14400`, so a correct deploy read stale
  for hours. Cache-bust with `?cb=$(date +%s)` and check the container itself
  before concluding a deploy failed.

## What to hand back

A report covering all 17 tools in sidebar order, splitting:

1. **Fixed and verified live** — with the measurement that proves it.
2. **Needs my decision** — with a recommendation, not a change.
3. **Measured numbers** — clicks, impressions, CTR, average position, indexed
   count, referring domains, AI citations.

For a store, pay particular attention to Search Performance → **Country** and
**Device**, and to AI Performance, which on fabrioza carried more citations
(2.7K) than the site had search impressions (2.3K).
