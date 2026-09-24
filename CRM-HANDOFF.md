# CRM-HANDOFF.md — Operating the FABRIOZA CRM

Everything the team needs to run the lead system. The CRM lives at
**https://fabrioza.com/admin** · data in SQLite at `/var/data/fabrioza_crm.db`
(Docker volume `fabrioza_crm_data`, outside the web root, survives rebuilds).

## 1. Admin password

Set / change it (replace YOUR-PASSWORD, keep quotes). The `${H//$/\$\$}` part
escapes `$` signs — **required**, docker-compose eats single `$`:

```bash
H=$(docker exec fabrioza-web php -r "echo password_hash('YOUR-PASSWORD', PASSWORD_BCRYPT);")
sed -i '/^ADMIN_PASS_HASH=/d' /opt/fabrioza/.env
echo "ADMIN_PASS_HASH=${H//$/\$\$}" >> /opt/fabrioza/.env
cd /opt/fabrioza && docker compose up -d
```

Login rules: username `admin` (or ADMIN_USER in .env), 5 wrong attempts = 15-minute
lockout per IP, sessions expire after 2 idle hours.

## 2. The two cron jobs (already installed)

```
0 6 * * *   docker exec fabrioza-web php /var/www/html/api/daily-digest.php       # morning summary email
30 6 * * *  docker exec fabrioza-web php /var/www/html/api/process-sequences.php  # automated follow-ups
```
```
*/30 * * * * docker exec fabrioza-web php /var/www/html/api/import-inbox.php   # Gmail inbox -> CRM
```
The inbox importer logs replies from known leads onto their CRM record
(pauses sequences, promotes new->quoted) and creates leads from unknown
senders (form_type "Inbox Email"). One-time historic backfill:
`docker exec fabrioza-web php /var/www/html/api/import-inbox.php --all`
Preview safely first with `--dry-run`. GDPR note: imported emails are
processed under legitimate interest (they wrote to us); the erasure
procedure applies to them like any lead.

Check crons with `crontab -l`; logs in `/var/log/fabrioza-digest.log`,
`/var/log/fabrioza-sequences.log` and `/var/log/fabrioza-inbox.log`. Preview what sequences WOULD send:
`docker exec fabrioza-web php /var/www/html/api/process-sequences.php --dry-run`

## 2b. Instant lead notifications on your phone (Telegram)

Gmail hides self-sent notification emails, so the CRM pushes new leads to
Telegram instead. One-time setup (5 minutes):
1. In Telegram, message **@BotFather** -> /newbot -> pick a name ->
   copy the **token** (looks like 123456:ABC-DEF...).
2. Message your new bot anything (e.g. "hi").
3. Open https://api.telegram.org/bot<TOKEN>/getUpdates in a browser -
   copy the number at "chat":{"id": ... } - that is your **chat id**.
4. On the VPS:
   echo "TELEGRAM_BOT_TOKEN=thetoken" >> /opt/fabrioza/.env
   echo "TELEGRAM_CHAT_ID=thechatid" >> /opt/fabrioza/.env
   cd /opt/fabrioza && docker compose up -d
From then on every new form lead, inbox lead and detected reply pings your
phone instantly with a direct link to the lead. Unset = feature off.

## 3. Backups

The whole CRM is one file. Daily snapshot + 30-day retention:

```bash
mkdir -p /root/crm-backups
(crontab -l 2>/dev/null; echo "15 5 * * * docker exec fabrioza-web sh -c 'sqlite3 /var/data/fabrioza_crm.db \".backup /var/data/backup.db\"' && docker cp fabrioza-web:/var/data/backup.db /root/crm-backups/crm-\$(date +\\%F).db && find /root/crm-backups -name 'crm-*.db' -mtime +30 -delete") | crontab -
```
(If `sqlite3` is missing in the image, a plain `docker cp fabrioza-web:/var/data/fabrioza_crm.db ...` copy is acceptable at this scale.)
Restore = copy a backup file back to `/var/data/fabrioza_crm.db` and restart.
Recommended: rsync `/root/crm-backups` somewhere off the VPS weekly.

## 4. GDPR

**What is stored per lead:** name, email, company, country, product interest,
quantity, message, form type, page, UTM tags, consent flag + timestamp, and a
salted SHA-256 hash of the IP (raw IPs are never stored). Emails sent to the
lead are logged (recipient/subject/status).

**Right to erasure:** open the lead → Danger zone → Delete lead. This
hard-deletes the lead and its notes and writes a PII-free entry to the audit
log. That is the complete procedure — nothing else retains the person's data
except your Gmail mailbox (delete the thread there too).

**Consent:** every website form requires the consent checkbox; consent time is
stored per lead. Manual entries (LinkedIn etc.) are marked and excluded from
automated emailing.

**Retention:** TODO(fabrioza): confirm a retention period (common default:
delete leads 2 years after last contact). Once confirmed, we can add an
automatic cleanup to the daily cron.

## 5. Email sequences — how to add or change one

1. Create `dist/api/email-templates/<name>.php` returning a
   `function(array $lead): array{subject, body}` (copy `quote-day3.php`,
   use `fab_tpl_wrap()` for the branded frame).
2. Add a rule to `$SEQUENCES` in `dist/api/process-sequences.php`:
   `'Form Type' => [[days_after_creation, 'template-name', 'only_status']]`.
3. Commit, deploy. Dedup is automatic (the `[seq:name]` tag in email_log);
   a template is sent at most once per lead, only while the lead is in the
   rule's status, never when paused, never to leads older than the rollout window.

**Daily habit that keeps automation polite:** when a lead replies in Gmail,
open their CRM page and click **"Log reply received"** — moves them to
*quoted* and stops their sequences.

## 6. Environment reference (/opt/fabrioza/.env)

| Var | Purpose |
|---|---|
| SMTP_HOST / SMTP_PORT / SMTP_USER / SMTP_PASS | cPanel mailbox (s99.veladns.com:465, info@fabrioza.com) |
| MAIL_TO | comma-separated notification recipients |
| IMAP_HOST | mailbox the inbox importer reads (default s99.veladns.com) |
| ADMIN_USER / ADMIN_PASS_HASH | admin login ($ doubled as $$) |
| CRM_IP_SALT | secret for IP hashing — set once, never change |
| CRM_RATE_MAX | form submissions per IP per hour (default 3) |
| RECAPTCHA_SECRET | reCAPTCHA v3 secret (site key lives in dist/index.html) |
| WEB_PORT | host port (8085 on the VPS) |

## 7. Troubleshooting

- **No notification email but the visitor saw "Thank you"** — by design: the
  lead is in the CRM; check the lead's Email history for the SMTP error.
- **Locked out of admin** — wait 15 minutes, or clear the lockout:
  `docker exec fabrioza-web php -r 'require "/var/www/html/api/db.php"; crm_db()->exec("DELETE FROM rate_limits");'`
- **Form says "Session expired"** — the visitor's browser blocked cookies;
  a reload fixes it. If widespread, check that /api/csrf.php returns a token.
- **Bot got through anyway** — open the lead, set status to `spam` (trains
  nothing, but keeps stats clean), and tell the developer the pattern; the
  heuristics in `dist/api/db.php > crm_spam_reason()` are easy to extend.
- **Everything on fire** — leads are in `/var/data` (volume) + backups; the
  site itself redeploys from GitHub with the usual two commands.

## 8. Mail transport (changed 24 Sep 2026)

Outbound mail used to go through Gmail SMTP with an App Password. Google began
rejecting it ("SMTP Error: Could not authenticate"), and lead notifications
silently failed - the leads themselves were still saved, because the CRM writes
to the database before it tries to send.

Mail now goes through the **cPanel mailbox on the veladns server**:

```
SMTP_HOST=s99.veladns.com   SMTP_PORT=465
SMTP_USER=info@fabrioza.com SMTP_PASS=<mailbox password>
```

Why this is better: cPanel's Email Deliverability page shows **SPF and DKIM valid**
for fabrioza.com on that server, so mail is authenticated and sends *as* the
business address instead of relaying through a personal Gmail.

**If notifications start failing again**, check the lead's Email history in the
admin for the exact SMTP error, then:
- "Could not authenticate" -> the mailbox password changed; update SMTP_PASS
- "Connection refused/timed out" -> the VPS cannot reach port 465 outbound
- Confirm the mailbox still exists in cPanel -> Email Accounts

No Gmail address is referenced in the code any more. `IMAP_HOST` controls which
mailbox the inbox importer reads; it defaults to the same server.

## 9. Resend notification button (added 24 Sep 2026)

Every lead page has a **Resend notification** button under Email history. It
re-sends the internal "new lead" email using the current SMTP settings.

Use it when a lead's Email history shows `failed` - the lead was always saved
(the CRM writes to SQLite before it tries to send), only the mail failed.

- The button turns green when any previous send for that lead failed.
- Result appears as a banner at the top of the page, and a new row is written
  to Email history with "(resent)" in the subject.
- Audited as `notification_resent` / `notification_resend_failed`.
- It makes **one** send attempt, not the three the public form uses - an admin
  is waiting on the browser, and a bad password will not fix itself on retry.
  Takes a few seconds; click again if you hit a transient failure.

**Shared mailer:** `dist/api/mailer.php` holds the single implementation of
`smtpSend` / `smtpSendOnce` / `logEmail` / `buildNotificationEmail`, used by
both `api/send-email.php` and `admin/lead.php`. Do not copy these functions
into a page - a second copy will drift from the first.
