# FABRIOZA — open items

Compiled 23 Oct 2026, rewritten 24 Oct after a long session. Every number
below was measured, not remembered. Items closed on 24 Oct are listed at the
bottom with the commit that closed them, so nothing looks open that is not.

---

## 0. The one that is costing money right now

**76 of your 82 leads are still marked `new`.** Six have been worked.

Measured against the live CRM on 24 Oct: **19 unique people enquired between
5 September and 22 October and received nothing at all** — no auto-reply, no
quote, no contact. Every one has four failed send attempts logged. They did
not ignore you; the mail server could not reach them.

The `email_log` tells the story: August 120 sent / 0 failed · September
38 / 37 · October 18 / 70.

- [ ] **Send the 19 replies.** They are written, personalised to what each
      person actually asked, and sitting in `LEADS-REPLY-EMAILS.md` (gitignored
      — it holds customer emails). Every price in them is one published on your
      own pricing page.
- [ ] Send **one at a time over two or three days**, from `info@fabrioza.com`.
      Nineteen near-identical emails leaving one mailbox in an hour is a
      deliverability risk on a domain whose sending reputation is days old.
- [ ] Chase after five days if silent: **Aadil Choudhry** (tech packs ready,
      UK, merino/Polartec), **Sam Scott-Cowie** (fully specified, 30 hoodies,
      ready to order) and **Oliver McLean** (Australian, long-term, multi-style).
      Those three are the most likely to buy.
- [ ] Two will probably not convert and that is fine — **Alyssa C.** wants a
      US-based supplier, and **Tiffany Austin** needs 18 school cheer uniforms
      on a $600 total budget. Both replies say so honestly.
- [ ] Move each lead from `new` to `contacted` as you go, so the 76 comes down
      and you can see who has not replied.

**Resend is verified working end to end** — a real form submission on 23 Oct
created lead #92 and sent all three emails (two notifications + the customer
auto-reply) with Resend message IDs. That test row was deleted.

---

## 1. Third-party proof — the biggest remaining gap

Nothing on the site is corroborated from outside your own domain. After this
week's cleanup every claim is accurate, but all of it is still you describing
yourself.

- [ ] **No Trustpilot page, no visible Google reviews, no review markup.**
      Two Sialkot competitors — Dravelle Industry and Qamar Exports — both have
      Trustpilot pages. Thin (one review, 3.5) but present. Someone searching
      "Fabrioza reviews" finds nothing.
- [ ] **Reviews are the one trust signal that needs no customer consent**,
      because the customer publishes them, not you. That is exactly the
      distinction the KWG notice turned on. You have 6 quoted customers and
      121 mailed letters across 7 verified members — ask them.
- [ ] **Publish the SECP registration number.** `/about` now commits to sending
      registration documents on request, which is keepable today. The number
      itself is stronger: a buyer can check it without contacting you at all.
      This is the one credential you certainly hold.

---

## 2. Make the two new commitments operationally true

`/about` and `/faq` now promise these publicly. They were not promised before
24 Oct. Owner confirmed both.

- [ ] **Third-party inspection welcome, at any stage, buyer's cost, no access
      charge.** SGS, Intertek, QIMA or anyone they appoint.
- [ ] **No fabric substitution without written approval.**
- [ ] Put both in the quote template, so whoever answers `info@` offers them as
      standard. A promise on the website that the inbox does not honour is
      worse than never making it.

---

## 3. Email marketing and the Workspace question

- [ ] **Move `info@fabrioza.com` to Google Workspace?** Owner's stated plan,
      deferred on 24 Oct. Recommended **yes** — full runbook, risks and ordering
      in `WORKSPACE-MIGRATION.md`. It replaces the cPanel mailbox rather than
      sitting beside it. Resend is unaffected (verified). One step is
      irreversible; one must precede it.
- [ ] **Subdomain or new domain for campaigns?** `news.fabrioza.com` is free and
      inherits your sending reputation; a new domain starts at zero and needs
      weeks of warm-up. Comparison in `MARKETING-EMAIL-SETUP.md`.
- [ ] **Campaigns must send as `news@news.fabrioza.com`, not `info@`.** Root
      DMARC is `adkim=s` (strict), and with `p=none` a misaligned campaign does
      not bounce — it silently lands in spam.
- [ ] **2 Google Workspace seats receive nothing** (~$12.60/mo). MX points at
      cPanel, so those mailboxes cannot receive. Make them useful or cancel.
- [ ] **Gmail signature is still placeholder text** — "Mission statement",
      "Phone number | Website".
- [ ] **Ask the mail host to lift the ban on `209.145.55.76`.** Resend routes
      around it for sending, but while it stands the server cannot reach cPanel,
      webmail or IMAP — so `import-inbox.php` is dead (confirmed: not in cron).

---

## 4. Search Console — verified 24 Oct, two things left

The property is verified (via the GA4 tag) and the sitemap is submitted and
processed: **last read 24/10/2026, 102 URLs**.

- [ ] **Request indexing for `/process` and `/faq`.** Genuinely new URLs, not
      recrawls. URL Inspection → Request indexing.
- [ ] **Add a second verification method.** The property is verified only
      through the GA4 tag on the homepage; if that tag ever changes you lose the
      property. Settings → Ownership verification → HTML file, send it to me.
      Your DNS TXT for the Domain property is already correct and waiting.
- [ ] Around mid-November, search your own key pages and confirm the old
      certification wording has dropped out of the snippets.

---

## 5. Proof and pricing

- [ ] **A digitizing before/after is the one asset worth photographing.** A rough
      customer logo beside the stitched result. Imagery is AI across the site by
      design and that is settled — but a real stitch-out is the one thing a
      buyer uses to judge embroidery quality, and it cannot be generated
      convincingly.
- [ ] **Standalone prices for vector art, patches and sublimation.** Only
      digitizing is priced ($18 / $28 / $45 / from $60). You approved the model,
      not the figures.
- [ ] **Real price list** — much of the catalogue still runs on the +$4 formula.
- [ ] **Confirm the /about factory stats once**: 10+ years, 50,000+ pieces/month,
      30+ countries, 8 specialised lines, 200+ skilled workers, 2,500+ daily
      capacity. Reviewed 24 Oct and kept — they are ordinary factory claims and
      the page invites a video call to verify them — but only you know if they
      are accurate.

---

## 6. Attribution — leave it to gather

Shipped 23–24 Oct: inline forms on the country pages, all 38 blog posts and the
service pages; `?from=` on 251 nav links; `country` stamped on the UK and
Germany forms; WhatsApp with per-page context on 101 pages.

Before this, **47 of 82 leads recorded `source_page` as `/#contact`** — the
homepage form, reached by clicking through from somewhere else. The origin was
destroyed at the hand-off, which made it look as though 99 pages produced
nothing and only the homepage converted. That was never true.

- [ ] **Do not draw conclusions for a month.** By late November the data will
      name the pages that actually sell, and that is what should decide where
      content effort goes next.
- [ ] **`country` is populated on 2 of 82 historic rows.** New enquiries from
      the UK and Germany pages now carry it; other pages still do not.

---

## 7. UK site (`Documents/fabrioza-uk`)

- [ ] **All 14 settings are empty** — moq, lead_time, free_services,
      legal_name, company_number, vat_number, phone, email, certificates,
      sample_policy and more. The build deliberately refuses to print a claim
      you have not supplied.
- [ ] **`fabrioza.co.uk` is not purchased.**
- [ ] **Decide: merge or separate.** Recommendation stands — `uk.fabrioza.com`
      needs zero code changes and inherits domain authority. A cold `.co.uk`
      cannot rank by January.
- [ ] **Deadline:** UK schools order leavers hoodies Jan–April. To rank for the
      2027 cycle, content must be live by **early December**.
- [ ] Note: the main site now carries the **0% UK duty (DCTS)** story, verified
      on GOV.UK. Whatever happens to the UK site, that advantage is already
      live and is the strongest UK-specific thing you have.

---

## 8. Housekeeping

- [ ] **The KWG photographs are still in public git history.** 60 files in
      `259bb43`; deleting them from the working tree does not remove them from
      earlier commits. Purging needs a history rewrite and force push.
- [ ] **Revoke the exposed Gmail App Password** `pvxvyboouqdqvsez`.
- [ ] **25 distinct navs across the 38 pages that have one**, and 63 pages have
      no nav at all. That is why `/process` and `/faq` could not simply be added
      to the menu. Consolidating to one shared nav is its own job and would make
      every future sitewide change cheaper.
- [ ] **Semrush has no API units** — blocks volume-led keyword work.
- [ ] If the portfolio ever returns: written permission from Kool World Global,
      House of A&D and DAY first.

---

## Closed on 24 Oct 2026

Each of these was open in the previous version of this file.

| item | commit |
|---|---|
| `/process` and `/faq` built as real pages (were 301s to homepage anchors) | `d1d9891` |
| WhatsApp button 5 → 101 pages, with per-page context (99 at the time, plus /process and /faq) | `073fa2d`, `5ee54dc` |
| amfori BSCI removed — including 6 places inside the SPA bundle the earlier sweep never searched | `77ecb68`, `4e5dac4` |
| "20-piece trial credited toward bulk" → the **premium** is credited, 33 statements across 13 files | `6d5f17a`, `27ce281` |
| "We will remake the sample free of charge" removed, plus both guarantee cards | `b811396`, `3f9272f` |
| Free-sample claims removed — 14 more found in the bundle and a comparison table after the first sweep reported zero | `fcc696a`, `a470d73`, `c867a3a`, `c14e061` |
| "500+ brands/clients" removed from 15 places; ~20 quantity uses of "500+" deliberately kept | `77ecb68`, `4e5dac4` |
| "99.5% first-pass rate" removed (the site also said 99.4%) | `bd0f3f6` |
| Three named client case studies stripped from the bundle — rendered nowhere, but readable in a public file | `bd0f3f6` |
| Inline enquiry forms on UK, Germany, 38 blog posts | `5e12055`, `f2b5477`, `6e8dbf5` |
| `?from=` attribution on 251 nav links + homepage reads it | `88b7e13` |
| UK page: 0% DCTS duty replacing a wrong "~12% apparel duty" | `5dd9aea` |
| Germany page: GSP+ with the origin caveat, deliberately not promising 0% | `85fb0a6` |
| Sitemap 96 → 102; 91 stale `lastmod` dates corrected; 4 orphaned pages added | `7c29bf1`, `5066281`, `69afb5e` |
| Third-party inspection, fabric substitution, swatches and MOQ-per-colourway answered | `f56deb6` |

---

## A note on how three of those were found

Worth keeping, because the same mistake will happen again otherwise.

Four times this week a sweep reported "zero remaining" and was wrong, each
time because the search could not see the thing it was searching for:

- **`FREE` split across two `<td>` cells** in the sampling comparison table.
  A tag-bounded regex cannot match across an element boundary.
- **Claims compiled into `dist/assets/*.js`.** Sweeps globbed `*.html` and
  `*.txt`. The homepage *is* the bundle, so the homepage was never checked.
- **Claims split by inline tags** — `<a>20-piece trial orders</a> credited
  toward bulk` reads as one sentence and exists as two strings.
- **Data split across object fields** — `end:500` and `label:"Clients"` in an
  animated counter. No text search for "500+ clients" could ever match.

**The method that works:** strip tags first, then match, then map back to the
raw string to make the edit — and include `*.js` and the JSON-LD and meta
attributes, which tag-stripping removes. Then load the rendered page and look
at it. Three of the four above were caught by a screenshot, not a grep.
