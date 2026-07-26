# CRM-AUDIT.md — Fabrioza Admin/CRM Audit

Date: 2026-07-26 · Read-only audit of repo `fabrioza-deployment-v3` @ `6ad160a` (deployed) · No changes made.

## Headline finding

**There is no admin panel, no CRM, and no database anywhere in the Fabrioza project.**
The "dashboard" does not exist yet — leads live exclusively in email inboxes. Everything below documents what actually exists and what building a real lead pipeline would take.

## 1. Stack

| Layer | What exists |
|---|---|
| Frontend | Static prerendered HTML (~70 pages) + React/Vite SPA homepage. No admin routes. |
| Backend | Exactly **one** endpoint: `dist/api/send-email.php` (PHP 8.3 inside the `php:8.3-apache` Docker image) using bundled PHPMailer over SMTP (smtp.hostinger.com:465, creds via container env from VPS `.env`). |
| Database | **None.** No MySQL/SQLite/Postgres, no ORM, no schema, no migrations. `docker-compose.yml` defines a single `web` service — no DB container. |
| Auth | **None** (nothing to log into). |
| Framework | None server-side; the PHP is a single hand-written script. |

## 2. Lead data currently captured (in email bodies only)

The SPA posts JSON to `/api/send-email.php` with these fields:
`name`, `email`, `company`, `product_type`, `quantity`, `message`, `source`, `form_type`.

Lead **sources already wired** in the SPA (each sets `form_type`):
- "Contact Form" (main quote form, `/#contact`)
- "Chat Lead Capture" (chat widget, `source: "Live Chat Widget"`)
- "10% Discount Popup"
- "Free Guide Download" / "Free Pricing Guide Download"

**DB tables/schema: none exist.** These fields are formatted into an HTML email and discarded — no record survives outside the mailbox.

## 3. Where leads land today

1. `POST /api/send-email.php` → sends a "New Lead" notification email to every address in `MAIL_TO` (currently `sales@fabrioza.com`, `info@fabrioza.com` on Hostinger), with `Reply-To` set to the lead.
2. Best-effort auto-reply is sent to the client.
3. **Nothing is written to any database or file.** If the SMTP send fails, the lead is lost permanently (the client sees an error asking them to email directly — but the submitted data is gone).
4. `/sample-order` has **no form of its own** — its CTAs link to the homepage quote form, so trial-order leads arrive as ordinary "Contact Form" leads (no `form_type` distinguishing trial intent).
5. Only aggregate, non-PII counting exists: the GA4 `generate_lead` event (added July 2026) fires on successful submit.

## 4. Existing admin UI

**None.** No lead list, no pipeline/kanban, no detail view, no notes, no follow-up reminders, no export. Lead management today = reading the shared inbox.

## 5. Auth / security / PII exposure

- No admin surface exists → no admin attack surface. ✔
- Lead PII lives only in the two Hostinger mailboxes (their security = the mailbox passwords) and transiently in SMTP transit (SSL). Nothing is web-exposed. ✔
- `send-email.php` risks: `Access-Control-Allow-Origin: *` + **no rate limiting, no honeypot, no CAPTCHA** → the endpoint can be scripted to spam your inboxes and burn SMTP quota; also makes fake `generate_lead` events possible.
- No logging at all: good for PII minimization, bad for recovery (failed sends unrecoverable, no audit trail).
- SMTP password handled correctly (env only, never in git). ✔

## 6. Gap analysis: today → working lead-capture → pipeline → follow-up CRM

| Capability | Today | Needed |
|---|---|---|
| Durable lead storage | ❌ email only, lossy | DB table `leads` (id, ts, name, email, company, product_type, quantity, message, form_type, source, page, status, assigned, notes, next_follow_up) — SQLite file or MySQL container |
| Capture reliability | ❌ SMTP fail = lead lost | Write to DB **first**, then email; retry queue for mail |
| Trial-order attribution | ❌ indistinguishable | `form_type: "Trial Order"` + a small form on /sample-order |
| Admin app | ❌ none | Lead list w/ filters, detail view, status pipeline (New → Quoted → Sample → Trial → Bulk → Won/Lost), notes, follow-up dates |
| Auth | ❌ n/a | Login (single admin user minimum), session, HTTPS-only cookie, `/admin` blocked in robots.txt |
| Spam protection | ❌ none | Honeypot field + per-IP rate limit (cheap, no user friction); CAPTCHA only if abuse appears |
| Follow-up automation | ❌ manual inbox | "Due today" view + optional daily digest email to sales@ |
| Reporting | GA4 counts only | Leads by source/form_type/week; conversion to quoted/won |

### Two realistic build paths
1. **Self-hosted micro-CRM (fits current stack):** add SQLite (PHP PDO, zero new containers) + `POST` insert in the existing handler + a small password-protected `/admin` PHP app. ~1–2 sessions of work, no monthly cost, data stays on your VPS.
2. **External CRM:** keep the form, add a webhook/API push to HubSpot Free / Zoho Bigin / Brevo. Faster to a polished pipeline UI, but monthly cost curve, data offsite, and another login for the team.

Recommendation: path 1 — the lead volume is early-stage, the stack is already PHP/Apache/Docker, and durable capture (stop losing leads on SMTP failure) is the urgent piece either way.

— End of audit. No files modified other than creating this document.
