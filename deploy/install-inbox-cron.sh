#!/usr/bin/env bash
# Put the inbox importer back on a schedule, and give the fabrioza logs
# somewhere to go.
#
# It ran until 14 Aug 2026 and then stopped - /var/log/fabrioza-inbox.log is
# frozen at that date - so every enquiry since has sat unimported until
# somebody ran it by hand. Nobody did.
#
# TWO ENTRIES, NOT ONE:
#   every 30 min  incremental (the importer's default: messages from the
#                 last 3 days). Cheap, and keeps new leads flowing in.
#   Sunday 04:10  --all, the whole mailbox. The incremental window is 3
#                 days, so any outage longer than that loses mail
#                 permanently. mail_seen is keyed on Message-ID, so a full
#                 pass re-reads everything and imports only what is new -
#                 it is a safety net that cannot double-import.
#
# flock on both, sharing ONE lock: a full pass takes ~3 minutes and the
# incremental fires every 30, so they can collide on a Sunday. -n means a
# blocked run gives up rather than queueing behind the other.
set -euo pipefail

STAMP=$(date +%Y%m%d-%H%M%S)
LOCK=/var/run/fabrioza-inbox.lock
LOG=/var/log/fabrioza-inbox.log
PHP="docker exec fabrioza-web php /var/www/html/api/import-inbox.php"

INCREMENTAL="*/30 * * * * flock -n $LOCK $PHP >> $LOG 2>&1"
FULL="10 4 * * 0 flock -n $LOCK $PHP --all >> $LOG 2>&1"

# Never clobber a crontab shared with five other projects.
crontab -l > "/root/crontab.bak-$STAMP" 2>/dev/null || true
echo "  backup: /root/crontab.bak-$STAMP ($(wc -l < "/root/crontab.bak-$STAMP") lines)"

if crontab -l 2>/dev/null | grep -q 'import-inbox'; then
  echo "  import-inbox is already scheduled - leaving the crontab alone"
else
  { crontab -l 2>/dev/null; echo "$INCREMENTAL"; echo "$FULL"; } | crontab -
  echo "  added 2 entries"
fi

# Rotation. None of the fabrioza logs had any; the inbox one is already
# 131K and is about to start getting 48 entries a day.
cat > /etc/logrotate.d/fabrioza <<'CONF'
/var/log/fabrioza-*.log {
    weekly
    rotate 8
    compress
    delaycompress
    missingok
    notifempty
    copytruncate
    su root syslog
}
CONF
echo "  wrote /etc/logrotate.d/fabrioza"

echo
echo "  --- crontab now (fabrioza lines) ---"
crontab -l | grep -n 'fabrioza' | sed 's/^/    /'
echo
echo "  --- logrotate accepts the config? ---"
logrotate -d /etc/logrotate.d/fabrioza 2>&1 | grep -iE 'error|considering|rotating pattern' | head -5 | sed 's/^/    /'
