#!/usr/bin/env bash
# Configure WhatsApp lead alerts on the VPS, then prove they work.
#
#   bash tools/setup-whatsapp.sh callmebot        # prompts for phone + key
#   bash tools/setup-whatsapp.sh cloud            # Meta WhatsApp Cloud API
#   bash tools/setup-whatsapp.sh test             # send a test to whatever is set
#   bash tools/setup-whatsapp.sh status           # what the container sees
#
# Values are read with `read -s` so they never reach your shell history,
# are written only to /opt/fabrioza/.env on the VPS (gitignored), and are
# never printed back.
#
# Why this is a script and not three ssh commands: `docker restart` does
# NOT re-read env_file or environment:. A variable appended to .env and
# followed by a restart is simply absent, the container comes back
# healthy, and the setting is silently missing. Only `docker compose up -d`
# recreates it. This script does that, then reads the value back from
# INSIDE the container rather than trusting the file.

set -uo pipefail
VPS="root@209.145.55.76"
DIR="/opt/fabrioza"
MODE="${1:-status}"

g() { printf '  \033[32m%s\033[0m\n' "$1"; }
b() { printf '  \033[31m%s\033[0m\n' "$1"; }
hdr() { printf '\n\033[1m%s\033[0m\n' "$1"; }

# set_env KEY VALUE  - idempotent, quoted, never echoed
set_env() {
  local k="$1" v="$2"
  ssh "$VPS" "cd $DIR && touch .env && sed -i '/^${k}=/d' .env && printf '%s=%s\n' '$k' '$v' >> .env && chmod 600 .env"
}

recreate() {
  hdr "Recreating the container so it re-reads .env"
  # not `docker restart` - that keeps the old environment
  ssh "$VPS" "cd $DIR && docker compose up -d" 2>&1 | tail -3
}

show_status() {
  hdr "What the container actually sees"
  ssh "$VPS" "docker exec fabrioza-web sh -c '
    for v in WHATSAPP_CALLMEBOT_PHONE WHATSAPP_CALLMEBOT_APIKEY \
             WHATSAPP_CLOUD_TOKEN WHATSAPP_CLOUD_PHONE_ID WHATSAPP_TO \
             TELEGRAM_BOT_TOKEN TELEGRAM_CHAT_ID; do
      val=\$(printenv \$v)
      if [ -n \"\$val\" ]; then echo \"  \$v = set (\${#val} chars)\";
      else echo \"  \$v = EMPTY\"; fi
    done'"
}

send_test() {
  hdr "Sending a real test message"
  # goes through the same crm_notify() the lead form uses, so this tests
  # the deployed code path and not a hand-rolled curl
  ssh "$VPS" "docker exec fabrioza-web php -r '
    require \"/var/www/html/api/db.php\";
    crm_notify(\"FABRIOZA test alert (Telegram leg)\",
               \"FABRIOZA test alert - WhatsApp lead notifications are live.\");
    echo \"sent\n\";'" 2>&1 | tail -2
  hdr "What the server logged"
  ssh "$VPS" "docker logs --since 2m fabrioza-web 2>&1 | grep -i crm_notify | tail -6" \
    || echo "  (nothing logged - check 'docker logs fabrioza-web')"
}

case "$MODE" in
  callmebot)
    cat <<'EOT'

CallMeBot needs three things done on the phone that will receive alerts.
Nobody can do them for you - the key is issued to that WhatsApp account:

  1. Save  +34 644 51 95 23  to that phone's contacts (any name).
  2. From that phone's WhatsApp, message it exactly:
         I allow callmebot to send me messages
  3. Wait for the reply. It contains "Your APIKEY is 123456".

EOT
    read -r -p "  Receiving number in full international form (e.g. +923124815601): " WA_PHONE
    read -r -s -p "  API key from the CallMeBot reply: " WA_KEY; echo
    [ -n "$WA_PHONE" ] && [ -n "$WA_KEY" ] || { b "both values are required"; exit 1; }
    set_env WHATSAPP_CALLMEBOT_PHONE "$WA_PHONE"
    set_env WHATSAPP_CALLMEBOT_APIKEY "$WA_KEY"
    g "written to $DIR/.env (chmod 600, gitignored)"
    recreate; show_status; send_test
    ;;

  cloud)
    cat <<'EOT'

Meta WhatsApp Cloud API. First-party, no third-party relay, but it needs
a Meta Business account, an app with WhatsApp added, and - because a
lead alert is business-initiated - an APPROVED MESSAGE TEMPLATE. The
permanent token comes from a System User, not the 24-hour test token.

EOT
    read -r -s -p "  Permanent access token: " WA_TOK; echo
    read -r -p "  Phone number ID (from the WhatsApp > API Setup panel): " WA_ID
    read -r -p "  Recipient number, digits only, no + (e.g. 923124815601): " WA_TO
    [ -n "$WA_TOK" ] && [ -n "$WA_ID" ] && [ -n "$WA_TO" ] || { b "all three are required"; exit 1; }
    set_env WHATSAPP_CLOUD_TOKEN "$WA_TOK"
    set_env WHATSAPP_CLOUD_PHONE_ID "$WA_ID"
    set_env WHATSAPP_TO "$WA_TO"
    g "written to $DIR/.env (chmod 600, gitignored)"
    recreate; show_status; send_test
    ;;

  test)   send_test ;;
  status) show_status ;;
  *)      b "usage: $0 {callmebot|cloud|test|status}"; exit 1 ;;
esac
