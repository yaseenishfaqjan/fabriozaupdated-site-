# Sites — one line each

Measured 15 Nov 2026. "Verified" means a Bing tool returned data, not that a
label said so.

| site | GSC | Bing | GA4 | Clarity | note |
|---|---|---|---|---|---|
| **fabrioza.com** | verified | verified | 163/163 | **163/163** | reference implementation — see `RECORD-fabrioza.md` |
| **otrace.co** | — | verified | — | — | sweep playbook exists, not yet run |
| **nodevant.com** | — | **verified 15 Nov** | yes | — | 20 titles / 26 descriptions / 1 h1 fixed; sitemap was 5 months stale |
| **peachpicks.app** | — | **verified 15 Nov** | — | — | essentially unindexed: 0 clicks, 1 impression. Sitemap + IndexNow set up from scratch |
| **globalshield360.io** | — | **blocked** | — | — | DNS points at the wrong host — see below |

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
