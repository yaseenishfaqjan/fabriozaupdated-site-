# Marketing email: subdomain vs new domain

Written 23 Oct 2026, after Resend went live for the website's own mail.

## What is already working — do not disturb any of it

| What | Where | Status |
|---|---|---|
| **Receiving** every enquiry at `@fabrioza.com` | cPanel, MX → `mail.fabrioza.com` | working |
| **Website mail** (lead alerts, auto-replies) | Resend, over HTTPS | **live** — verified by a real send on 23 Oct |
| Resend bounce handling | `send.fabrioza.com` MX → `feedback.forge.rmta.net` | in use, **do not reuse this subdomain** |
| cPanel DKIM | `default._domainkey` | present |
| Resend DKIM | `resend._domainkey` | present |
| SPF | `v=spf1 a mx ip4:95.216.22.216 ~all` | **one record only** |

Check it at any time: `bash tools/resend_check.sh`

---

## The decision: subdomain or a new domain

Both do the job. They differ on three things that actually matter.

| | `news.fabrioza.com` | A new domain |
|---|---|---|
| Cost | £0 | ~£10/yr, forever |
| Sending reputation at launch | Inherits fabrioza.com's standing | **Starts at zero** — weeks of warm-up, early sends land in spam |
| Does a spam complaint hurt quotes? | No — burns the subdomain only | No |
| Looks legitimate to a buyer | `news@fabrioza.com` — obviously you | A name they have never seen |
| Someone replies | Goes where you point it | Goes where you point it |
| Admin | One domain, one renewal | Two of everything, forever |

**Recommendation: `news.fabrioza.com`.** It gives you the same isolation a
separate domain does — if a campaign draws complaints it burns the subdomain,
not the address your quotes go out on — without the cold-start penalty or the
second renewal.

**A new domain is the right call only if** the marketing is a distinct brand
with its own name, not Fabrioza campaigns. Then the domain IS the brand and
the cold start is worth paying.

**Already taken: `send.fabrioza.com`** (Resend bounces) and
`mail.fabrioza.com` (your inbox host). Free and checked: `news`, `go`,
`email`, `marketing`, `hello`, `updates`.

---

## Setup A — `news.fabrioza.com` with Google Workspace

This is the one that gives you what you asked for: Workspace for marketing,
cPanel keeps every enquiry, neither touches the other.

### 1. Add it to Workspace as a secondary domain

Google Admin → **Domains** → **Manage domains** → **Add a domain** →
`news.fabrioza.com` → choose **Secondary domain** (not an alias — an alias
mirrors existing users; a secondary domain gets its own).

Verify with the TXT record Google gives you, added in cPanel → Zone Editor.

### 2. Create the sending user

Google Admin → **Users** → add e.g. `news@news.fabrioza.com`.

### 3. DNS in cPanel → Zone Editor

**Add these. Change nothing else.**

| Type | Name | Value | Why |
|---|---|---|---|
| MX | `news.fabrioza.com` | Google's MX hosts, priority as given | Replies to campaigns land in Workspace |
| TXT | `news.fabrioza.com` | `v=spf1 include:_spf.google.com ~all` | SPF **for the subdomain only** |
| TXT | `google._domainkey.news.fabrioza.com` | from Admin → Gmail → Authenticate email | DKIM |

**The subdomain gets its own SPF record.** Do not add
`include:_spf.google.com` to the root `fabrioza.com` SPF — the root is for
cPanel and Resend, and every include you bolt on makes it more fragile.

### 4. The three records that must survive untouched

If any of these change, enquiries stop arriving:

- root `MX` → `mail.fabrioza.com`
- root `TXT` → `v=spf1 a mx ip4:95.216.22.216 ~all`
- `default._domainkey` (cPanel's DKIM)

Run `bash tools/resend_check.sh` after editing the zone. It fails loudly if
any of them went missing.

### 5. DMARC — the trap

The root publishes `adkim=s; aspf=s` (**strict**) and it applies to
subdomains unless told otherwise. Mail from `news@news.fabrioza.com` signed
`d=news.fabrioza.com` aligns strictly, so it passes. **That only holds if the
From address is on the subdomain too.**

If you send `From: info@fabrioza.com` through Workspace on the subdomain,
DKIM signs `news.fabrioza.com`, the From says `fabrioza.com`, strict
alignment fails and — because the policy is `p=none` — nothing bounces. The
mail just quietly lands in spam with no error anywhere.

**So: campaigns go out as `news@news.fabrioza.com`.** Set a reply-to of
`info@fabrioza.com` if you want replies in cPanel.

---

## Setup B — a new domain

Same shape. Point its MX at Google, give it its own SPF and DKIM, and leave
fabrioza.com completely alone. Its DMARC is its own, so the strict-alignment
trap above does not apply — but you also inherit none of fabrioza.com's
standing, and you are warming a cold domain.

If you go this way: buy it, then **do not send a campaign from it for two
weeks.** Send a handful of real messages a day first. Domains that start with
a bulk send get filtered, and a reputation lost early is slow to rebuild.

---

## One thing worth considering first

**Resend already does marketing.** Its dashboard has Broadcasts and Audience,
the domain is verified, and your volume is free. If the goal is campaigns to
your own enquiry list, that is zero extra setup, zero cold start and no
second mailbox to check — and it sends from a domain already proven to
deliver into inboxes.

Workspace earns its place when you want Gmail's interface and a real mailbox
for a person. It is not required to send campaigns.

**Also note:** you currently pay for 2 Workspace seats (~$12.60/month, invoice
5704420644, 30 Sept 2026) which receive nothing, because the MX points at
cPanel. Whatever you decide, make those two seats either useful or cancelled.
