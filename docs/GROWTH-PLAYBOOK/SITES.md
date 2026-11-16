# Sites — one line each

Measured 15 Nov 2026. "Verified" means a Bing tool returned data, not that a
label said so.

| site | GSC | Bing | GA4 | Clarity | note |
|---|---|---|---|---|---|
| **fabrioza.com** | verified | verified | 163/163 | **163/163** | reference implementation — see `RECORD-fabrioza.md` |
| **otrace.co** | — | verified | — | — | sweep playbook exists, not yet run |
| **nodevant.com** | — | verified | 45/45 | **none** | see below |
| **peachpicks.app** | — | **verified 15 Nov** | — | — | essentially unindexed: 0 clicks, 1 impression. Sitemap + IndexNow set up from scratch |
| **globalshield360.io** | — | **blocked** | — | — | DNS points at the wrong host — see below |

## nodevant.com — clean on-page, two structural gaps

Audited 16 Nov 2026: **0 defects across all 42 live pages** — titles 35–60,
descriptions 97–160, exactly one h1, canonical, og:image and 4–12 JSON-LD
blocks each, no duplicates. The meta work from 15 Nov holds.

What was wrong was structural, and neither would show in an on-page audit:

- **Three indexable pages were missing from the sitemap** — `/pricing/`,
  `/reverse-audit/`, `/build/`. All 200, all `index, follow`, all linked from
  the footer. Sitemap 42 → 45.
- **The AI files knew half the site.** `llms.txt` was missing 25 of 42 pages
  (the whole `/ai-employees/` section, all six case studies, every service and
  solution detail page). `llms-full.txt` carried 3 of 7 blog articles because
  its "generator" hardcoded the slugs. Now 0 missing of 45, 7 of 7 articles,
  28.9 KB → 57.6 KB.
- `/ai-automation-agency/` was an **orphan** at priority 0.95 — now in the
  footer.
- **IndexNow set up from scratch** (key `a2a4eb32864cfd3578ba1379ef40a373`);
  45 URLs accepted, HTTP 202.

**Microsoft Clarity is on 0 of 45 pages.** GA4 is on all 45. Clarity needs its
own project per site — fabrioza's `ze5wn6at0r` must not be reused, or two
businesses' session recordings land in one dashboard. Owner decision.

## globalshield360.io — not an SEO problem

The domain serves an **open directory listing** from a LiteSpeed/PHP 7.2.34
shared host at `95.216.243.216`, exposing `cgi-bin/`, `php.ini` and an
unexplained `sbl.php`. The real app is built, running and certificated on the
Contabo VPS and serves correctly when the Host header points there.

Fix is one A record at VelaDNS (`ns1/ns2.veladns.com`) for the apex and `www`
→ **209.145.55.76**. The certificate expires **1 Dec 2026** and cannot renew
while DNS points elsewhere, because the ACME challenge can't reach the VPS.

Bing verification for it can be done by CNAME regardless of the A record:
`00dd2bc98a4ec1c08cc934fc8a36fbff` → `verify.bing.com.`

## Account-level facts

- **One Bing verification token for the whole account**:
  `B84578EA798497426806EE05427AAD83`. Reuse it on any new site; no new token is
  issued per site.
- Bing Webmaster account: `fabriozadotcom@gmail.com`.
- All sites except fabrioza sit on the Contabo VPS `209.145.55.76` behind the
  shared `scalaro-nginx-1` container. fabrioza has its own `fabrioza-web`
  container at `/opt/fabrioza`.
