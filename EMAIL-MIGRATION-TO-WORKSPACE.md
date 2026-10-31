# Moving fabrioza.com email from cPanel to Google Workspace

Measured 30 Oct 2026 against live DNS and the running container. Read the
"three things that break" section before touching DNS — two of them are in our
own code and nothing will tell you they broke.

---

## What is actually true right now

The Gmail window says "Managed by fabrioza.com" with an Admin console link, so
it looks like the mailbox already lives in Google Workspace. **It does not.**

```
MX  fabrioza.com  ->  mail.fabrioza.com  ->  95.216.22.216
```

That is the cPanel server. **Google is not receiving your mail.** A Workspace
account exists for info@fabrioza.com and one `google-site-verification` TXT is
published, but delivery was never moved — Gmail is *fetching* from cPanel.
That is also why the sidebar shows `INBOX.Drafts`, `INBOX.Junk`, `INBOX.Sent`
and `INBOX.Trash`: those dotted names are cPanel/Dovecot folders, pulled in by
an import. A native Gmail account has no such labels.

So the job is not "set up Workspace". Workspace is set up. The job is to move
**delivery** and **history**, and to repoint the three things in our own stack
that still talk to cPanel.

Current outbound, from inside the container:

| | value |
|---|---|
| primary sender | Resend (`resend._domainkey` published, SPF on `send.fabrioza.com`) |
| fallback SMTP | `s99.veladns.com:465` as `info@fabrioza.com` |
| lead alerts to | `info@fabrioza.com`, `yasinishfaqllc5@gmail.com` |
| SPF | `v=spf1 +a +mx +ip4:95.216.22.216 +ip4:95.216.243.216 ~all` |
| DMARC | `p=none; adkim=s; aspf=s` |
| Google DKIM | **not published** (`google._domainkey` does not resolve) |

---

## The three things that break, and only one is in DNS

### 1. `import-inbox.php` reads the cPanel mailbox over IMAP

This is the one nobody remembers. `dist/api/import-inbox.php` logs into the
cPanel mailbox over IMAPS with curl and writes the `mail_seen` table — **917
rows**. It is how inbound customer replies reach the CRM at all.

The moment the cPanel mailbox stops receiving, that script keeps running,
keeps succeeding, and silently imports nothing. There is no error, because
connecting to an empty mailbox is not an error. **This is the same shape as
the 6–22 Oct blackout** — a mail path that failed without logging.

It must be repointed to `imap.gmail.com:993` with an App Password, and the
first run after cutover must be checked by counting rows, not by exit code.

### 2. The SMTP fallback points at cPanel

`SMTP_HOST=s99.veladns.com`, `SMTP_USER=info@fabrioza.com`. When the cPanel
mailbox goes, the fallback goes with it. Resend is primary so mail still
flows — which is exactly why this would sit broken unnoticed until the day
Resend has an outage and there is nothing behind it.

Note the compose file *defaults* to `smtp.hostinger.com` while the PHP
defaults to `s99.veladns.com` and the live value is `s99.veladns.com`. Three
different answers in three places; only the container's value is real.

### 3. SPF does not authorise Google, and DMARC alignment is strict

```
v=spf1 +a +mx +ip4:95.216.22.216 +ip4:95.216.243.216 ~all
```

No `include:_spf.google.com`. Combined with `aspf=s` and `adkim=s` (strict
alignment on both), anything sent through Google as `@fabrioza.com` fails SPF
*and* fails DKIM, because `google._domainkey` is not published either.

`p=none` is the only reason this is not already causing bounces — nothing is
rejected, it just lands in spam more often. **If you are already replying to
customers from this Gmail window, those replies are unauthenticated today.**
That is worth fixing regardless of whether you complete the migration.

There is a trap in `+mx`: it authorises whatever MX resolves to, so the moment
MX points at Google, SPF would start passing *by accident*. Do not rely on
that — publish the explicit `include`.

---

## sales@fabrioza.com — pick the right kind, it is a cost decision

Three options, and they are not the same thing:

| | cost | can receive | can send as | separate login |
|---|---|---|---|---|
| **Alias** on your existing user | **free** | yes | yes | no |
| **Google Group** | **free** | yes | yes (members) | no |
| **New user** | **a full extra seat, monthly** | yes | yes | yes |

- If **you** are reading sales@ — make it an **alias**. Free, arrives in the
  same inbox, and you can send *as* sales@ with a "from" selector. This is
  almost certainly what you want.
- If you want it to survive you hiring someone, or want replies visible to
  more than one person — make it a **Group**. Also free, and you add or remove
  members without touching billing.
- Only create a **new user** if someone else needs their own login, their own
  Drive and their own password.

Aliases: Admin console → Directory → Users → (your user) → User information →
Email aliases. Up to 30 per user, no charge.

**Do not pay for a seat to get a second address.** That is the most common way
money gets spent here for nothing.

---

## The sequence

Order matters. Steps 1–3 are safe to do today and change nothing about
delivery; the cutover is step 5.

### Step 1 — confirm what the Workspace actually is

Admin console → Billing → Subscriptions: note the plan and how many seats you
are paying for. Then Directory → Users: confirm `info@fabrioza.com` is a real
user and not just a Gmail "send mail as" identity.

### Step 2 — publish Google DKIM (safe, no delivery change)

Admin console → Apps → Google Workspace → Gmail → Authenticate email →
Generate new record (2048-bit) → publish the `google._domainkey` TXT at your
DNS host → come back and click **Start authentication**.

Verify before moving on:

```bash
nslookup -type=TXT google._domainkey.fabrioza.com 8.8.8.8
```

### Step 3 — add Google to SPF (safe, additive)

Change the single SPF TXT to:

```
v=spf1 +a +mx include:_spf.google.com +ip4:95.216.22.216 +ip4:95.216.243.216 ~all
```

**One SPF record only.** Two `v=spf1` records on the same name is a permerror
and breaks *all* of your mail, including Resend. Edit the existing record, do
not add a second one.

Leave Resend's `send.fabrioza.com` SPF and `resend._domainkey` exactly as they
are — they are on different names and are not affected.

### Step 4 — migrate the old mail IN, while cPanel still has it

Admin console → Data → Data import & export → **Data migration**.

- Source: IMAP (Other IMAP server)
- Server: `mail.fabrioza.com`, port **993**, SSL on
- Sign in with the cPanel mailbox address and its password
- Select the date range — choose **all mail**, not a window

This is Google's own server-side tool. It preserves folders, dates and
read/unread state, and it is free. It is much better than re-importing by
hand, and **far** better than the POP fetch that produced those `INBOX.Junk`
labels.

Do this **before** step 5. Once MX moves, new mail arrives at Google and the
cPanel copy stops growing — but everything already on cPanel is still only on
cPanel.

### Step 5 — the cutover (this is the only step with downtime risk)

Replace the MX record. Delete `mail.fabrioza.com` as MX and publish Google's.
Your DNS host must end up with Google's MX values and nothing else.

Then wait. MX changes propagate on the record's TTL — check it first, and if
it is long (3600+), lower the TTL to 300 a day *before* cutover so you can
undo quickly.

**Do not delete the cPanel mailbox for at least two weeks.** Mail sent by
someone with a stale DNS cache will still land there, and that is your only
copy of it. Keep the hosting until you have gone a full week with nothing new
arriving on cPanel.

### Step 6 — repoint our own stack (the part that is not in any guide)

On the account that the app will use: turn on 2-Step Verification, then create
an App Password (Google Account → Security → App passwords). Admin console →
Apps → Gmail → End User Access must have **IMAP access enabled** org-wide, or
the App Password will authenticate and then be refused.

Then on the VPS, in `/opt/fabrioza/.env`:

```
IMAP_HOST=imap.gmail.com
IMAP_PORT=993
IMAP_USER=info@fabrioza.com
IMAP_PASS=<the 16-character app password, no spaces>
SMTP_HOST=smtp.gmail.com
SMTP_PORT=465
SMTP_USER=info@fabrioza.com
SMTP_PASS=<the same app password>
```

**A variable only reaches the container if it is declared in
`docker-compose.yml` under `environment:`.** `IMAP_HOST` and `IMAP_PASS` are
not currently declared there — adding them to `.env` alone does nothing.

And **`docker restart` does not re-read env**:

```bash
ssh root@209.145.55.76 "cd /opt/fabrioza && docker compose up -d"
```

Then check the value from **inside** the container, never from the file:

```bash
ssh root@209.145.55.76 "docker exec fabrioza-web printenv | grep -E 'IMAP_|SMTP_'"
```

### Step 7 — prove it, do not assume it

1. Send a mail from an outside address to info@fabrioza.com. It must land in
   Gmail, not cPanel.
2. Reply from Gmail. In the recipient's copy, open **Show original** and
   confirm three lines: `spf=pass`, `dkim=pass`, `dmarc=pass`.
3. Submit the website contact form. Confirm the lead row is created *and* the
   auto-reply is logged `sent` in `email_log`.
4. Run `import-inbox.php` and **count `mail_seen` rows before and after**. A
   clean exit means nothing — an empty mailbox exits clean too.

### Step 8 — only then, tighten DMARC

Once steps 1–7 pass for a week, move DMARC from `p=none` to
`p=quarantine`. Not before: with strict alignment and a half-migrated setup,
quarantine would start eating real customer mail.

---

## What I would not do

- **Do not cancel cPanel hosting on cutover day.** The website is not hosted
  there, but the mailbox is the only copy of your history until step 4 is
  verified complete.
- **Do not create a paid seat for sales@.** Use an alias.
- **Do not let Gmail keep POP-fetching from cPanel after cutover.** You will
  get duplicates and a confusing split. Remove the fetcher in Gmail →
  Settings → Accounts → "Check mail from other accounts" once migration is
  verified.
- **Do not change SPF and MX in the same sitting.** Do SPF and DKIM first,
  confirm they resolve, then move MX. If something goes wrong you want one
  variable to undo, not two.
