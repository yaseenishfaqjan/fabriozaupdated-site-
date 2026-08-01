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
| SMTP_HOST / SMTP_PORT / SMTP_USER / SMTP_PASS | Gmail SMTP (App Password) |
| MAIL_TO | comma-separated notification recipients |
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
