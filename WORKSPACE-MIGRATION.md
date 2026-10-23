# Moving info@fabrioza.com to Google Workspace

Owner question, 23 Oct 2026: *"my planning is also to ship our info@fabrioza.com
to google workspace ... i think this is good because cpanel services are not
good"*

**Verdict: yes, do it.** But read the two warnings first, because one step is
irreversible and one must happen before it.

---

## It reverses an earlier instruction — knowingly, not by accident

On 21 Oct the instruction was the opposite:

> "for cPanel email, we need to keep this as we are receiving the queries from
> the people from the businesses ... So I want to keep both accounts, like for
> Google Workspace and also the cPanel, okay? So do not destroy anything"

`MARKETING-EMAIL-SETUP.md` was written to that brief: marketing on
`news.fabrioza.com` in Workspace, enquiries left in cPanel, neither touching the
other.

**A domain has exactly one MX record set.** Pointing `fabrioza.com` at Google
means cPanel stops receiving mail at `@fabrioza.com` entirely — not "as well
as", instead of. You cannot have `info@` in both places. So this plan does
retire the cPanel mailbox, which is what "do not destroy anything" was guarding
against. That is a fine decision to make; it just has to be made on purpose.

If you want both after all, `news.fabrioza.com` in `MARKETING-EMAIL-SETUP.md`
still does that and needs no MX change.

---

## Why it is nonetheless the right call

**The reason you gave is half right, and the half that is right is the one that
matters.**

The mail host firewall-banned your own VPS — every port, including 443, including
ICMP — and has not lifted it after weeks. That killed `import-inbox.php`
(verified: it is no longer in cron and `mail_seen` has not grown) and it is why
SMTP from the site had to move to Resend. A host that blackholes its own
customer's server and does not answer the ticket is a real failing.

**But be clear-eyed about the cause.** The ban was almost certainly LFD/cPHulk
reacting to weeks of failed SMTP auth from the VPS retrying 3x per send. Google
would also lock an account hammered with bad credentials. The actual advantage is
not that Google's software is better — it is that **Google gives you an admin
console you control, instead of a support queue that does not reply.** That is
the argument, and it is a good one.

Three more reasons, each independently sufficient:

- **The September outage was caused by exactly this split.** MX pointed at Google
  while the mailboxes lived in cPanel, and mail vanished. Consolidating onto one
  system removes that whole class of bug permanently.
- **You already pay for it.** Two Workspace seats currently receive nothing
  (~$12.60/mo). `info@` can take one. Net cost of this migration: **$0**, and it
  starts earning back a seat you are already buying.
- **Nothing in the website depends on cPanel mail any more.** See below.

---

## What breaks: nothing, and here is the proof

Measured 23 Oct 2026 against live DNS.

| thing | depends on root MX? | survives? |
|---|---|---|
| Resend sending (every form, auto-reply, notification) | **no** — HTTPS to `api.resend.com:443` | **yes, untouched** |
| Resend SPF | **no** — lives on `send.fabrioza.com` (`ip4:52.3.252.119 ...`) | **yes** |
| Resend DKIM | **no** — `resend._domainkey.fabrioza.com`, root-signed | **yes** |
| Resend bounce handling | **no** — `send.fabrioza.com` MX -> `feedback.forge.rmta.net` | **yes** |
| `import-inbox.php` (IMAP -> CRM) | yes | already dead; not in cron, host-banned |

Subdomains carry their own MX. Changing the root MX does not touch
`send.fabrioza.com`, so **the entire Resend path is unaffected** — which means
the website keeps sending throughout the migration, including while MX is
mid-propagation. There is no window where the site goes quiet.

The root SPF (`v=spf1 a mx ip4:95.216.22.216 ~all`) points only at the cPanel
server (`mail.fabrioza.com` -> `95.216.22.216`). It contains no Resend include
and does not need one.

**Check before you start:** log into cPanel and list *every* mailbox, forwarder,
autoresponder and filter on `@fabrioza.com`. All of them stop when MX moves, not
just `info@`. Anything you want to keep has to be re-created in Gmail. I cannot
enumerate these from here — the VPS is banned from that host.

---

## Order of operations

Steps 1-3 are reversible. **Step 5 is the irreversible one. Step 3 must be
complete before it**, or the existing mail archive is lost.

### 1. Create info@fabrioza.com as a Workspace USER

Admin console -> Directory -> Users -> Add new user. A **user**, not an alias —
an alias has no mailbox and cannot receive the migration in step 3.

Use one of the two seats already being paid for.

### 2. Turn on Google's DKIM

Admin -> Apps -> Google Workspace -> Gmail -> **Authenticate email**.

Generate a **2048-bit** key for `fabrioza.com` and add the TXT record it gives
you at `google._domainkey.fabrioza.com` (currently empty — verified). Click
**Start authentication** only after the record resolves.

This must be on the **root** domain. `_dmarc.fabrioza.com` is `adkim=s; aspf=s`
— strict alignment — so a DKIM signature with `d=` anything other than exactly
`fabrioza.com` does not align.

### 3. Migrate the existing mail — BEFORE touching MX

Admin -> Account -> **Data migration**. Source: IMAP, `s99.veladns.com`, port
993, SSL. Credentials: the cPanel `info@` mailbox.

Do this **while cPanel still holds the mail.** Once MX moves, new mail goes to
Google and the old mail is still only in cPanel — and your access to that host
is already unreliable. Migrating first is the difference between keeping the
archive and losing it.

Run it on one small folder first and confirm messages arrive. If it fails, stop
and solve that before step 5; the ban is on `209.145.55.76` specifically, not on
Google's IPs, so it ought to connect — but verify rather than assume.

### 4. Update SPF

One TXT record, one SPF record. Replace the existing root SPF with:

```
v=spf1 include:_spf.google.com ip4:95.216.22.216 ~all
```

Google added; `ip4:95.216.22.216` kept deliberately so anything still sending
from the cPanel box during the cutover is not suddenly unauthenticated. Drop
`a mx` — those resolve to that same IP and become meaningless once MX moves.

**Do not add a Resend include.** Resend authenticates via `send.fabrioza.com`'s
own SPF and the root DKIM. Adding it here would be noise, and every needless
`include:` eats one of the 10 DNS lookups SPF allows.

Trim `ip4:95.216.22.216` once step 7 is done.

### 5. Flip the MX — the irreversible step

Delete `0 mail.fabrioza.com` and add:

```
MX  fabrioza.com  1  smtp.google.com
```

(If the Workspace console shows the older five-record `ASPMX.L.GOOGLE.COM` set,
use exactly what it shows.)

Propagation is usually under an hour, can be 24. Mail is not lost in between —
senders retry.

### 6. Verify, from outside

- Send to `info@fabrioza.com` from a Gmail/Outlook account that is not yours.
- In the received message: **Show original** -> `SPF: PASS`, `DKIM: PASS`,
  `DMARC: PASS`.
- Submit a real form on the live site and confirm the Resend notification and
  the customer auto-reply both still arrive. This is the check that proves the
  Resend path survived.
- Confirm DMARC reports still arrive (`rua=mailto:postmaster@fabrioza.com` —
  that address moves with the MX, so it follows automatically).

### 7. Only now retire the cPanel side

**Keep the cPanel mailbox for 30 days.** Do not delete it and do not cancel the
hosting mail service. If anything was missed, that mailbox is the only copy.

Leave `default._domainkey` in DNS for those 30 days too — it costs nothing and
mail already in flight may still reference it.

After 30 clean days: remove the cPanel mailbox, remove `default._domainkey`, and
trim `ip4:95.216.22.216` out of SPF.

---

## One thing to fix afterwards, not during

`_dmarc.fabrioza.com` is `p=none` — monitoring only. Nothing is enforced today,
which is lucky for this migration: a misstep will not bounce anyone's mail.

It also means `fabrioza.com` is currently spoofable. Once step 6 has passed
cleanly for a couple of weeks, move to `p=quarantine`, then `p=reject`.

**Not before.** Tightening DMARC during a mail migration turns a small mistake
into silent, invisible mail loss — and invisible failure is the specific thing
that has cost this project the most, twice over.
