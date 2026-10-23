# FABRIOZA — open items

Compiled 23 Oct 2026. Every number below was measured today, not remembered.

---

## 0. The one that is costing money right now

**76 of your 82 leads are still marked `new`.** Six have been worked. Leads
#87–#91 (16–22 Oct) received **no auto-reply and no notification**, because
SMTP was dead — the mail host had the server firewall-banned and every send
failed after three retries.

Resend is now live and fixes that going forward. It does nothing about the
76 already sitting there.

- [ ] Work the backlog at `https://fabrioza.com/admin/leads.php`. Even a
      one-line reply to the last 20 is worth more than anything else on this
      page.
- [ ] **Thomas du Four** (#91, 22 Oct) and **Guillermo Estrada** (#90) are the
      freshest and got nothing. Start there.
- [ ] The next real form submission is the true test of Resend — the only
      sends so far were my direct tests. Watch that lead's email history.

---

## 1. Email marketing

### Decide first
- [ ] **Subdomain or new domain?** `news.fabrioza.com` is free, inherits your
      sending reputation and isolates campaigns from your quote address. A new
      domain costs ~£10/yr and **starts at zero reputation** — weeks of warm-up
      before campaigns reach inboxes. Full comparison in `MARKETING-EMAIL-SETUP.md`.
- [ ] **Workspace or Resend Broadcasts?** Resend already has Broadcasts and
      Audience, the domain is verified, and it is free at your volume. Workspace
      is worth paying for if you want Gmail's interface and a real mailbox —
      not to send campaigns.

### Then set up
- [ ] Add the chosen domain/subdomain to Workspace as a **secondary domain**
      (not an alias), or skip if using Resend.
- [ ] DNS in cPanel Zone Editor — exact records in `MARKETING-EMAIL-SETUP.md`.
- [ ] **Campaigns must send as `news@news.fabrioza.com`, not `info@fabrioza.com`.**
      Root DMARC is `adkim=s` (strict). A campaign From your root address but
      DKIM-signed by the subdomain fails DMARC, and with `p=none` nothing
      bounces — it just silently lands in spam. Set reply-to `info@fabrioza.com`.
- [ ] After any zone edit: `bash tools/resend_check.sh`. It fails loudly if the
      root MX, cPanel DKIM or SPF went missing.

### Clean up
- [ ] **2 Google Workspace seats receive nothing** (~$12.60/mo, invoice
      5704420644). The MX points at cPanel, so those mailboxes cannot receive.
      Make them useful or cancel them.
- [ ] **The Gmail signature is still placeholder text** — "Mission statement",
      "Phone number | Website". Replace or remove.
- [ ] **Ask the mail host to lift the ban on `209.145.55.76`.** Resend routes
      around it for sending, but while it stands the server cannot reach cPanel,
      webmail **or IMAP** — so `import-inbox.php`, which pulls replies into the
      CRM, is dead.

---

## 2. Claims still unresolved

- [ ] **amfori BSCI — on 43 pages, still unevidenced after a month.** It is the
      only certification claim left on the site. If there is no certificate with
      a verifiable number, it should go the way ISO 9001 did: before a client
      asks, not after. ZYEN and Owain already caught you once.
- [ ] **"20-piece trial orders credited toward bulk" — 5 pages.** You said
      20-piece runs cost *more per unit*. This says the cost comes back. Both
      could be true; I will not guess which.
- [ ] **"We will remake the sample free of charge"** — homepage. A guarantee on
      a paid sample, not an offer of a free one, so I left it. Your call.

---

## 3. Pricing and proof

- [ ] **Standalone prices for vector art, patches and sublimation.** Only
      digitizing is priced ($18 / $28 / $45 / from $60). The other three say
      "quoted separately" because you approved the model, not the figures.
- [ ] **Real photographs.** The highest-converting thing missing is a digitizing
      **before/after** — a customer's rough logo beside the stitched result.
      AI cannot fake that credibly and I will not try. One phone photo of a real
      stitch-out beats every generated image on the site.
- [ ] **9 factory videos** sitting unused in the marketing folder.
- [ ] **Real price list** — much of the catalogue still runs on the +$4 formula.

---

## 4. Never done from the September urgent list

- [ ] `/process` page — does not exist
- [ ] `/faq` page — does not exist
- [ ] **WhatsApp button on only 5 of 99 pages**
- [ ] Real production photos sitewide (all 300 images are AI-generated)
- [ ] Client testimonials — still zero, and still must be real
- [ ] DMS Global Houston warehouse — never mentioned anywhere

---

## 5. UK site (`Documents/fabrioza-uk`)

- [ ] **All 14 settings are empty** — moq, lead_time, free_services,
      legal_name, company_number, vat_number, phone, email, certificates,
      sample_policy and more. Nothing can launch until these are filled;
      the build deliberately refuses to print a claim you have not supplied.
- [ ] **`fabrioza.co.uk` is not purchased.**
- [ ] **Decide: merge or separate.** My recommendation stands — put it on
      `uk.fabrioza.com`, which needs zero code changes and inherits domain
      authority. A cold `.co.uk` cannot rank by January.
- [ ] **Deadline:** UK schools order leavers hoodies Jan–April. To rank for the
      2027 cycle, content must be live by **early December**. That is ~6 weeks.

---

## 6. Housekeeping

- [ ] **The KWG photographs are still in public git history.** 60 files were
      committed in `259bb43` and deleting them from the working tree does not
      remove them from earlier commits. The repo
      (`github.com/yaseenishfaqjan/fabriozaupdated-site-`) is public. If Kool
      World Global asks whether the material was distributed, this is the honest
      answer. Purging needs a history rewrite and force push.
- [ ] **Revoke the exposed Gmail App Password** `pvxvyboouqdqvsez`.
- [ ] **Semrush has no API units** — blocks volume-led keyword work. Everything
      built since is based on buyer language, not measured volume.
- [ ] If the portfolio ever returns: get written permission from Kool World
      Global, House of A&D and DAY first.

---

## Done this month, for reference

Homepage collection cards · /portfolio built then withdrawn on KWG's notice ·
phone number changed sitewide · form file uploads (they were never wired —
the input had no `name`) · `lead_files` migration · /print-on-demand-alternative ·
/design configurator with live per-technique preview · /brand-launch and four
service pages · patch material gallery · **Resend live** · auto-reply rewritten ·
MOQ stated as two tiers · **every "free sample" claim removed** (26 across 16 files).
