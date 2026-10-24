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

SITE="https://fabrioza.com"
VPS="root@209.145.55.76"
PASS=0; FAIL=0
g() { printf '  \033[32mPASS\033[0m  %s\n' "$1"; PASS=$((PASS+1)); }
b() { printf '  \033[31mFAIL\033[0m  %s\n' "$1"; FAIL=$((FAIL+1)); }
hdr() { printf '\n\033[1m%s\033[0m\n' "$1"; }

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
  [ -s "$f" ] || curl -fsS --max-time 30 "$SITE$p" -o "$f" 2>/dev/null || : >"$f"
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
  git push origin HEAD || { echo "push failed"; exit 1; }
  ssh "$VPS" 'cd /opt/fabrioza && git pull --ff-only && docker compose up -d --build' \
    2>&1 | tail -4
fi

hdr "Commit in sync"
LOCAL=$(git rev-parse HEAD)
REMOTE=$(ssh -o ConnectTimeout=15 "$VPS" 'cd /opt/fabrioza && git rev-parse HEAD' 2>/dev/null | tr -d '\r')
printf '  local  %s\n  vps    %s\n' "${LOCAL:0:12}" "${REMOTE:0:12}"
[ -n "$REMOTE" ] && [ "$LOCAL" = "$REMOTE" ] && g "VPS is on this commit" \
  || b "VPS is NOT on this commit (unpushed work, or the pull did not run)"
[ -z "$(git status --porcelain)" ] && g "working tree clean" \
  || b "working tree has uncommitted changes - they are not live"

hdr "Container"
ssh -o ConnectTimeout=15 "$VPS" \
  "docker ps --filter name=fabrioza-web --format '{{.Status}} | image {{.Image}}'" 2>/dev/null \
  | sed 's/^/  /'
UP=$(ssh -o ConnectTimeout=15 "$VPS" "docker ps -q --filter name=fabrioza-web" 2>/dev/null | tr -d '\r')
[ -n "$UP" ] && g "fabrioza-web is running" || b "fabrioza-web is NOT running"

hdr "Configurator photos"
N=$(ssh -o ConnectTimeout=15 "$VPS" \
  "docker exec fabrioza-web sh -c 'ls /var/www/html/images/design/*.webp 2>/dev/null | wc -l'" 2>/dev/null | tr -d '\r ')
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

hdr "Lead alerts (server side)"
ssh -o ConnectTimeout=15 "$VPS" \
  "docker exec fabrioza-web grep -c crm_notify_whatsapp /var/www/html/api/db.php" >/dev/null 2>&1 \
  && g "WhatsApp fan-out is in the deployed db.php" \
  || b "WhatsApp fan-out missing from the deployed db.php"

hdr "Result"
printf '  %d passed, %d failed\n\n' "$PASS" "$FAIL"
[ "$FAIL" -eq 0 ] || exit 1
