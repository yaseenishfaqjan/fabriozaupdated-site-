#!/usr/bin/env bash
# Verify that what is on fabrioza.com is what is in this repo.
#
# A deploy that "succeeded" is not a deploy that landed. This checks the
# live site for the specific strings and files each change introduced,
# rather than trusting an exit code, and it fails loudly if the VPS is on
# a different commit from the checkout you are standing in.
#
#   bash tools/verify-live.sh            # check production
#   bash tools/verify-live.sh --deploy   # pull + rebuild on the VPS first
#
# Exit 0 only when every check passes.

set -uo pipefail

# Resolved from this script's own path so the checks that read repo files
# work from any working directory, not just the repo root.
REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SITE="https://fabrioza.com"
VPS="root@209.145.55.76"
PASS=0; FAIL=0
g() { printf '  \033[32mPASS\033[0m  %s\n' "$1"; PASS=$((PASS+1)); }
b() { printf '  \033[31mFAIL\033[0m  %s\n' "$1"; FAIL=$((FAIL+1)); }
hdr() { printf '\n\033[1m%s\033[0m\n' "$1"; }

# ssh here is occasionally flaky, and one dropped connection reported "VPS
# is NOT on this commit" against a VPS that was perfectly in sync. A false
# red trains you to ignore the script, which costs more than the check is
# worth, so every remote call gets three attempts before it counts.
sshq() {
  local i out
  for i in 1 2 3; do
out=$(ssh -o ConnectTimeout=15 -o BatchMode=yes "$VPS" "$1")
    [ -n "$out" ] && { printf '%s' "$out"; return 0; }
    sleep 2
  done
  return 1
}

# page cache: fetch each page once, reuse
TMP=$(mktemp -d); trap 'rm -rf "$TMP"' EXIT
# NOTE: the two assignments below must be separate statements. "local a=$1
# b=$(... $a ...)" expands every argument BEFORE assigning any of them, so
# the second one reads an unset variable - which under set -u emptied the
# filename and made every check read a directory instead of a page.
page() {  # page <path>  -> prints the local file holding that page body
  local p="$1"
  local f
  f="$TMP/$(printf '%s' "$p" | tr '/' '_')"
# -L matters here. fabrioza.com canonicalises to the NO-SLASH form, so any
# path written with a trailing slash 301s. Without -L curl saves the 366-byte
# redirect stub, and because that stub is NOT empty, fetched() is satisfied -
# so "lacks" greps the stub, finds nothing, and reports PASS. A check that
# cannot fail. (Measured 28 Oct 2026 on /sustainable-fashion/ while verifying
# a claim removal: all three greps returned 0 against the stub.) code() is
# deliberately left WITHOUT -L, so it still asserts the status of the URL as
# written rather than the status at the end of a chain.
  [ -s "$f" ] || curl -fsSL --max-time 30 "$SITE$p" -o "$f" 2>/dev/null || : >"$f"
  printf '%s' "$f"
}
# An unfetchable page must FAIL both ways. The first version let "lacks"
# pass on an empty body, so a site that was down scored green.
fetched() { [ -s "$(page "$1")" ]; }
has()  {
  fetched "$1" || { b "$3 (page not fetched)"; return; }
  grep -qF -- "$2" "$(page "$1")" && g "$3" || b "$3"
}
lacks(){
  fetched "$1" || { b "$3 (page not fetched)"; return; }
  grep -qF -- "$2" "$(page "$1")" && b "$3" || g "$3"
}
code() {
  local c; c=$(curl -s -o /dev/null -w '%{http_code}' --max-time 30 "$SITE$1")
  [ "$c" = "$2" ] && g "$3 ($c)" || b "$3 (got $c, want $2)"
}

if [ "${1:-}" = "--deploy" ]; then
  hdr "Deploying"
  # Changed assets behind an unchanged cache-buster reach nobody who has
  # visited before. assets/index-*.js is served max-age=31536000 + immutable
  # with a filename that never changes, so the ?v= query in index.html is the
  # only versioning there is. On 2 Nov 2026 five changes shipped into that
  # bundle, the suite reported 76/76, and a warm cache still served the old
  # copy. Checked BEFORE the pull, because afterwards local and VPS match and
  # the evidence is gone.
  vps_before=$(ssh -o ConnectTimeout=20 -o BatchMode=yes "$VPS" \
      'cd /opt/fabrioza && git rev-parse HEAD' 2>/dev/null || echo '')
  if [ -n "$vps_before" ] && git cat-file -e "$vps_before^{commit}" 2>/dev/null; then
    if ! git diff --quiet "$vps_before" HEAD -- "$REPO/dist/assets/" 2>/dev/null; then
      q_old=$(git show "$vps_before:dist/index.html" 2>/dev/null \
              | grep -o 'assets/index-[^"]*\.js?v=[^"]*' | head -1)
      q_new=$(grep -o 'assets/index-[^"]*\.js?v=[^"]*' "$REPO/dist/index.html" | head -1)
      if [ "$q_old" = "$q_new" ]; then
        printf '\n  \033[31mREFUSING TO DEPLOY\033[0m  dist/assets/ changed but the cache-buster did not.\n'
        printf '    still: %s\n' "$q_new"
        printf '    That file is served max-age=31536000, immutable, and its name never\n'
        printf '    changes - so every returning visitor would keep the old copy and this\n'
        printf '    deploy would reach new traffic only.\n'
        printf '    Bump the ?v= in dist/index.html, or give the build content-hashed\n'
        printf '    filenames so this stops being manual.\n\n'
        exit 1
      fi
      printf '  asset cache-buster bumped: %s -> %s\n' "${q_old##*?v=}" "${q_new##*?v=}"
    fi
  fi

  git push origin HEAD || { echo "push failed"; exit 1; }

  # pipefail is set, but nothing examined this pipeline's status, so a failed
  # pull or a broken image build used to scroll past inside tail's four lines
  # and the script went on to "verify" a deploy that never happened - then
  # reported PASS on 73 checks describing the OLD content. Capture, check,
  # then print.
  if ! deploy_out=$(ssh -o ConnectTimeout=20 -o BatchMode=yes "$VPS" \
        'cd /opt/fabrioza && git pull --ff-only && docker compose up -d --build' 2>&1); then
    printf '%s\n' "$deploy_out" | tail -15 | sed 's/^/  /'
    printf '\n  \033[31mDEPLOY FAILED\033[0m  the VPS was not updated, so nothing below would mean anything.\n\n'
    exit 1
  fi
  printf '%s\n' "$deploy_out" | tail -4 | sed 's/^/  /'

  # CLAUDE.md asks for this after any SEO-relevant deploy, and it was missed
  # every time on 24 Oct, so it runs here rather than relying on memory. One
  # POST tells Bing and Yandex to recrawl - and Bing is what ChatGPT
  # searches, which is where 15 of the 16 attributed leads came from.
  #
  # indexnow.php is a web PAGE, not a command: it exits 0 whatever happens
  # and the outcome is only in its text. On 1 Nov 2026 it printed
  # "HTTP status: 0" and this script still finished "73 passed, 0 failed" -
  # Bing had been told nothing. Match the success string and route it
  # through b() so it reaches the tally and the exit code.
  #
  # One retry, because that failure was transient and the immediate retry
  # returned 200 - same reasoning as sshq() above. It still fails if both
  # attempts fail; the retry removes false reds, it does not hide a real one.
  hdr "IndexNow"
  idx=""
  for attempt in 1 2; do
    idx=$(curl -s --max-time 120 "$SITE/indexnow.php" 2>&1)
    case "$idx" in *"SUCCESS:"*) break ;; esac
    [ "$attempt" = "1" ] && sleep 5
  done
  printf '%s\n' "$idx" | sed 's/^/  /'
  case "$idx" in
    *"SUCCESS:"*)
      g "IndexNow accepted the submission" ;;
    *)
      b "IndexNow did NOT accept it - Bing will not recrawl until this is re-run" ;;
  esac
fi

hdr "Commit in sync"
LOCAL=$(git rev-parse HEAD)
REMOTE=$(sshq 'cd /opt/fabrioza && git rev-parse HEAD' || echo '')
printf '  local  %s\n  vps    %s\n' "${LOCAL:0:12}" "${REMOTE:0:12}"
[ -n "$REMOTE" ] && [ "$LOCAL" = "$REMOTE" ] && g "VPS is on this commit" \
  || b "VPS is NOT on this commit (unpushed work, or the pull did not run)"
[ -z "$(git status --porcelain)" ] && g "working tree clean" \
  || b "working tree has uncommitted changes - they are not live"

hdr "Container"
sshq "docker ps --filter name=fabrioza-web --format '{{.Status}} | image {{.Image}}'" | sed 's/^/  /'
UP=$(sshq "docker ps -q --filter name=fabrioza-web" || echo '')
[ -n "$UP" ] && g "fabrioza-web is running" || b "fabrioza-web is NOT running"

hdr "Configurator photos"
N=$(sshq "docker exec fabrioza-web sh -c 'ls /var/www/html/images/design/*.webp | wc -l'" | tr -d ' ')
[ "$N" = "280" ] && g "280 product photos in the container" || b "expected 280 photos, found '${N:-none}'"
code /images/design/pullover-hoodie--black--back.webp 200 "a back view serves"
code /images/design/club-tracksuit--royal-blue.webp   200 "a front view serves"

hdr "Configurator behaviour"
has  /design '"front":{'                     "placement map has a front view"
has  /design '"back":{'                      "placement map has a back view"
has  /design 'long-sleeve-tee'               "long-sleeve tee present"
has  /design '"shape":"tee"}]'               "long-sleeve tee is shaped as a tee, not a hoodie"
has  /design 'accept=".png,.jpg,.jpeg,.svg,.pdf"' "upload accepts PDF tech packs"
has  /design 'enter GSM'                   "custom GSM option"
has  /design 'dzGsmOther'                  "custom GSM input field"
has  /design 'Describe the technique'      "custom technique option"
has  /design 'dzTechOther'                 "custom technique input field"
has  /design 'This is a preview, not a photograph of your order' "preview disclaimer is the corrected one"
lacks /design 'This is an illustration, not a photograph'        "old disclaimer gone"

has  /design 'GARMENT_BOX'                 "drag is clamped to measured garment outlines"
has  /design 'dz-seg'                      "Front/Back toggle present"
has  /design 'dzSize'                      "logo size control present"
has  /design 'Drag the logo on the preview' "drag hint present"
has  /design 'pointermove'                 "drag handler shipped"
has  /design 'dzRot'                       "rotation control present"
has  /design 'dzReset'                     "artwork reset button present"
has  /design 'Math.sin(t)'                 "clamp allows for the rotated extent"

hdr "Import duty figures"
has  /clothing-manufacturer-usa       '26.5% on cotton knits'  "USA: 26.5% incl. Section 301"
lacks /clothing-manufacturer-usa      '~16.5% on cotton knits' "USA: pre-July-2026 rate gone"
has  /blog/tshirt-manufacturing-cost  '26.5%'                  "t-shirt guide: duty updated"
has  /blog/tshirt-manufacturing-cost  '$12.23 landed'          "t-shirt guide: example re-costed"
has  /clothing-manufacturer-canada    '18% duty'               "Canada: 18% on knitwear"
has  /clothing-manufacturer-canada    'GPT preference scheme'  "Canada: GPT exclusion explained"
has  /clothing-manufacturer-canada    'input tax credit'       "Canada: GST recoverable"
has  /clothing-manufacturer-australia '5% apparel duty'        "Australia: 5% duty"
has  /clothing-manufacturer-australia 'input tax credit'       "Australia: GST recoverable"
has  /blog/clothing-manufacturer-australia-brands '5% apparel duty' "Australia blog: duty added"
has  /clothing-manufacturer-uae       'import VAT'             "UAE: 5% VAT added"
has  /clothing-manufacturer-uae       'free zone'              "UAE: free-zone note"
has  /blog/pakistan-vs-china-vs-bangladesh-vs-vietnam-vs-india 'MFN + 12.5%' "5-way table: real Section 301 tiers"

hdr "Markup repairs"
for p in /blog/hoodie-brand-guide /blog/what-is-moq-clothing /blog/oem-odm-clothing-manufacturer; do
  lacks "$p" '</h4><td>' "no stray table tag in the FAQ on $p"
done
for p in /blog/pakistan-vs-bangladesh-clothing-manufacturing /blog/clothing-manufacturer-vs-wholesale; do
  lacks "$p" '%%' "no literal double-percent on $p"
done

hdr "FAQ structured data"
for p in /blog/tech-pack-clothing-guide /process /streetwear-manufacturer /custom-hoodie-manufacturer; do
  has "$p" '"FAQPage"' "FAQPage schema on $p"
done

hdr "Page targeting"
# these three led with phrases Google does not recognise as queries
has  /team-uniform-manufacturer 'Sports, School & Staff Uniform Manufacturer' "team page retargeted"
lacks /team-uniform-manufacturer '<title>Team Uniform Manufacturer'   "old team title gone"
has  /sampling-and-moq 'How Much Does a Clothing Sample Cost'          "sampling page retargeted"
has  /sportswear-catalog 'Sportswear Catalogue'                        "catalogue page retargeted"
lacks /sportswear-catalog '<title>Custom Sportswear Manufacturer'      "title no longer clashes with /custom-sportswear-manufacturer"
has  /custom-sportswear-manufacturer '<title>Custom Sportswear Manufacturer' "/custom-sportswear-manufacturer still owns its phrase"

# the UK landing page and its blog post both led with the same phrase
has  /clothing-manufacturer-uk '<title>Clothing Manufacturer for UK Brands' "UK landing page keeps the commercial phrase"
has  /blog/clothing-manufacturer-uk-brands 'Importing Clothes from Pakistan to the UK' "UK blog post retargeted"
lacks /blog/clothing-manufacturer-uk-brands 'Clothing Manufacturer for UK Brands' "UK blog post no longer competes"

has  /clothing-manufacturer-usa '<title>Clothing Manufacturer for USA Brands' "USA landing page keeps the commercial phrase"
has  /blog/clothing-manufacturer-usa-brands 'US Clothing Import Duty' "USA blog post retargeted"
lacks /blog/clothing-manufacturer-usa-brands 'Clothing Manufacturer for USA Brands' "USA blog post no longer competes"
has  /clothing-manufacturer-australia '<title>Clothing Manufacturer for Australian Brands' "AU landing page keeps the commercial phrase"
has  /blog/clothing-manufacturer-australia-brands 'Importing Clothing to Australia' "AU blog post retargeted"
lacks /blog/clothing-manufacturer-australia-brands 'Clothing Manufacturer for Australian Brands' "AU blog post no longer competes"

# UK product pages. They exist to cover "<product> manufacturer uk", which
# neither parent page targets - /custom-hoodie-manufacturer has no UK angle
# and /clothing-manufacturer-uk has no product angle. They must NOT take the
# phrase the UK landing page owns, or all three compete for one query.
has   /hoodie-manufacturer-uk '<title>Hoodie Manufacturer for UK Brands'   "UK hoodie page owns its phrase"
lacks /hoodie-manufacturer-uk 'Clothing Manufacturer for UK Brands'        "UK hoodie page does not compete with the UK landing page"
has   /t-shirt-manufacturer-uk '<title>T-Shirt Manufacturer for UK Brands' "UK t-shirt page owns its phrase"
lacks /t-shirt-manufacturer-uk 'Clothing Manufacturer for UK Brands'       "UK t-shirt page does not compete with the UK landing page"

# The MOQ in the hero is inherited from the template and was wrong on the
# first build: it read "MOQ 50 pieces" on a site whose every headline says
# 20, which is the same contradiction that made the enquiry form unusable.
lacks /hoodie-manufacturer-uk 'MOQ 50 pieces'                              "UK hoodie page hero does not contradict the 20-piece MOQ"
lacks /t-shirt-manufacturer-uk 'MOQ 50 pieces'                             "UK t-shirt page hero does not contradict the 20-piece MOQ"

# Prices are parsed from /pricing at build time. If someone edits the price
# list and not these pages, the site quotes two different numbers for one
# garment - so a spot price from each table is pinned to the source.
has   /hoodie-manufacturer-uk '$19.20'                                     "UK hoodie page carries the live 20-piece price"
has   /pricing '$19.20'                                                    "...and /pricing still agrees with it"
has   /t-shirt-manufacturer-uk '$11.40'                                    "UK t-shirt page carries the live 20-piece price"
has   /pricing '$11.40'                                                    "...and /pricing still agrees with it"
has   /tracksuit-manufacturer-uk '<title>Tracksuit Manufacturer for UK Brands'   "UK tracksuit page owns its phrase"
lacks /tracksuit-manufacturer-uk 'Clothing Manufacturer for UK Brands'          "UK tracksuit page does not compete"
lacks /tracksuit-manufacturer-uk 'MOQ 50 pieces'                                "UK tracksuit hero does not contradict the 20-piece MOQ"
has   /tracksuit-manufacturer-uk '$26.40'                                       "UK tracksuit page carries the live 20-piece price"
has   /pricing '$26.40'                                                         "...and /pricing still agrees with it"

has   /streetwear-manufacturer-uk '<title>Streetwear Manufacturer for UK Brands' "UK streetwear page owns its phrase"
lacks /streetwear-manufacturer-uk 'Clothing Manufacturer for UK Brands'          "UK streetwear page does not compete"
lacks /streetwear-manufacturer-uk 'MOQ 50 pieces'                                "UK streetwear hero does not contradict the 20-piece MOQ"
has   /streetwear-manufacturer-uk '$13.20'                                       "UK streetwear page carries the live 20-piece price"
has   /pricing '$13.20'                                                          "...and /pricing still agrees with it"
# Streetwear covers several garments, so it must NOT claim a single code -
# naming one would be a wrong answer to the question a UK buyer is asking.
has   /streetwear-manufacturer-uk 'spans several commodity codes'                "UK streetwear page does not claim one commodity code"

# every UK product page must be reachable, not an orphan
has   /sitemap.xml 'hoodie-manufacturer-uk'                                      "UK hoodie page is in the sitemap"
has   /sitemap.xml 't-shirt-manufacturer-uk'                                     "UK t-shirt page is in the sitemap"
has   /sitemap.xml 'tracksuit-manufacturer-uk'                                   "UK tracksuit page is in the sitemap"
has   /sitemap.xml 'streetwear-manufacturer-uk'                                  "UK streetwear page is in the sitemap"
has   /clothing-manufacturer-uk 'hoodie-manufacturer-uk'                         "UK landing page links its product pages"

# THE SITE MUST NOT CONTRADICT ITS OWN MOQ. Every country landing page
# opened with "MOQ 50 pieces" while the homepage title, the hero, the
# product cards and the enquiry form all say 20 - and /moq-calculator, a
# page named for the question, never mentioned 20 at all. 50 is still
# correct where it appears as the BULK threshold, which is why these check
# the hero claim rather than the number.
lacks /clothing-manufacturer-uk 'MOQ 50 pieces'          "UK page hero does not claim a 50-piece minimum"
lacks /clothing-manufacturer-usa 'MOQ 50 pieces'         "USA page hero does not claim a 50-piece minimum"
lacks /clothing-manufacturer-australia 'MOQ 50 pieces'   "AU page hero does not claim a 50-piece minimum"
lacks /clothing-manufacturer-canada 'MOQ 50 pieces'      "CA page hero does not claim a 50-piece minimum"
lacks /clothing-manufacturer-germany 'MOQ 50 pieces'     "DE page hero does not claim a 50-piece minimum"
lacks /clothing-manufacturer-uae 'MOQ 50 pieces'         "UAE page hero does not claim a 50-piece minimum"
has   /clothing-manufacturer-uk 'MOQ from 20 pieces'     "UK page hero states the real minimum"
lacks /moq-calculator 'low MOQ of 50 pieces'             "/moq-calculator does not state 50 as the minimum"
has   /moq-calculator '20 pieces'                        "/moq-calculator states the real minimum"
has   /sitemap.xml 'precios-fabricacion-ropa'            "the Spanish price list is in the sitemap"

hdr "Breadcrumbs"
# 16 posts linked "Blog" to the homepage while their own JSON-LD said /blog
for p in /blog/hoodie-brand-guide /blog/what-is-moq-clothing /blog/tech-pack-clothing-guide /blog/pakistan-vs-china-clothing; do
  lacks "$p" '<a href="/">Blog</a>' "breadcrumb Blog link points at /blog on $p"
done

hdr "Attribution"
for p in / /blog/tech-pack-clothing-guide /clothing-manufacturer-uk /pricing; do
  has "$p" 'fabAttribution=function' "first-touch capture on $p"
done
has /clothing-manufacturer-uk 'fabAttribution(body)' "inline form attaches attribution"
# Derived, never hard-coded. This read `grep -q '^5$'` and went red the
# moment migration 6 landed, reporting a fault on a database that was
# perfectly correct - the same stale-constant trap as the "want 9" and
# "want 11" checks before it. db.php declares the latest migration, so
# ask db.php.
want_ver=$(grep -o 'PRAGMA user_version = [0-9]*' "$REPO/dist/api/db.php" | grep -o '[0-9]*$' | sort -n | tail -1)
live_ver=$(sshq "docker exec fabrioza-web php -r 'echo (new PDO(\"sqlite:/var/data/fabrioza_crm.db\"))->query(\"PRAGMA user_version\")->fetchColumn();'" || echo '')
if [ -n "$want_ver" ] && [ "$live_ver" = "$want_ver" ]; then
  g "CRM schema is at migration $want_ver (matches db.php)"
else
  b "CRM schema is at '${live_ver:-unreadable}' but db.php declares '${want_ver:-?}'"
fi

hdr "Lead alerts (server side)"
sshq "docker exec fabrioza-web grep -c crm_notify_whatsapp /var/www/html/api/db.php" >/dev/null 2>&1 && g "WhatsApp fan-out is in the deployed db.php" || b "WhatsApp fan-out missing from the deployed db.php"

hdr "Assets"
# The deploy can land while the asset the page actually requests is stale or
# missing - a 404 here is invisible on the server and blanks the React app.
# Compare what the live HTML asks for against the file in this checkout.
asset_ref=$(curl -fsSL --max-time 30 "$SITE/" 2>/dev/null \
            | grep -o 'assets/index-[^"]*\.js[^"]*' | head -1)
if [ -z "$asset_ref" ]; then
  b "could not read the asset reference from the live homepage"
else
  asset_code=$(curl -s -o /dev/null -w '%{http_code}' --max-time 30 "$SITE/$asset_ref")
  if [ "$asset_code" = "200" ]; then
    live_md5=$(curl -fsSL --max-time 60 "$SITE/$asset_ref" 2>/dev/null | md5sum | cut -c1-32)
    repo_md5=$(md5sum "$REPO/dist/${asset_ref%%\?*}" 2>/dev/null | cut -c1-32)
    if [ -n "$live_md5" ] && [ "$live_md5" = "$repo_md5" ]; then
      g "the bundle the homepage requests is the one in this checkout (?v=${asset_ref##*?v=})"
    else
      b "the live bundle differs from this checkout (live $live_md5 vs repo $repo_md5)"
    fi
  else
    b "the bundle the homepage requests returns $asset_code - the app will not boot"
  fi
fi

hdr "Automated mail (cron)"
# Nothing watched these until 1 Nov 2026. process-sequences.php and
# daily-digest.php each opened their own PHPMailer straight to SMTP instead
# of going through fab_send, so neither could ever reach Resend. Both failed
# every morning for over a week - leads #93, #96 and #97 never got their
# day-3 nudge and six consecutive digests never arrived - while this suite
# reported 74 passed, 0 failed.

# 1. Structural: no sender may bypass fab_send. mailer.php is the one
#    legitimate exception, because smtpSend() lives there as the fallback.
#    This is the check that stops a third sender repeating the mistake.
bypass=$(sshq "docker exec fabrioza-web sh -c 'cd /var/www/html/api && grep -l -- \"->isSMTP()\" *.php 2>/dev/null | grep -v \"^mailer.php\$\" | tr \"\\n\" \" \"'" || true)
# trim only the ends - tr -d ' ' would glue two filenames together and make
# the failure message unreadable at exactly the moment it matters
bypass=$(printf '%s' "$bypass" | sed 's/^[[:space:]]*//; s/[[:space:]]*$//')
if [ -z "$bypass" ]; then
  g "every api mailer goes through fab_send (Resend, SMTP fallback)"
else
  b "these bypass fab_send and can never reach Resend: $bypass"
fi

# 2. Behavioural: is mail working NOW. Deliberately "the newest row is not a
#    failure" rather than "no failures in 48h" - the second stays red for a
#    fault already fixed, and a check that cries wolf gets ignored. The 48h
#    counts print either way so intermittent failures remain visible.
#    The query is in dist/api/mail-health.php, not inlined, because nesting
#    SQL through ssh -> docker -> php -r eats a level of quoting per layer.
mailstat=$(sshq "docker exec fabrioza-web php /var/www/html/api/mail-health.php" || true)
last_status=${mailstat%%|*}
sent48=$(printf '%s' "$mailstat" | cut -d'|' -f2)
failed48=$(printf '%s' "$mailstat" | cut -d'|' -f3)
printf '        last 48h: %s sent, %s failed  |  newest entry: %s\n' \
  "${sent48:-?}" "${failed48:-?}" "${last_status:-unreadable}"
case "$last_status" in
  sent)  g "mail transport is working (most recent send succeeded)" ;;
  empty) g "mail transport untested (email_log is empty)" ;;
  "")    b "could not read mail-health.php - cannot tell whether mail is working" ;;
  *)     b "the most recent email FAILED ($last_status) - cron mail is down" ;;
esac

hdr "Result"
printf '  %d passed, %d failed\n\n' "$PASS" "$FAIL"
[ "$FAIL" -eq 0 ] || exit 1
