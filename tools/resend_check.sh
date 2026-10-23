#!/usr/bin/env bash
# Resend readiness check for fabrioza.com. Changes nothing.
#
#   bash tools/resend_check.sh           # DNS only
#   bash tools/resend_check.sh --send    # also send one live test email
#
# Runs on the VPS and on a Windows dev box. Three portability traps were hit
# writing this, all of which made it report false failures:
#   1. Windows nslookup puts the TXT value on the line AFTER "text =", Linux
#      puts it on the same line. Pulling quoted substrings handles both.
#   2. The VPS has no `python` binary, only `python3` - so a python-based
#      parser silently failed every lookup and reported live records as GONE.
#      There is no interpreter dependency now, only sed and grep.
#   3. --send used to ssh to the VPS, which fails when you are ALREADY on the
#      VPS (root cannot ssh to itself without a key). It now runs docker
#      locally when docker is present.
#
# The DMARC check is the important one. fabrioza.com publishes adkim=s
# (strict), so the DKIM signing domain must be EXACTLY fabrioza.com. A
# send.fabrioza.com signature would fail DMARC, and with p=none nothing
# bounces - the only symptom is mail quietly landing in spam.
set -u
DOM=fabrioza.com
DNS=8.8.8.8
ok=0; bad=0
say()  { printf '  %-34s %s\n' "$1" "$2"; }
pass() { say "$1" "PASS  $2"; ok=$((ok+1)); }
fail() { say "$1" "FAIL  $2"; bad=$((bad+1)); }
warn() { say "$1" "----  $2"; }

# Quoted substrings, works with both nslookup layouts and with dig.
txt() {
  if command -v dig >/dev/null 2>&1; then
    dig +short TXT "$1" "@$DNS" 2>/dev/null | sed 's/" "//g; s/^"//; s/"$//'
  else
    nslookup -type=TXT "$1" "$DNS" 2>/dev/null | tr -d '\r' \
      | grep -o '"[^"]*"' | sed 's/^"//; s/"$//'
  fi
}
mxr() {
  if command -v dig >/dev/null 2>&1; then
    dig +short MX "$1" "@$DNS" 2>/dev/null | head -1 | awk '{print $NF}' | sed 's/\.$//'
  else
    nslookup -type=MX "$1" "$DNS" 2>/dev/null | tr -d '\r' \
      | grep -i 'mail exchanger' | sed 's/.*= //' | head -1 \
      | awk '{print $NF}' | sed 's/\.$//'
  fi
}

echo "Resend readiness - $DOM"
echo "================================================================"

M=$(mxr "$DOM")
if [ -n "$M" ]; then pass "root MX (your inbox)" "$M"
else fail "root MX (your inbox)" "MISSING - incoming mail is broken"; fi

RK=$(txt "resend._domainkey.$DOM")
if echo "$RK" | grep -qi 'p='; then pass "resend._domainkey" "present"
else fail "resend._domainkey" "missing - add the DKIM TXT from Resend"; fi

if txt "default._domainkey.$DOM" | grep -qi 'p='; then
  pass "default._domainkey (cPanel)" "present - do NOT delete this"
else
  fail "default._domainkey (cPanel)" "GONE - webmail sending will fail DKIM"
fi

SPF=$(txt "$DOM" | grep -i 'v=spf1' || true)
N=$(printf '%s' "$SPF" | grep -c 'v=spf1' || true)
if [ -z "$SPF" ]; then fail "SPF" "missing"
elif [ "$N" -gt 1 ]; then fail "SPF" "$N records - only ONE allowed, all mail fails"
elif echo "$SPF" | grep -q '95.216.22.216'; then pass "SPF" "$SPF"
else fail "SPF" "cPanel IP removed - webmail will fail: $SPF"; fi

D=$(txt "_dmarc.$DOM" | grep -i 'v=DMARC' || true)
if [ -z "$D" ]; then warn "DMARC" "none published"
else
  say "DMARC" "$D"
  if echo "$D" | grep -q 'adkim=s'; then
    if echo "$RK" | grep -qi 'p='; then
      pass "DKIM alignment (adkim=s)" "root DKIM present, From: @$DOM aligns"
    else
      fail "DKIM alignment (adkim=s)" "strict, no root DKIM - mail fails DMARC"
    fi
    if txt "send.$DOM" | grep -qi 'p='; then
      fail "subdomain DKIM" "send.$DOM signs - fails under adkim=s"
    fi
  fi
fi

SM=$(mxr "send.$DOM")
[ -n "$SM" ] && warn "send.$DOM MX" "$SM (bounces only - root MX untouched)"

C=$(curl -s -o /dev/null -m 10 -w '%{http_code}' -I https://api.resend.com/emails 2>/dev/null)
if [ "$C" != "000" ]; then pass "api.resend.com reachable" "HTTP $C"
else fail "api.resend.com reachable" "no route"; fi

echo "================================================================"
printf '  %d passed, %d failed\n' "$ok" "$bad"

if [ "${1:-}" = "--send" ]; then
  echo
  PHP='require "/var/www/html/api/mailer.php";
if(!fab_resend_enabled()){ echo "  RESEND_API_KEY is not set INSIDE the container.\n";
  echo "  Putting it in .env is not enough - it must also be listed in the\n";
  echo "  environment: block of docker-compose.yml, then: docker compose up -d\n"; exit(1); }
list($o,$n) = resendSend("info@fabrioza.com","FABRIOZA","Resend test ".date("H:i"),
  "<p>Sent through Resend. If you are reading this, it works.</p>");
echo ($o?"  SENT   ":"  FAILED "), $n, PHP_EOL;'
  if command -v docker >/dev/null 2>&1 && docker ps --format '{{.Names}}' 2>/dev/null | grep -q '^fabrioza-web$'; then
    echo "Live send (local container):"
    docker exec fabrioza-web php -r "$PHP"
  else
    echo "Live send (over ssh):"
    ssh -o BatchMode=yes root@209.145.55.76 "docker exec fabrioza-web php -r '$PHP'"
  fi
fi
