#!/bin/bash
# gn-desc: Beispiel – zeigt Speicher, Platte und die größten Logdateien
# gn-cron: @daily
# (ausgeschaltet, weil der Name mit _ beginnt – zum Benutzen umbenennen, z. B. in plattenstand.sh)
set -euo pipefail
echo "== $(date '+%F %T') =="
free -h | head -2
df -h / | tail -1
du -ah /var/log 2>/dev/null | sort -rh | head -5
