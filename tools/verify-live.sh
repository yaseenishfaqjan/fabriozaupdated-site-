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
    # Scoped to the files that are actually AT RISK: the ones served
    # immutable under a name that never changes, which is index-*.
    # A brand-new file in dist/assets/ cannot be stale in anybody's cache -
    # nobody has a cached copy of a file that did not exist - and it carries
    # its own ?v= in its own tag. The first version of this guard diffed the
    # whole directory and refused a deploy that added product-detail.js,
    # where bumping the bundle's ?v= would have thrown away the 609 KB
    # bundle cache for every returning visitor to fix nothing.
    # RELATIVE pathspec, and the git exit code is inspected rather than
    # inverted. git-bash hands a Windows git binary a POSIX $REPO, which it
    # rejects: "fatal: Invalid path '/c/Users/...'", exit 128. The original
    # line was `if ! git diff --quiet ... 2>/dev/null`, so that 128 read as
    # "assets changed" and the check fell through to the ?v= comparison on
    # EVERY deploy - it never once compared what it claimed to. It looked
    # fine because the ?v= usually differed; the first deploy where it
    # matched refused for no reason.
    git diff --quiet "$vps_before" HEAD -- 'dist/assets/index-*'; asset_rc=$?
    if [ "$asset_rc" -gt 1 ]; then
      printf '  [31mcache-buster check could not run[0m (git exit %s) - not deploying blind.
' "$asset_rc"
      exit 1
    fi
    if [ "$asset_rc" -eq 1 ]; then
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
# Matched on the ELEMENT, not the bare string. The nav-less brandmark markup
# is gone, but the dead .fab-brandmark CSS rule still sits in these pages
# inline <style>, so grepping for the plain name reports a failure on a page
# that is correct.
lacks /moq-calculator 'low MOQ of 50 pieces'             "/moq-calculator does not state 50 as the minimum"
has   /moq-calculator '20 pieces'                        "/moq-calculator states the real minimum"
has   /sitemap.xml 'precios-fabricacion-ropa'            "the Spanish price list is in the sitemap"

# USA PRODUCT PAGES. The duty story is the OPPOSITE of the UK's and
# getting it wrong costs a customer real money: Pakistan has no US trade
# agreement, so cotton knits carry roughly 26.5% (16.5% MFN plus the 10%
# Section 301 tariff on Pakistani goods), while the UK is 0% under DCTS.
# These assert each page tells its own country the truth.
has   /hoodie-manufacturer-usa '<title>Hoodie Manufacturer for USA Brands'  "USA hoodie page owns its phrase"
has   /t-shirt-manufacturer-usa '<title>T-Shirt Manufacturer for USA Brands' "USA t-shirt page owns its phrase"
has   /tracksuit-manufacturer-usa '<title>Tracksuit Manufacturer for USA Brands' "USA tracksuit page owns its phrase"
has   /streetwear-manufacturer-usa '<title>Streetwear Manufacturer for USA Brands' "USA streetwear page owns its phrase"
lacks /hoodie-manufacturer-usa 'Clothing Manufacturer for USA Brands'       "USA hoodie page does not compete with the USA landing page"
lacks /hoodie-manufacturer-usa '0% import duty'                             "USA hoodie page does not claim the UK's 0% duty"
lacks /streetwear-manufacturer-usa '0% import duty'                         "USA streetwear page does not claim the UK's 0% duty"
lacks /hoodie-manufacturer-usa 'Developing Countries Trading Scheme'        "USA page does not cite a UK-only scheme"
has   /hoodie-manufacturer-usa '26.5'                                       "USA page states the real duty rate"
has   /hoodie-manufacturer-usa 'HTS 6110'                                   "USA page uses HTS, not HS"
has   /hoodie-manufacturer-uk 'HS 6110'                                     "UK page still uses HS"
has   /hoodie-manufacturer-uk '0% import duty'                              "UK page still states its 0% position"
lacks /hoodie-manufacturer-uk '26.5'                                        "UK page does not carry the US rate"
has   /sitemap.xml 'hoodie-manufacturer-usa'                                "USA hoodie page is in the sitemap"
has   /sitemap.xml 'streetwear-manufacturer-usa'                            "USA streetwear page is in the sitemap"
has   /clothing-manufacturer-usa 'hoodie-manufacturer-usa'                  "USA landing page links its product pages"

# CANADA / AUSTRALIA / GERMANY / UAE product pages. Six countries, six
# different tariff positions, and the failure mode is one page quoting
# another country's rate - a number somebody budgets against and pays at
# the border. Each is asserted to state its OWN position and not a
# neighbour's.
has   /hoodie-manufacturer-canada 'General Preferential Tariff'     "Canada page states apparel is excluded from GPT"
has   /hoodie-manufacturer-canada '18%'                             "Canada page states the real knitwear rate"
lacks /hoodie-manufacturer-canada '0% import duty'                  "Canada page does not claim the UK's 0%"
lacks /hoodie-manufacturer-canada 'Section 301'                     "Canada page does not carry the US tariff"

has   /hoodie-manufacturer-australia 'flat 5%'                      "Australia page states the real duty rate"
has   /hoodie-manufacturer-australia '10% GST'                      "Australia page states GST"
lacks /hoodie-manufacturer-australia '0% import duty'               "Australia page does not claim the UK's 0%"

has   /hoodie-manufacturer-germany 'GSP+'                           "Germany page cites the EU scheme"
has   /hoodie-manufacturer-germany 'will not quote you a flat 0%'   "Germany page keeps its rules-of-origin caveat"
has   /hoodie-manufacturer-germany '19%'                            "Germany page states German import VAT"
lacks /hoodie-manufacturer-germany 'Developing Countries Trading'   "Germany page does not cite the UK-only scheme"

has   /hoodie-manufacturer-uae 'GCC common customs'                 "UAE page states the GCC duty"
lacks /hoodie-manufacturer-uae '0% import duty'                     "UAE page does not claim the UK's 0%"

has   /sitemap.xml 'hoodie-manufacturer-canada'                     "Canada product page is in the sitemap"
has   /sitemap.xml 'streetwear-manufacturer-uae'                    "UAE product page is in the sitemap"
has   /clothing-manufacturer-germany 'hoodie-manufacturer-germany'  "Germany landing page links its product pages"

# PRODUCT DETAIL. 349 product cards across 13 collection pages were plain
# divs - visible, priced, and impossible to click. Every one was a dead
# end for a buyer who wanted to know more.
has   /assets/product-detail.js 'fz-card-clickable'        "the product-detail script is served"
has   /intimate-apparel 'product-detail.js'                "intimate-apparel loads it"
has   /soccer-kits 'product-detail.js'                     "soccer-kits loads it"
has   /premium-hoodies 'product-detail.js'                 "premium-hoodies loads it"
has   /basketball-kits 'product-detail.js'                 "basketball-kits loads it"
has   /medical-scrubs 'product-detail.js'                  "medical-scrubs loads it"
has   /assets/product-detail.js 'wa.me'                    "the panel offers a per-product WhatsApp quote"
has   /assets/product-detail.js 'aria-modal'               "the panel is announced as a dialog"

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

hdr "Navigation (one shared top bar - tools/build-nav.py)"
# Before 10 Nov 2026 this site had 35 distinct navigations: 101 pages with a
# WHITE top bar, 42 near-black, 3 gradient, and 13 pages whose header had no
# navigation at all. These checks are written so that a regression on any one
# of those three faults turns the suite red.

code /assets/site-nav.css 200 "the one shared nav stylesheet serves"
code /assets/site-nav.js  200 "the one shared nav script serves"

# A spread of pages that previously had THREE different top bars.
for p in /moq-calculator /clothing-manufacturer-uk /blog /pricing \
         /streetwear-manufacturer /what-we-make /sample-order; do
  has "$p" 'class="fz-nav"'        "$p carries the shared nav"
  has "$p" 'site-nav.css'          "$p loads the shared nav stylesheet"
done

# The pages that had a header with NO nav at all, and the one with no header.
# /moq-calculator is the sharpest case: a buyer using the price calculator
# had no route to pricing or the quote form from it.
has /moq-calculator          'fz-nav__cta'   "the MOQ calculator now offers a quote CTA"
has /streetwear-manufacturer 'fz-nav__cta'   "streetwear manufacturing now offers a quote CTA"
has /blog                    'fz-nav__menu'  "the blog index now has a top bar at all"

# The OLD divergent markup must be gone, not merely outnumbered. These three
# are the exact class names behind the white/black/gradient split.
lacks /clothing-manufacturer-uk 'class="site-header"' "the white site-header markup is gone from /clothing-manufacturer-uk"
lacks /pricing                  'class="site-header"' "the white site-header markup is gone from /pricing"
lacks /moq-calculator           'class="fab-brandmark"'       "the nav-less brandmark block is gone from /moq-calculator"

# Per-page attribution. The CTA carries ?from=<this page> into the homepage
# enquiry form; a constant href would wipe that on every page and nothing
# would error. Each of these must name ITS OWN path.
has /moq-calculator          '/?from=/moq-calculator#contact'          "the MOQ calculator CTA carries its own attribution"
has /clothing-manufacturer-uk '/?from=/clothing-manufacturer-uk#contact' "the UK page CTA carries its own attribution"
has /sample-order            '/?from=/sample-order#contact'            "the sample page CTA carries its own attribution"

# The six translated languages must keep their own words. Overwriting them
# with the English nav is the most likely way this generator goes wrong.
has  /fabricante-de-roupa 'Início'      "the Portuguese nav stays Portuguese"
has  /fabricante-de-roupa 'Pedir or'    "the Portuguese CTA stays Portuguese"
lacks /fabricante-de-roupa 'Sportswear' "the Portuguese nav did not take English labels"
has  /fabricante-de-ropa  'Colecciones' "the Spanish nav stays Spanish"
has  /fabricante-de-ropa  '/precios-fabricacion-ropa' "Spanish pricing still points at the Spanish price list"
has  /bekleidungshersteller 'Kollektionen' "the German nav stays German"
lacks /bekleidungshersteller 'Samples'     "the German nav did not take English labels"

# The bar must be usable on a phone: the drawer control and its state.
has /pricing 'fz-nav__toggle'   "the mobile drawer control is present"
has /pricing 'aria-expanded'    "the drawer control reports its state to screen readers"
has /pricing 'aria-label="Menu"' "the drawer control is labelled"

hdr "Performance (headers that decide how much a visitor downloads and waits)"
# Measured 10 Nov 2026, against the live site:
#   inside the container   TTFB 5ms
#   VPS host via nginx+TLS TTFB 79ms
#   a remote client        TTFB 1500ms
# The server is not slow. Distance to the German VPS is, so the only things
# fixable in this repo are BYTES and ROUND TRIPS - which is what these check.

# enc <path> <expected-encoding> <label>
enc() {
  local e
  e=$(curl -sSI --compressed -H 'Accept-Encoding: gzip, br' --max-time 25 "$SITE$1" \
      | grep -i '^content-encoding' | tr -d '\r' | awk '{print tolower($2)}')
  # Accept gzip OR brotli. Both are real compression; br is BETTER and is
  # what Cloudflare serves from the edge. The first version demanded the
  # exact string "gzip" and so reported a FAILURE the moment the CDN
  # upgraded the encoding - a check that goes red when the site improves
  # is worse than no check, because the next person learns to ignore it.
  # It still fails on NO compression, which is the fault worth catching.
  case "$e" in
    gzip|br|zstd) g "$3 ($e)" ;;
    "")      b "$3 (no compression at all)" ;;
    *)       b "$3 (got '$e', want gzip, br or zstd)" ;;
  esac
}
# ccache <path> <substring> <label>
ccache() {
  local c
  c=$(curl -sSI --compressed --max-time 25 "$SITE$1" | grep -i '^cache-control' | tr -d '\r')
  case "$c" in *"$2"*) g "$3" ;; *) b "$3 (got '${c:-none}')" ;; esac
}

# The homepage bundle is the single biggest download on the site. It shipped
# UNCOMPRESSED at 624 KB until 10 Nov 2026: dist/.htaccess listed
# application/javascript, Apache serves .js as text/javascript, and
# AddOutputFilterByType in a directory context REPLACES the inherited list
# instead of merging - so this one file fell out while every other text
# resource stayed gzipped. Nothing errored and nothing logged.
enc /assets/index-C6-wz3bb.js gzip "the homepage JS bundle is compressed"
enc /assets/site-nav.css      gzip "the shared nav stylesheet is compressed"
enc /assets/product-detail.js gzip "the product-detail script is compressed"
enc /what-we-make             gzip "page HTML is compressed"

# A returning visitor used to block ~1.5s on a 304 carrying 0 bytes before
# anything painted. stale-while-revalidate lets the cached page show at once
# and refresh behind it. must-revalidate must NOT come back: it forbids
# serving stale, which cancels this entirely.
ccache /what-we-make  'stale-while-revalidate' "HTML may repaint instantly from cache"
ccache /pricing       'stale-while-revalidate' "pricing repaints instantly from cache"

# Hashed assets stay immutable for a year - that part was already right.
ccache /assets/site-nav.css 'immutable' "hashed assets stay cached for a year"

hdr "Motion (scroll and hover animation - must fail OPEN)"
# The hidden state used by the reveal effect lives ONLY under .fz-reveal, a
# class that JavaScript adds at runtime. No rule in the stylesheet hides page
# markup on its own, so if the script is blocked, broken or still loading,
# every page renders exactly as it did before motion existed. On a site whose
# traffic comes from search, a reveal effect that fails CLOSED takes the
# content with it - these checks are what stop that regressing.
code /assets/site-motion.css 200 "the shared motion stylesheet serves"
code /assets/site-motion.js  200 "the shared motion script serves"
enc  /assets/site-motion.css gzip "the motion stylesheet is compressed"
enc  /assets/site-motion.js  gzip "the motion script is compressed"
has  /what-we-make 'site-motion.css' "pages load the shared motion stylesheet"
has  /what-we-make 'site-motion.js'  "pages load the shared motion script"

# The stylesheet must not ship an opacity:0 that applies to anything other
# than .fz-reveal. Checked against the SERVED file, not the repo copy.
motion_css=$(curl -fsSL --compressed --max-time 25 "$SITE/assets/site-motion.css" 2>/dev/null || echo '')
if [ -z "$motion_css" ]; then
  b "could not fetch site-motion.css to audit it"
else
  # Newlines are stripped BEFORE splitting on braces, so each line becomes
  # one whole "selector{declarations" rule. Splitting a pretty-printed
  # stylesheet on braces alone leaves the selector and its declarations on
  # separate lines, so a line-by-line grep sees a bare "opacity: 0;" with no
  # selector attached and reports every rule as unscoped - which is exactly
  # what the first version of this check did. The sed drops any leading
  # comment, because the comment above this very rule contains the characters
  # opacity:0 while describing them.
  bad_hide=$(printf '%s' "$motion_css" | tr -d '
' | tr '}' '
'     | grep -E 'opacity[[:space:]]*:[[:space:]]*0[^.0-9]'     | sed 's|.*\*/||' | grep -vc 'fz-reveal' || true)
  [ "${bad_hide:-0}" = "0" ]     && g "motion CSS hides nothing outside .fz-reveal (fails open)"     || b "motion CSS has $bad_hide opacity:0 rule(s) OUTSIDE .fz-reveal - content can be hidden with JS off"
fi

# Reduced motion must be honoured, in the stylesheet as well as the script.
case "$motion_css" in
  *"prefers-reduced-motion"*) g "motion respects prefers-reduced-motion" ;;
  *) b "motion does NOT respect prefers-reduced-motion" ;;
esac

hdr "DNS, mail and origin certificate"
# These exist because of the CDN move (deploy/CDN-CLOUDFLARE.md). The mail
# checks matter MOST, and they matter whether or not a CDN is in front:
# proxying a mail record through an HTTP CDN silently stops the business
# receiving email, and nothing on the website looks wrong when it happens.

# dnsq <name> <type> -> prints the answer data, one per line
dnsq() {
  curl -sS --max-time 20 -H 'accept: application/dns-json' \
    "https://dns.google/resolve?name=$1&type=$2" 2>/dev/null \
  | tr ',' '\n' | grep -o '"data":"[^"]*"' | cut -d'"' -f4
}

mail_a=$(dnsq mail.fabrioza.com A | head -1)
[ "$mail_a" = "95.216.243.216" ] \
  && g "mail.fabrioza.com still points at the mail server ($mail_a)" \
  || b "mail.fabrioza.com is '$mail_a', expected 95.216.243.216 - EMAIL IS AT RISK (is it proxied?)"

mx=$(dnsq fabrioza.com MX | head -1)
case "$mx" in
  *mail.fabrioza.com*) g "MX still points at mail.fabrioza.com" ;;
  *) b "MX is '$mx', expected mail.fabrioza.com - inbound email is at risk" ;;
esac

spf=$(dnsq fabrioza.com TXT | grep -i 'v=spf1' | head -1)
case "$spf" in
  *"ip4:95.216.243.216"*) g "SPF still authorises the mail server explicitly" ;;
  "") b "no SPF record found - outbound mail may be spam-foldered" ;;
  *) b "SPF no longer lists ip4:95.216.243.216 ('$spf')" ;;
esac

# Informational: is the edge in front yet? Never a failure either way - this
# line is here so the suite's output says which mode the site is running in.
cfh=$(curl -sSI --max-time 25 "$SITE/" | grep -ic '^cf-' || true)
if [ "${cfh:-0}" -gt 0 ]; then
  printf '        CDN: Cloudflare edge is in front (%s cf-* headers)\n' "$cfh"
else
  printf '        CDN: not in front yet - requests go straight to the origin\n'
fi

# The origin certificate. With Cloudflare on "Full (strict)" an expired origin
# cert takes the WHOLE SITE down, and renewal is a silent background job due
# around 30 Dec 2026. This turns that into a visible countdown.
exp=$(echo | openssl s_client -connect 209.145.55.76:443 -servername fabrioza.com 2>/dev/null \
      | openssl x509 -noout -enddate 2>/dev/null | cut -d= -f2)
if [ -z "$exp" ]; then
  b "could not read the origin certificate expiry"
else
  exp_s=$(date -d "$exp" +%s 2>/dev/null || echo 0)
  now_s=$(date +%s)
  if [ "$exp_s" -eq 0 ]; then
    printf '        origin cert expires: %s (could not compute days)\n' "$exp"
    g "origin certificate is readable"
  else
    days=$(( (exp_s - now_s) / 86400 ))
    printf '        origin cert expires in %s days (%s)\n' "$days" "$exp"
    [ "$days" -gt 14 ] \
      && g "origin certificate has more than 14 days left" \
      || b "origin certificate expires in $days days - on Full (strict) this takes the site down"
  fi
fi

hdr "Crawl budget (the ?from= attribution URLs)"
# Every Get a Quote button links to /?from=<that page>#contact so the enquiry
# form knows which page produced the lead. Each is the homepage wearing a
# query string, and each declares a canonical to "/" - correct, and Search
# Console files them as "Alternative page with proper canonical tag", which
# is informational rather than an error. But on 10 Nov 2026 Google had
# crawled 89 of them, and the site emits 160 distinct ones against 161 real
# pages: half the crawl budget spent re-reading the homepage.

has /robots.txt "Disallow: /?from=" "robots.txt stops the attribution URLs being crawled"
has /pricing    "rel=\"nofollow\""  "the quote CTA is not followed"

# ...but the parameter must still WORK. It is how a lead is attributed, and
# blocking crawlers must never break it for a human.
code "/?from=/pricing" 200 "the attribution URL still serves for real visitors"
# Fetched directly rather than through page(). That helper caches each page to
# a temp file named after the URL with "/" turned into "_", and "?" is not a
# legal filename character on Windows - so the write fails, the body is empty,
# and the check reports "page not fetched" for a URL that is perfectly healthy.
canon=$(curl -fsSL --compressed --max-time 25 "$SITE/?from=/pricing" 2>/dev/null \
        | grep -oiE 'rel="canonical" href="[^"]*"' | head -1)
case "$canon" in
  *'href="https://fabrioza.com/"'*)
      g "the attribution URL still points its canonical at the homepage" ;;
  "") b "could not read a canonical from the attribution URL" ;;
  *)  b "the attribution URL canonical is unexpected: $canon" ;;
esac

hdr "Product cards (3D tilt)"
# Every product has ONE photograph - 285 cards, 113 distinct images - so a
# real 360 spin is not buildable, only faked, and a fake rotation of a flat
# image reads as broken rather than premium. Parallax tilt is what a single
# image DOES support.
#
# This fetches the EXACT url the page asks for, cache-buster included, rather
# than the bare /assets/product-detail.js. That file is served immutable for
# a year under a filename that never changes and is edge-cached by Cloudflare,
# so the unversioned path keeps answering with whatever shipped last time -
# the first version of this section tested the OLD file and failed against a
# site that was already correct.
pd_url=$(curl -fsSL --compressed --max-time 25 "$SITE/soccer-kits" 2>/dev/null \
         | grep -o '/assets/product-detail\.js?v=[0-9]*' | head -1)
if [ -z "$pd_url" ]; then
  b "could not find the product-detail script tag on /soccer-kits"
else
  printf '        script the page requests: %s\n' "$pd_url"
  pd=$(curl -fsSL --compressed --max-time 25 "$SITE$pd_url" 2>/dev/null)
  if [ -z "$pd" ]; then
    b "the product-detail script the page requests does not serve"
  else
    g "the product-detail script the page requests serves"
    case "$pd" in *"fz-glare"*) g "the light-sweep layer ships" ;;
      *) b "the light-sweep layer is MISSING - stale asset behind the cache-buster?" ;; esac
    case "$pd" in *"perspective(900"*) g "the tilt engine ships" ;;
      *) b "the tilt engine is MISSING - stale asset behind the cache-buster?" ;; esac
    # Guards that keep it off devices where it would misfire or stick.
    case "$pd" in *"hover:hover"*) g "tilt is limited to pointer-fine devices (no stuck tilt on touch)" ;;
      *) b "tilt is NOT limited to hover-capable devices" ;; esac
    case "$pd" in *"prefers-reduced-motion"*) g "tilt honours prefers-reduced-motion" ;;
      *) b "tilt does NOT honour prefers-reduced-motion" ;; esac
    # The tilt writes to the same element the click handler lives on.
    case "$pd" in *"fz-card-clickable"*) g "cards are still wired as buttons" ;;
      *) b "the clickable wiring is gone - cards would no longer open" ;; esac
  fi
fi

hdr "Category cards (/sportswear-catalog)"
# The page advertises 21 categories and shows 16 cards. Twelve of them had
# no link at all - identical markup minus the closing </a> - so a visitor
# clicked a category and nothing happened. Eight already had a page on this
# site that was simply never wired up.
cat=$(curl -fsSL --compressed --max-time 25 "$SITE/sportswear-catalog" 2>/dev/null)
if [ -z "$cat" ]; then
  b "could not fetch /sportswear-catalog"
else
  total=$(printf '%s' "$cat" | grep -c 'class="cat-card"' || true)
  # count cards that contain no <a> at all: split on the card opener, drop
  # the preamble, and look at each fragment up to its own closing markup.
  dead=$(printf '%s' "$cat" | tr '>' '
' | awk '
    /class="cat-card"/ { incard=1; haslink=0; next }
    incard && /^<a href=/ { haslink=1 }
    incard && /^<div class="cat-card"/ { if (!haslink) d++; haslink=0 }
    END { print d+0 }')
  printf '        %s category cards on the page
' "$total"
  [ "${total:-0}" -ge 16 ] && g "all 16 category cards are present"                            || b "expected 16 category cards, found ${total:-0}"
  case "$cat" in
    *"/rugby-kit-manufacturer"*)   g "the rugby card links to its page" ;;
    *) b "the rugby card has lost its link" ;;
  esac
  case "$cat" in
    *"/custom-cheer-uniforms"*)    g "the cheer card links to its page" ;;
    *) b "the cheer card has lost its link" ;;
  esac
  case "$cat" in
    *"/custom-hoodie-manufacturer"*) g "the hoodie card links to its page" ;;
    *) b "the hoodie card has lost its link" ;;
  esac
  # The four categories with no page of their own must still offer a route.
  case "$cat" in
    *"Request ice hockey uniforms"*) g "categories with no page still offer a quote route" ;;
    *) b "the no-page categories have lost their quote route" ;;
  esac
fi

hdr "Quote drawer (lead capture on the page)"
# Built because the live CRM showed 73 of ~89 web leads coming from the
# HOMEPAGE, 65% of all leads never naming a product, and 0 leads carrying
# the ?from= page attribution. The drawer captures the enquiry where the
# buyer already is, with the product and the page attached.
#
# Proven end to end against production on 12 Nov 2026: a honeypot-filled
# POST returned success and stored nothing (224 -> 224), and a real
# submission landed with form_type="Product Quote", the product name, and
# source_page=/soccer-kits - the attribution that was always "/#contact"
# before. That test row was deleted.
qd_url=$(curl -fsSL --compressed --max-time 25 "$SITE/soccer-kits" 2>/dev/null \
         | grep -o "/assets/quote-drawer\.js?v=[0-9a-z]*" | head -1)
if [ -z "$qd_url" ]; then
  b "pages do not load the quote drawer"
else
  printf '        drawer script: %s\n' "$qd_url"
  qd=$(curl -fsSL --compressed --max-time 25 "$SITE$qd_url" 2>/dev/null)
  if [ -z "$qd" ]; then
    b "the quote drawer script does NOT serve"
  else
    g "the quote drawer script serves"
    # It must feed the EXISTING pipeline. A second lead path with weaker
    # guards is a spam hole and puts two kinds of lead in one table.
    case "$qd" in *"/api/send-email.php"*) g "the drawer posts to the existing lead endpoint" ;;
      *) b "the drawer no longer posts to /api/send-email.php" ;; esac
    case "$qd" in *"csrf.php"*) g "the drawer fetches a CSRF token" ;;
      *) b "the drawer has lost CSRF protection" ;; esac
    case "$qd" in *"website"*) g "the drawer carries the honeypot field" ;;
      *) b "the drawer has lost the honeypot" ;; esac
    case "$qd" in *"gdpr_consent"*) g "the drawer sends GDPR consent" ;;
      *) b "the drawer no longer sends GDPR consent" ;; esac
    # The two fields that are the entire point of the feature.
    case "$qd" in *"product_type"*) g "the drawer sends the product" ;;
      *) b "the drawer no longer sends the product" ;; esac
    case "$qd" in *"source_page"*) g "the drawer sends the originating page" ;;
      *) b "the drawer no longer sends the originating page" ;; esac
  fi
fi

code /api/csrf.php 200 "the CSRF endpoint serves"

# Every trigger must remain a REAL link, so a script that fails to load can
# never strand a buyer with no way to reach a form.
has /pricing "fz-nav__cta" "the quote CTA is still a real link if the script fails"

hdr "Design configurator / tech pack"
# /design holds garment, fabric, GSM, colourway, 10 decoration techniques,
# placement, artwork and a cutter-ready position. It had produced 0 of 224
# leads because it was linked from 0 navs and 0 footers - fixed 14 Nov 2026.
has /pricing     '"/design"'   "Design is in the nav (it was invisible, and produced 0 leads)"
code /design 200               "the configurator serves"

dz=$(curl -fsSL --compressed --max-time 25 "$SITE/design" 2>/dev/null)
if [ -z "$dz" ]; then
  b "could not fetch /design to audit the tech pack"
else
  case "$dz" in *"mockupPng"*) g "the mockup exporter ships" ;;
    *) b "the mockup exporter is GONE - enquiries carry no visual placement" ;; esac

  # The placement must be sent whether or not the buyer dragged the artwork.
  # It used to read `state.moved?boxPercent():null`, so accepting the
  # standard placement sent no coordinates at all.
  # The POSITIVE marker is tested first, deliberately. The code comment that
  # explains this fix quotes the old expression verbatim, so a case testing
  # for "state.moved?boxPercent()" first matches the comment describing the
  # repair and reports the bug as present on a page that is correct - which
  # is exactly what the first version of this check did.
  case "$dz" in
    *"standard placement, not adjusted"*)
      g "the placement is sent even when the buyer does not drag it" ;;
    *"state.moved?boxPercent():null,"*)
      b "the placement is conditional again - a buyer who does not drag sends NO coordinates" ;;
    *) b "the placement labelling is missing" ;;
  esac

  # The garment photo must be inlined before rasterising. An SVG loaded via a
  # data: URL cannot fetch external images, so a naive export shows artwork
  # on an empty background while LOOKING like a finished mockup - which would
  # be approved and then manufactured.
  case "$dz" in *"querySelectorAll('image')"*) g "every external image is inlined before rasterising" ;;
    *) b "the mockup no longer inlines all images - the garment would vanish from it" ;; esac

  # It must never cost the enquiry.
  case "$dz" in *"finish(null)"*) g "a failed mockup still lets the enquiry send" ;;
    *) b "the mockup has no failure path - it could block a lead" ;; esac

  # Percentages, not invented centimetres: no garment measurements exist here.
  case "$dz" in *"% of its width"*) g "placement is expressed as a percentage of the garment" ;;
    *) b "the placement units changed - check nobody invented centimetres" ;; esac

  # It must keep feeding the one lead pipeline.
  case "$dz" in *"/api/send-email.php"*) g "the configurator posts to the existing lead endpoint" ;;
    *) b "the configurator no longer posts to /api/send-email.php" ;; esac
fi

hdr "Result"
printf '  %d passed, %d failed\n\n' "$PASS" "$FAIL"
[ "$FAIL" -eq 0 ] || exit 1
