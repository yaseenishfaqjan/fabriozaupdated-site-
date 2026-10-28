# Trust, reviews and backlinks

Measured 28 Oct 2026 against the live site and Google's own documentation.
Read the Trustpilot section before setting it up — the obvious plan is the one
Google disallows.

---

## What the site has today

| | count |
|---|---|
| reviews, testimonials or `AggregateRating` anywhere in 90 pages | **0** |
| pages mentioning a company registration number | **0** |
| pages mentioning money-back terms | **0** |
| pages mentioning a refund | 1 — and it is a FAQ about *sample* fees |
| pages mentioning escrow or payment protection | 1 — and it describes **Alibaba's** |

That last row is the important one. The only place the site discusses payment
protection is a blog paragraph explaining that marketplaces offer
"escrow-style payment protection on some platforms". So the copy reminds a UK
buyer that Alibaba protects their money, and says nothing about what happens
to theirs. Nothing on the site answers "what stops you taking my £3,000 and
disappearing".

Certifications are handled correctly — all 69 pages describe ISO 9001 / BSCI /
OEKO-TEX **generically, as buyer advice**, and never claim them for Fabrioza.
One page contradicted that and was fixed today (see the last section).

---

## Trustpilot: what it does, and the thing it cannot do

From Google's own review-snippet documentation, read 28 Oct 2026:

> "their pages that use LocalBusiness or any other type of Organization
> structured data are ineligible for star review feature"

and that exclusion applies **"even when reviews come through an embedded
third-party widget"**. Separately:

> "Don't aggregate reviews or ratings from other websites."

Two consequences, both counter-intuitive:

- **Embedding the Trustpilot widget on fabrioza.com will never produce stars
  in Google's organic results.** Not a configuration problem and not a schema
  problem — the page type is ineligible because we control the reviews about
  ourselves, and the widget is explicitly named as not a way round it.
- **Copying our Trustpilot score into our own JSON-LD is a guideline
  violation**, not a shortcut. Do not do it. 87 pages carry `Organization`
  schema and adding `aggregateRating` to any of them buys nothing and risks a
  manual action.

So Trustpilot is worth doing, but **not for SEO**. It is worth doing for three
other reasons, in order of value to this business:

1. **Brand defence.** A UK buyer about to send money to a supplier in Sialkot
   searches "fabrioza reviews" first. Right now that search returns nothing we
   own. A Trustpilot profile ranks for it, and an empty-but-real profile beats
   a blank. This is the main reason.
2. **On-page conversion.** Real quotes beside the quote form, with a name and a
   country, address the only objection that matters. No search value, real
   conversion value.
3. **Google Seller Ratings in Ads** — a *different* system from organic stars,
   and Trustpilot is a licensed partner for it. It needs a minimum review
   volume in a rolling window; check the current threshold with Google before
   planning around it rather than trusting a number from a blog.

### The legitimate route to stars

`Product` is on Google's eligible list. 16 pages already carry `Product`
schema (`/premium-hoodies`, `/football-kits`, `/medical-scrubs`, …). First-party
reviews collected **on fabrioza.com, about a specific product**, are eligible.

That is the real path, and it is sequenced last on purpose: building a review
display and `aggregateRating` before a single review exists is backwards, and
an `aggregateRating` with a fabricated count is the one mistake in this area
that cannot be walked back.

### Setup, in order

1. Claim the free Trustpilot business account on `fabrioza.com`. Domain
   verification is a DNS TXT record or a file upload — the same drill as the
   Bing verification done on 27 Oct.
2. Fill the profile properly: logo, the Sialkot address, what Fabrioza makes,
   MOQ. A thin profile reads as abandoned.
3. Send Email 1 below to every completed order. Do **not** use a review-gating
   flow (asking how happy they are first and only inviting the happy ones) —
   Trustpilot prohibits it and it is detectable.
4. Once there are reviews, quote them on the site as plain HTML with name and
   country. No schema. The quotes convert; the schema is not eligible.
5. Revisit `Product` + first-party `aggregateRating` only when real
   per-product reviews exist.

---

## Email 1 — review request

Send a few days after delivery, when they can see the goods. One ask, one link.

> **Subject:** How did the order turn out?
>
> Hi {first name},
>
> Your {product} order shipped on {date} and should be with you by now. I
> wanted to check it arrived as expected — if anything is off, reply to this
> email and I will sort it.
>
> If it did land well, would you leave a short review? Most of the brands who
> find us are weighing up whether to trust a factory they have never visited,
> and a line from someone who has actually received an order is worth more
> than anything I can write about ourselves.
>
> {Trustpilot link}
>
> Two minutes, and it genuinely helps.
>
> Thanks,
> Yasin
> FABRIOZA — info@fabrioza.com

Why it is shaped that way: it opens with service, not with the ask, so a
dissatisfied customer replies to **you** instead of venting publicly — which
is the correct outcome and is not gating, because nobody is screened out of
the review link.

---

## Email 2 — backlink request

Only to customers whose own site is live and who are visibly happy. Never buy
links — Fiverr packages, guest-post networks and PBNs all risk a manual
action, and Bing's "lacks inbound links from high-quality domains" notice is a
generic site-level prompt, not a defect to pay your way out of.

> **Subject:** Quick favour — happy to return it
>
> Hi {first name},
>
> Glad the {product} run worked out.
>
> If you list suppliers or manufacturing partners anywhere on {their site} —
> an about page, a "how it is made" page, a sustainability page — would you
> mind including us? A plain link to fabrioza.com is perfect.
>
> Happy to do the same for you: I can add {their brand} to our client list
> with a link, if you would like the exposure.
>
> No problem either way.
>
> Yasin
> FABRIOZA

The reciprocal offer is deliberate and worth more than the link itself: a
named, linked customer brand on fabrioza.com is the trust signal the site
currently has none of.

---

## The biggest gap, and what I need from you to close it

Reviews and Trustpilot both depend on customers acting. The risk-reversal copy
does not — it is a page I can write today, and it addresses the objection that
is losing the most UK leads. But every sentence in it has to be **true**, and
these are facts I do not have. Answer these and I will write it:

1. Is Fabrioza a registered company, and where? Registration number?
2. How long has it been trading? From what year?
3. What payment terms do you actually offer — deposit / balance split? What
   percentage up front?
4. What payment methods? Is there anything a UK buyer can charge back (card,
   PayPal, Wise), or is it bank transfer only?
5. If a bulk order arrives not matching the approved sample, what happens?
   Remake, refund, partial credit — what have you actually done when it
   happened?
6. Any audit or certification you can produce a **number** for?
7. Can a buyer video-call the factory before ordering?

Answers to 3, 4 and 5 alone would let me write the single highest-value page
on the site. Do not guess at them for my benefit — an invented guarantee is
worse than a missing one, and the site's own guide tells buyers to verify
claims.

---

## Ranked, honestly

| # | action | who | blocked on |
|---|---|---|---|
| 1 | Risk-reversal "how we protect your order" page | me | your answers above |
| 2 | Named customer logos + linked brands on site | me | permission from 2-3 customers |
| 3 | Trustpilot profile claimed and filled | you | ~30 minutes |
| 4 | Email 1 to every past completed order | you | the Trustpilot link |
| 5 | Email 2 to 3-5 happy customers with live sites | you | — |
| 6 | Quote real reviews on site (no schema) | me | reviews existing |
| 7 | `Product` + first-party `aggregateRating` | me | per-product reviews existing |

Items 1 and 2 need neither Trustpilot, nor Google, nor anyone outside the
company — and they beat everything below them. That is the thing to notice.

---

## Fixed today on `/sustainable-fashion`

Three claim problems, all on one page:

- A hero stat read **"100% / Certified"** — an unverifiable claim about
  Fabrioza itself, with no certificate named anywhere on the site, on a site
  whose own guide says "an unverifiable claim is worthless — ask for the
  certificate number". **Removed**, not softened. `.hero-stats` is
  `flex`/`justify-content:center`, so the remaining three still centre.
- A bar headed **"Certifications Available:"** listed Organic Cotton,
  Recycled Polyester, Hemp, Bamboo and Tencel — which are **fibres, not
  certifications** (GOTS is the certification; organic cotton is the
  material). Mislabelling them invites the certificate-number question the
  page cannot answer. Now reads "Sustainable Fabrics Available:".
- The stat said "6 Green Fabrics" while the bar listed 5 on the same screen.
  The stat was right — the nav groups "Hemp & Linen" — so **Linen** was added
  to the bar. Both now read 6.
