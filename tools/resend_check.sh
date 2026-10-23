#!/usr/bin/env bash
# Resend readiness check for fabrioza.com.
#
# Run BEFORE adding the API key to see which DNS records are still missing,
# and AFTER to confirm sending works. It changes nothing.
#
#   bash tools/resend_check.sh           # DNS only
#   bash tools/resend_check.sh --send    # also send one live test email
#
# The DMARC alignment check is the important one. fabrioza.com publishes
# adkim=s (strict), so the DKIM signing domain must be EXACTLY fabrioza.com.
# If Resend is set up on a send.fabrioza.com subdomain instead, every message
# fails DMARC - and because the policy is p=none nothing bounces, so nobody
# notices while the mail quietly lands in spam.
#
# Parsing note, learned the hard way: Windows nslookup prints "name text ="
# on one line and the VALUE on the NEXT line, tab-indented, quoted, CRLF.
# A lookahead on "text = " matches nothing, and the first version of this
# script reported a live SPF record and a live cPanel DKIM as GONE. Always
# sanity-check a checker against a record you know exists.
set -u
DOM=fabrioza.com
DNS=8.8.8.8
ok=0; bad=0
say()  { printf '  %-34s %s\n' "$1" "$2"; }
pass() { say "$1" "PASS  $2"; ok=$((ok+1)); }
fail() { say "$1" "FAIL  $2"; bad=$((bad+1)); }
warn() { say "$1" "----  $2"; }

txt() { nslookup -type=TXT "$1" "$DNS" 2>/dev/null | python -c '
import sys,re
for ln in sys.stdin.read().replace(chr(13),"").split(chr(10)):
    m=re.match(r"^\s*\"(.*)\"\s*$",ln)
    if m: print(m.group(1))'; }
mxr() { nslookup -type=MX "$1" "$DNS" 2>/dev/null | tr -d '\r' \
        | grep -i 'mail exchanger' | sed 's/.*= //' | head -1; }

echo "Resend readiness - $DOM"
echo "================================================================"

M=$(mxr "$DOM")
if [ -n "$M" ]; then pass "root MX (your inbox)" "$M"
else fail "root MX (your inbox)" "MISSING - incoming mail is broken"; fi

RK=$(txt "resend._domainkey.$DOM")
if echo "$RK" | grep -qi 'p='; then
  pass "resend._domainkey" "present"
else
  fail "resend._domainkey" "missing - add the DKIM TXT from the Resend dashboard"
fi

if txt "default._domainkey.$DOM" | grep -qi 'p='; then
  pass "default._domainkey (cPanel)" "present - do NOT delete this"
else
  fail "default._domainkey (cPanel)" "GONE - webmail sending will fail DKIM"
fi

SPF=$(txt "$DOM" | grep -i 'v=spf1')
N=$(printf '%s' "$SPF" | grep -c 'v=spf1' || true)
if [ -z "$SPF" ]; then
  fail "SPF" "missing"
elif [ "$N" -gt 1 ]; then
  fail "SPF" "$N records - a domain may publish only ONE, all mail fails"
elif echo "$SPF" | grep -q '95.216.22.216'; then
  pass "SPF" "$SPF"
else
  fail "SPF" "cPanel IP 95.216.22.216 removed - webmail will fail: $SPF"
fi

D=$(txt "_dmarc.$DOM")
if [ -z "$D" ]; then
  warn "DMARC" "none published"
else
  say "DMARC" "$D"
  if echo "$D" | grep -q 'adkim=s'; then
    if echo "$RK" | grep -qi 'p='; then
      pass "DKIM alignment (adkim=s)" "root DKIM present, so From: @$DOM aligns"
    else
      fail "DKIM alignment (adkim=s)" "strict alignment with no root DKIM - mail will fail DMARC"
    fi
    if txt "send.$DOM" | grep -qi 'p='; then
      fail "subdomain DKIM" "send.$DOM signs - under adkim=s this FAILS. Verify the ROOT domain."
    fi
  fi
fi

SM=$(mxr "send.$DOM")
[ -n "$SM" ] && warn "send.$DOM MX" "$SM (bounces only - root MX above untouched)"

C=$(curl -s -o /dev/null -m 10 -w '%{http_code}' -I https://api.resend.com/emails 2>/dev/null)
if [ "$C" != "000" ]; then pass "api.resend.com reachable" "HTTP $C"
else fail "api.resend.com reachable" "no route"; fi

echo "================================================================"
printf '  %d passed, %d failed\n' "$ok" "$bad"

if [ "${1:-}" = "--send" ]; then
  echo
  echo "Live send (inside the container, using its RESEND_API_KEY):"
  ssh -o BatchMode=yes root@209.145.55.76 'docker exec fabrioza-web php -r "
    require \"/var/www/html/api/mailer.php\";
    if(!fab_resend_enabled()){ echo \"  RESEND_API_KEY not set in the container\n\"; exit(1); }
    list(\$o,\$n) = resendSend(\"info@fabrioza.com\",\"FABRIOZA\",\"Resend test \".date(\"H:i\"),
        \"<p>Sent through Resend. If you are reading this, it works.</p>\");
    echo (\$o?\"  SENT  \":\"  FAIL  \"), \$n, PHP_EOL;"'
fi
