# Putting fabrioza.com behind Cloudflare

Written 10 Nov 2026. Everything below was measured against the live site on
that date, not assumed.

## Why

| measured from | time to first byte |
|---|---|
| inside the `fabrioza-web` container | **5 ms** |
| the VPS host, through nginx + TLS | **79 ms** |
| a remote client | **~1500 ms** |

The server is not slow. Apache answers in 5 ms. The 1.5 s is distance to the
German VPS, and roughly a third of it is the TLS handshake alone (`conn`
~0.5 s, `tls` ~1.0-1.3 s before the request is even sent).

A CDN fixes this because the handshake terminates at an edge near the
visitor instead of in Germany. **This is worth most for exactly the customers
we are chasing** - UK and US - who are furthest from the origin.

Note what a CDN does *not* fix: the origin fetch still happens. Expect a large
improvement on repeat and static requests and a smaller one on a cold HTML
request. Measure after, do not assume.

## Before you touch anything

The origin is already ready, verified 10 Nov 2026:

- Let's Encrypt cert covers `fabrioza.com` **and** `www.fabrioza.com`,
  valid to **30 Jan 2027** - so SSL mode **Full (strict)** will work.
- `http://` already 301s to `https://`, and `www` 301s to the apex.
- nginx sends `X-Forwarded-Proto https` **hard-coded**, so there is no
  redirect-loop risk even if the CDN connects over http.
- `crm_ip_hash()` in `dist/api/db.php` reads `CF-Connecting-IP` first and
  otherwise takes the **first** entry of `X-Forwarded-For`. That matters:
  nginx uses `$proxy_add_x_forwarded_for`, which APPENDS, so behind a CDN the
  header is `<client>, <cdn-edge>`. Hashing the whole string would give one
  visitor a different hash on every request and break both the 5-per-hour
  form rate limit and the stored lead fingerprint.

## DNS records - recreate ALL of these

Moving nameservers means every record must exist at the new provider.
Cloudflare's scan usually finds them, but **check each one against this table
before changing nameservers.** A missing MX record means the business stops
receiving email and nothing errors.

| type | name | value | proxy |
|---|---|---|---|
| A | `fabrioza.com` | `209.145.55.76` | **Proxied** |
| CNAME | `www` | `fabrioza.com` | **Proxied** |
| MX | `fabrioza.com` | `mail.fabrioza.com` (priority 0) | n/a |
| A | `mail` | `95.216.243.216` | **DNS only** |
| A | `webmail` | `95.216.243.216` | **DNS only** |
| A | `ftp` | `95.216.243.216` | **DNS only** |
| TXT | `fabrioza.com` | `v=spf1 ip4:95.216.243.216 a mx ip4:95.216.22.216 ~all` | n/a |
| TXT | `fabrioza.com` | `google-site-verification=bwGWtmoTxxnDiEcziVbRJ2DXWmLM5PSkPcEB_GL2_5E` | n/a |
| TXT | `_dmarc` | `v=DMARC1; p=none; rua=mailto:postmaster@fabrioza.com; adkim=s; aspf=s` | n/a |

**THE MAIL SERVER IS A DIFFERENT MACHINE FROM THE WEBSITE.** The site is
`209.145.55.76`; mail, webmail and ftp are `95.216.243.216`. Proxying any of
those three through the CDN breaks email - a CDN proxies HTTP, not SMTP/IMAP.
This is the single most common way a business destroys its own mail during a
Cloudflare move. They must stay **grey cloud / DNS only**.

### One SPF subtlety

The SPF record contains the `a` mechanism, which authorises *whatever the A
record of fabrioza.com resolves to*. Once the apex is proxied, `a` resolves to
Cloudflare's IPs rather than our server - so that mechanism stops meaning what
it was written to mean.

It is not an outage: the real sending host `95.216.243.216` is listed
explicitly by `ip4:`, so mail from the mail server still passes. But `a`
becomes both useless and sloppy. Replace it with the origin's own address if
anything ever sends mail from the web server:

```
v=spf1 ip4:95.216.243.216 ip4:95.216.22.216 ip4:209.145.55.76 mx ~all
```

Change this **only** after confirming what actually sends mail for the domain.
Editing SPF carelessly causes silent spam-foldering, which is worse than slow.

## Cloudflare settings

| setting | value | why |
|---|---|---|
| SSL/TLS mode | **Full (strict)** | The origin has a valid public cert. Flexible would be an unencrypted origin hop and can cause redirect loops. |
| Always Use HTTPS | On | The origin already does it; doing it at the edge saves a round trip to Germany. |
| Brotli | On | Better than our gzip, and it happens at the edge. |
| Rocket Loader | **OFF** | It defers and reorders scripts. This site's homepage is a React bundle plus hand-injected scripts that re-query the DOM; Rocket Loader is a known way to break exactly that. |
| Auto Minify | **OFF** | Deprecated by Cloudflare, and our assets are already built. |
| Email Obfuscation | **OFF** | It rewrites `mailto:` links into script, which would damage the contact links the quote CTAs rely on. |
| Browser Cache TTL | **Respect Existing Headers** | We deliberately set `stale-while-revalidate` on HTML and `immutable` on hashed assets. Do not let the edge override them. |

### Caching

Leave the default cache behaviour at first. Cloudflare caches static assets by
extension and **does not cache HTML by default**, which is what we want:
deploys stay visible immediately, and the HTML win comes from the shorter TLS
path rather than from edge caching.

Add these rules so the dynamic parts are never cached:

- **Bypass cache** for `/api/*`
- **Bypass cache** for `/admin*`
- **Bypass cache** for `/indexnow.php`

Only after measuring should you consider caching HTML at the edge. If you do,
it must be a short edge TTL with revalidation, and you must re-test that a
deploy appears - that is the property the current header setup exists to
protect.

## Order of operations

1. Add the zone in Cloudflare, let it scan, then **check every record against
   the table above**, especially MX and the three `95.216.243.216` records.
2. Set SSL/TLS to **Full (strict)** BEFORE the nameservers move. If it is left
   on Flexible, the first proxied request can loop.
3. Apply the settings and cache rules above.
4. Change the nameservers at the registrar (currently `ns1.veladns.com` /
   `ns2.veladns.com`). Propagation is usually minutes to a few hours.
5. Verify (below).

## Verify after the switch

```bash
bash tools/verify-live.sh          # the whole suite must stay green
```

Then specifically:

```bash
# the edge is actually serving
curl -sSI https://fabrioza.com/ | grep -iE '^(cf-cache-status|server)'

# mail still resolves to the MAIL server, NOT to Cloudflare
nslookup -type=MX fabrioza.com 8.8.8.8
nslookup mail.fabrioza.com 8.8.8.8      # must be 95.216.243.216

# send a real test to an outside address and confirm it arrives,
# then submit the live contact form and confirm the lead reaches the CRM
```

**Measure the thing this was for**, from a machine far from Germany:

```bash
curl -sS --compressed -o /dev/null \
  -w 'conn %{time_connect}s tls %{time_appconnect}s ttfb %{time_starttransfer}s\n' \
  https://fabrioza.com/what-we-make
```

Baseline before the move, 10 Nov 2026: `conn 0.51s tls 1.16s ttfb 1.88s`.

## The delayed failure to guard against

**The Let's Encrypt certificate renews around 30 Dec 2026** (60 days before it
expires on 30 Jan 2027). Renewal uses an HTTP-01 challenge served from the
origin. Behind the proxy that still works, but it is exactly the kind of thing
that fails quietly two months later - and with **Full (strict)**, an expired
origin certificate takes the entire site down.

Prove it still works the same day you switch, rather than finding out in
January:

```bash
ssh root@209.145.55.76 "certbot renew --dry-run"
```

If that fails behind the proxy, either set the apex to DNS-only for the few
minutes a renewal takes, or move that certificate to a DNS-01 challenge.

## Rollback

Point the nameservers back to `ns1.veladns.com` / `ns2.veladns.com` at the
registrar. Nothing on the server changes during this work, so the origin keeps
serving throughout - which is why no code or nginx change is part of it.

## Optional hardening, AFTER it is proven working

Once traffic is flowing through the edge, the origin IP `209.145.55.76` is
still publicly reachable, so anyone can bypass the CDN by connecting to it
directly - including to spoof `CF-Connecting-IP`. Restricting port 443 on the
VPS to Cloudflare's published ranges closes that.

**Do not do this during the switch.** The shared `scalaro-nginx-1` serves
several other projects from that machine, so a firewall change there affects
more than this site, and doing it before DNS has fully propagated takes the
site offline.
