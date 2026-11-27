#!/usr/bin/env bash
# =====================================================================================================
#  GhostNode – Start-Installer für den Root-Server (alles in einem Befehl)
#
#    curl -fsSL https://raw.githubusercontent.com/ghostnode-labs/ghostenode/main/server/install.sh | sudo bash
#
#  Macht: Git/curl installieren → Repo nach /root/ghostenode holen (oder aktualisieren, auch wenn es schon da ist)
#  → beim ersten Mal „install“, danach „update“ von ghostnode-root.sh. Fragen gehen auch bei „curl | bash“ ans Terminal.
#  Weitere Befehle/Optionen werden durchgereicht, z. B.:  … | sudo bash -s -- check      … | sudo bash -s -- install --yes
# =====================================================================================================
set -Eeuo pipefail
REPO=https://github.com/ghostnode-labs/ghostenode.git
DIR=/root/ghostenode
CONF=/etc/ghostnode/setup.conf

say() { printf '\033[1;36m▶ %s\033[0m\n' "$*"; }
die() { printf '\033[1;31m✖ %s\033[0m\n' "$*" >&2; exit 1; }

[ "$(id -u)" = 0 ] || die "Bitte als root starten (sudo -i oder „… | sudo bash“)."
[ -r /etc/os-release ] && . /etc/os-release
case "${ID:-}" in debian|ubuntu) ;; *) die "Nur Debian 12 / Ubuntu 22.04 / 24.04 (gefunden: ${PRETTY_NAME:-unbekannt})." ;; esac

export DEBIAN_FRONTEND=noninteractive
# frischer Server: automatische Updates laufen oft noch → warten; unterbrochene Installationen reparieren
apt_wait() {
    local i=0
    while { command -v fuser >/dev/null && fuser /var/lib/dpkg/lock-frontend /var/lib/dpkg/lock /var/lib/apt/lists/lock >/dev/null 2>&1; } || pgrep -x 'apt|apt-get|dpkg|unattended-upgr' >/dev/null; do
        [ $i = 0 ] && say "Warte auf laufende System-Updates (automatische Updates des Servers) …"
        i=$((i+1)); [ $i -gt 120 ] && die "Paketverwaltung seit 10 Minuten blockiert – Server neu starten und erneut versuchen."
        sleep 5
    done
}
apt_wait
if [ -n "$(ls -A /var/lib/dpkg/updates 2>/dev/null)" ] || [ -n "$(dpkg --audit 2>/dev/null)" ]; then
    say "Unterbrochene Paket-Installation reparieren (dpkg --configure -a)"
    find /var/lib/dpkg/updates -type f -size 0 -print -delete 2>/dev/null | sed 's/^/  leere Journal-Datei entfernt: /' || true   # „parsing file …/updates/00xx near line 0“
    dpkg --configure -a || die "dpkg --configure -a fehlgeschlagen – Ausgabe oben prüfen."
    apt-get -f install -y -qq >/dev/null || true
fi
say "Werkzeuge (git, curl) installieren"
command -v git >/dev/null 2>&1 && command -v curl >/dev/null 2>&1 || { apt-get update -qq && apt-get install -y -qq git curl ca-certificates >/dev/null; }

if [ -d "$DIR/.git" ]; then
    say "GhostNode-Repo aktualisieren ($DIR)"
    git -C "$DIR" fetch -q origin main
    git -C "$DIR" reset -q --hard origin/main      # Einstellungen liegen in /etc/ghostnode, nicht im Repo
elif [ -e "$DIR" ]; then
    say "$DIR ist kein Git-Ordner – wird nach $DIR.alt-$(date +%s) verschoben"
    mv "$DIR" "$DIR.alt-$(date +%s)"
    git clone -q "$REPO" "$DIR"
else
    say "GhostNode-Repo holen ($DIR)"
    git clone -q "$REPO" "$DIR"
fi
chmod 700 "$DIR"

cmd=${1:-}
if [ -z "$cmd" ]; then cmd=$([ -f "$CONF" ] && echo update || echo install); else shift; fi
say "Starte: ghostnode-root.sh $cmd $*"
# bei „curl | bash“ ist stdin die Pipe → Fragen an das Terminal geben
if [ ! -t 0 ] && [ -r /dev/tty ] && ( : < /dev/tty ) 2>/dev/null; then exec bash "$DIR/server/ghostnode-root.sh" "$cmd" "$@" < /dev/tty; fi
exec bash "$DIR/server/ghostnode-root.sh" "$cmd" "$@"
