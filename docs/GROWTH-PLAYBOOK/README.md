# Growth Playbook — SEO / AEO / GEO

One folder, one method, reusable on any site we run. Started 15 Nov 2026 on
**fabrioza.com**, which is the reference implementation: everything in
`CHECKLIST.md` was done there first and measured, and `RECORD-fabrioza.md`
holds the numbers.

## What the three letters mean here

| | stands for | the question it answers | where we measure it |
|---|---|---|---|
| **SEO** | Search Engine Optimization | does a *person searching* find us? | Search Console, Bing Search Performance |
| **AEO** | Answer Engine Optimization | are we the *answer box / FAQ snippet*? | SERP features, FAQPage schema coverage |
| **GEO** | Generative Engine Optimization | do *ChatGPT, Copilot, Perplexity* cite us? | Bing **AI Performance** tab, `llms.txt` hits |

They are not three projects. They are three audiences reading the same pages,
and they want the same thing: a page that answers one question completely,
marked up so a machine can read the answer without guessing.

## The order that matters

Doing these out of order wastes money. Each step is worthless until the one
above it is true.

1. **Can it be reached?** — 200, no redirect chain, not blocked, one canonical.
2. **Can it be measured?** — analytics on *every* page, search consoles verified.
3. **Can it be understood?** — title, description, one h1, schema.
4. **Does it deserve to rank?** — a page per thing we actually sell.
5. **Does anyone vouch for it?** — inbound links. This is the slow one.
6. **Does the visit turn into money?** — the enquiry form, the follow-up.

Fabrioza is solid on 1–3, has a named gap on 4, and 5 is its real bottleneck:
Bing's own top recommendation is *"Your site does not have enough inbound links
from high quality domains."*

## Files

| file | what it is |
|---|---|
| `CHECKLIST.md` | the repeatable list, with the command that proves each item |
| `RECORD-fabrioza.md` | measured state of fabrioza + what was changed and when |
| `SITES.md` | one-line status for every site we run |
| `../BING-WEBMASTER-SWEEP-PROMPT.md` | the 17-tool Bing sweep, paste-ready |

## The rule this whole folder exists to enforce

**Write down what you MEASURED, never what you assumed.** Every claim here
carries the command or the screen it came from. The expensive mistakes on this
project have all been the same shape — a thing that looked installed, looked
indexed, looked verified, and was not:

- Clarity sat on the homepage inside an HTML comment for weeks, wrapped around
  a `FABRIOZA_CLARITY_ID` placeholder. It looked present to a grep.
- A missing file returned **200** on peachpicks.app because the server falls
  back to the SPA shell — so did a filename invented on the spot.
- `peachpicks.app` showed **no "Not verified" label** in Bing while every tool
  answered *"User is unauthorized"*.
- A grep for `clarity.ms/tag/<id>` reported 0 of 163 installed immediately
  after installing 163 — the snippet builds that URL at runtime.

So: **always check with a control.** A test that cannot fail is not a test.
