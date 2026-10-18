# Sending mail through Resend

## Your address does not change

Mail still goes out **as `info@fabrioza.com`**. Resend is the delivery pipe,
not a new mailbox. Nobody sees a Resend address, and replies come back to
`info@fabrioza.com` as they always have.

You do **not** need to move your mailbox. cPanel keeps receiving mail exactly
as it does now — MX still points at `mail.fabrioza.com`. This only changes how
the website *sends*.

---

## Why we moved

On 18 Oct 2026, from the VPS (`209.145.55.76`):

| Target | Result |
|---|---|
| `95.216.22.216` (your mail host) ports 25 / 465 / 587 | **all time out** |
| `95.216.22.216` port **443** | **times out** |
| ping `95.216.22.216` | **100% packet loss** |
| smtp.gmail.com:465 | OPEN, instant |
| smtp.office365.com:587 | OPEN, instant |
| smtp.resend.com:465 | OPEN, instant |

Outbound SMTP was never blocked. Your mail host has **firewall-banned the
server's IP** — every port, including HTTPS and ICMP. cPanel hosts run
CSF/LFD and cPHulk, which auto-ban an IP after repeated failed SMTP logins,
and the mailer had been retrying three times per email with a password that
no longer worked.

Resend goes over **HTTPS on port 443 to a different host**, so an SMTP port
policy or a ban on one mail server cannot stop it. A wrong API key returns a
clean `401` instead of hammering a login until the IP is banned again.

---

## Setup

### 1. Verify the domain in Resend

**Add `fabrioza.com` — the ROOT domain. Not `send.fabrioza.com`.**

This is not a preference. Your DMARC record is:

```
v=DMARC1; p=none; rua=mailto:postmaster@fabrioza.com; adkim=s; aspf=s
```

`adkim=s` is **strict DKIM alignment**: the DKIM signing domain must match the
From domain *exactly*. Resend's default suggestion is a subdomain, which would
sign as `d=send.fabrioza.com` while the From header says `@fabrioza.com` —
those do not match under strict alignment, so **DMARC would fail on every
message**. `p=none` means nothing would bounce, so you would not get an error;
the mail would just quietly land in spam, which is worse.

Verify the root domain and DKIM signs as `d=fabrioza.com`, which aligns.

### 2. Add the DNS records Resend gives you

cPanel → Zone Editor → fabrioza.com. Paste exactly what the Resend dashboard
shows. It will be a DKIM `TXT` record and usually an `MX`/`TXT` pair for the
return path.

**Do not delete or edit the existing records:**

- `default._domainkey` — cPanel's own DKIM, still used for mail you send from
  webmail or your phone
- `v=spf1 a mx ip4:95.216.22.216 ~all` — your current SPF
- The `MX` pointing at `mail.fabrioza.com` — this is how you **receive** mail.
  Touching it breaks your inbox.

If Resend asks you to change SPF, **add its include to the existing record**,
never replace it. One SPF record per domain, and losing `a mx` would break
sending from cPanel.

### 3. Put the key on the server

```bash
nano /opt/fabrioza/.env
```

Add:

```
RESEND_API_KEY=re_xxxxxxxxxxxxxxxxxxxx
RESEND_FROM=info@fabrioza.com
RESEND_FROM_NAME=FABRIOZA
```

Then:

```bash
cd /opt/fabrioza && docker compose up -d --build
```

No `$$` escaping needed here — unlike `SMTP_PASS`, Resend keys contain no `$`.

### 4. Check it

```bash
docker exec fabrioza-web php -r '
require "/var/www/html/api/mailer.php";
[$ok,$note] = resendSend("info@fabrioza.com","FABRIOZA","Resend test",
                         "<p>Sent through Resend.</p>");
echo ($ok?"OK  ":"FAIL"), " ", $note, PHP_EOL;'
```

`OK via resend id=...` means it is working. Then use the **Resend notification**
button on any lead and confirm the email history row says `via resend`.

---

## How it behaves

`fab_send()` is the single entry point. It tries **Resend first when
`RESEND_API_KEY` is set, then falls back to SMTP**.

- **No key set** — behaviour is exactly what it was before. Safe to deploy
  ahead of having an account.
- **Key set, Resend works** — logged as `via resend id=...`.
- **Key set, Resend fails** — the error is recorded and SMTP is tried anyway,
  so a Resend outage cannot lose a lead notification.
- Lead capture never depends on mail. The lead and its attachments are written
  to SQLite before any send is attempted.

## Until the domain is verified

Resend only allows sending to **your own account email**, from
`onboarding@resend.dev`. Notifications to `info@fabrioza.com` will fail with a
403 and fall back to SMTP, which is currently banned — so mail stays broken
until step 1 is done. Verification is the part that actually fixes it.

## Also worth doing

Ask your mail host to lift the firewall ban on `209.145.55.76` regardless.
With Resend in place it is no longer urgent, but while that ban stands the
server cannot reach cPanel, webmail or IMAP on that host — which also means
**`import-inbox.php` cannot read the mailbox.**
