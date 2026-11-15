# Record — fabrioza.com

B2B custom clothing manufacturer. Sells production runs (MOQ from 20) to brands,
clubs and teams. **The money is a B2B enquiry, not a pageview.** Everything
below is measured; the date is when it was measured.

---

## Accounts and IDs

| | value |
|---|---|
| GA4 property | `G-714NBVRE2V` |
| Microsoft Clarity project | `ze5wn6at0r` |
| IndexNow key | `6e0726a1783a50994f70e2ffd2987f22` (at `/<key>.txt`) |
| Google Search Console | verified, `https://fabrioza.com/` |
| Bing Webmaster Tools | verified |
| Meta Pixel | **placeholder only** — `FB_PIXEL_ID_PLACEHOLDER`, never live |

## Where it stands (15 Nov 2026)

| source | number |
|---|---|
| GA4, last 90 days | 3.0K active users · 4.4K views · 3.4K sessions · 14K events (+241% on prior period) |
| Bing, last ~90 days | **30 clicks** · 2.4K impressions |
| Google Search Console | **135 indexed** · 224 not indexed |
| Referring domains | **2** |
| Pages on the site | 175 (174 in the sitemap, all returning 200) |

Bing's own top recommendation: *"Your site does not have enough inbound links
from high quality domains."* That is the honest summary of the whole account.

## Done — 15 Nov 2026

- **Titles and descriptions**: 0 over 60 / 0 over 160 across all pages.
- **Homepage h1**: was missing entirely; now server-rendered.
- **robots.txt**: `Sitemap:` moved above the non-standard `LLMs:` lines, which
  Bing's tester reported as 3 errors. Now 0 errors.
- **Sportswear catalog**: "21 Categories" corrected to 17 (only 17 render), and
  a duplicate `TS-01` SKU fixed — Sublimation was coded by garment while every
  other card codes by category, which collided with Premium Tracksuit.
- **Microsoft Clarity installed on all 163 pages.** It had never run: the
  snippet existed on the homepage only, **commented out**, wrapped around a
  `FABRIOZA_CLARITY_ID` placeholder. Verified live in a browser —
  `typeof window.clarity === "function"` and `o.clarity.ms/collect` firing.
- **GA4 added to the 3 pages that lacked it**: `/custom-equestrian-apparel`,
  `/faq`, `/process`. Now 163/163. The equestrian page is one the site sells
  from, so every visit to it had been invisible since launch.

## The 224 "not indexed" — mostly not a problem

The reaction to this number is usually panic. Measured, **about 188 of the 224
are correct behaviour:**

| reason | pages | verdict |
|---|---|---|
| Page with redirect | 98 | **fine** — a redirect must not be indexed |
| Alternative page with proper canonical | 90 | **fine** — duplicate correctly resolved |
| Duplicate, Google chose different canonical | 23 | **look at these** |
| Crawled – currently not indexed | 6 | quality//priority signal |
| Not found (404) | 3 | **fix** |
| Discovered – currently not indexed | 2 | crawl budget |
| Redirect error | 1 | **fix** |
| Blocked by robots.txt | 1 | check it is deliberate |

Why the first two buckets are so large, verified by request:

```
https://fabrioza.com/faq             200
https://fabrioza.com/faq/            301 -> /faq
https://fabrioza.com/faq/index.html  301 -> /faq
http://fabrioza.com/faq              301 -> /faq
https://www.fabrioza.com/faq         301 -> /faq
```

Google has discovered four variant forms of many of the 162 real URLs. Each one
correctly collapses onto the clean no-slash URL — and each one is then counted
as "not indexed". **That is the system working.** Only 1 of the 163 pages
canonicalises to a different URL, and it is the 404 page.

So the genuine list is ~36 URLs, and **all 162 sitemap URLs return 200**, which
means the 404s and the redirect error are old URLs discovered elsewhere, not
live pages. Their exact addresses only exist inside Search Console — that part
is still open.

## Open — needs a decision or an account

- **Search Console sign-in** in the browser pane, to pull the URL lists for the
  ~36 genuinely broken ones and run Validate Fix.
- **No winter-outerwear pages at all.** See the gap below.
- **Referring domains: 2.** No code change fixes this.
- **Meta Pixel** has no real ID.
- **128 untouched leads, 180 overdue follow-ups** (recorded separately, kept out
  of this repo because it holds customer names).
- Mobile: **0 clicks on 1.1K impressions** in Bing — unexplained.
- Cloudflare Email Obfuscation still ON.

## The gap that matters most for the stated goal

The goal is **UK clients for winter hoodies, trousers, jackets and winter
workwear**. Measured across all 163 pages:

| category | dedicated pages |
|---|---|
| jacket / outerwear / coat / puffer / parka / gilet / bodywarmer | **0** |
| fleece | **0** |
| winter (anything) | **0** |
| workwear | **0** |
| trousers / joggers / sweatpants | **0** |
| varsity | **0** |

They are *mentioned* — "jacket" appears on 27 pages, "fleece" on 32 — but a
product that exists only as a sentence inside another page cannot rank for
itself.

Meanwhile the UK pages that DO exist are: `clothing-manufacturer-uk`,
`hoodie-manufacturer-uk`, `streetwear-manufacturer-uk`,
`t-shirt-manufacturer-uk`, `tracksuit-manufacturer-uk`.

The pattern is already proven on this site and already ranks. The missing
intersection is the whole winter line crossed with the one market being asked
for — e.g. `jacket-manufacturer-uk`, `fleece-manufacturer-uk`,
`workwear-manufacturer-uk`, `custom-puffer-jackets`, `custom-varsity-jackets`.

**This is the single highest-value SEO work available on this site**, and it is
content, not configuration.

### Started — 16 Nov 2026

| page | products behind it |
|---|---|
| `/jacket-manufacturer-uk` | Bomber / Varsity Jacket, Windbreaker Jacket |
| `/winter-outerwear-manufacturer-uk` | + Premium Hoodie, Crewneck Sweatshirt, Tracksuit Set |

Both built with `tools/build-country-product-page.py`, so they inherit the
schema, hreflang, duty wording and live price table the five existing UK pages
have. Sitemap 162 → 164; both linked from `/clothing-manufacturer-uk`.

**Only two pages, deliberately.** Every product must map onto rows that really
exist in `/pricing` — the generator asserts it. The only priced winter items
are those two jackets plus the hoodie, crewneck and tracksuit already in use.
So there is still **no honest `fleece-`, `workwear-` or `joggers-manufacturer-uk`**:
no priced fleece jacket, no workwear line, and joggers exist only inside the
tracksuit set. Those need a product and a price before they need a page —
publishing them now would advertise something nobody can quote.

**All six markets are now built — 12 pages, same day.** `jacket-` and
`winter-outerwear-manufacturer-` exist for uk, usa, canada, australia,
germany and uae. Sitemap 162 → 174, each linked from its own country hub,
all 12 returning 200, all 15 changed URLs accepted by IndexNow.

**The duty wording is the reason this was not a copy-paste job, and it came
out right.** Extracted from the "What import actually costs you" section of
each jacket page:

| market | what the page actually says |
|---|---|
| uk | 0% under DCTS (92% of lines) + 20% import VAT |
| usa | 16.5% MFN + 10% Section 301 = **26.5%** |
| canada | 18% MFN + 5% GST |
| australia | 10% GST + 5% |
| germany | 0% + 12% + 19% VAT |
| uae | 5% |

Six markets, six different answers, and **no "0% duty" on the US page** —
verified against the live HTML, which contains `26.5%` and does not contain
`DCTS`. That is the exact error the generator's docstring was written to
prevent, because it is a number a buyer budgets against and then pays.

Audit of all 12: titles 43–60 (limit 60), descriptions 118–160 (limit 160),
exactly one h1 each, self-canonical, Clarity and GA4 present, nav CTA
attributing to the page itself.

### Four generator bugs found while doing it

Found by rebuilding all 24 existing pages and diffing, which is worth doing
before trusting any generator you did not just write:

1. **Meta lengths had diverged.** Regenerating any page silently undid the
   earlier meta work — 63-char title, 166-char description, against limits of
   60 and 160, with nothing erroring. It now imports the rule from
   `fix-meta-lengths.py` instead of restating it.
2. **Lead attribution was wrong.** The nav CTA carries its source page in a
   *relative* param (`from=/clothing-manufacturer-uk`); the generator only
   rewrote absolute URLs, so every generated page would have credited its own
   quote requests to the landing page.
3. **Importing `fix-meta-lengths.py` ran it.** No `__main__` guard, and it
   decides whether to write from `--apply` in `sys.argv` — so
   `... --write --apply` would have rewritten every page's meta as a side
   effect of building one.
4. `default_fabric()` collapsed a paragraph onto one line, making every
   rebuild emit whitespace-only noise.
