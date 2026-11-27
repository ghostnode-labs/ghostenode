#!/usr/bin/env bash
# =====================================================================================================
#  GhostNode Root-Server – Einrichtung, Updates und Prüfung (Debian 12 / Ubuntu 22.04 / 24.04)
#
#  Rollen: „alles“ (Standard) – Webseite (GitHub ghostenode), MariaDB, Discord-Bot (node_discord), HypeRate-Weiterleitung
#          (vrc_nexus), TURN, Mumble, FTPS, Mail, Cron, Backup.   „dienste“ – Webseite bleibt woanders (z. B. Plesk).
#  nginx ist immer der Reverse Proxy vorne (80/443, TLS); Webseite (Apache intern), Bot und HypeRate-Weiterleitung laufen
#  dahinter als eigene Dienste. Umzug von Plesk: „migrate“ (MySQL-Export + data/ + uploads/).
#
#  Bausteine:
#    web   Webseite (Apache + PHP-FPM, eigener Benutzer „ghostnode“, Git-Checkout → Updates per git pull)
#    db    MariaDB (oder SQLite), Datenbank + Benutzer mit Zufallspasswort
#    tls   Let's-Encrypt-Zertifikate für alle Namen, automatische Verlängerung
#    hr    HypeRate-Weiterleitung (Node.js, hr.<domain>)
#    bot   GhostNode-Discord-Bot mit Web-Dashboard (Node.js, discord.<domain>)
#    turn  TURN/STUN-Server (coturn) für Sprach-/Videoanrufe im Nexus-Chat (turn.<domain>)
#    ftp   FTPS-Eingang für VRChat-Poster (nur der Poster-Eingangsordner, kein Zugriff auf die Seite)
#    mail  Mailversand über einen SMTP-Anbieter (Status-Meldungen per E-Mail)
#    voice Mumble-Sprachserver (voice.<domain>, ohne Pterodactyl als Dienst, mit Pterodactyl über dessen Egg)
#    cron  Aufgabenplaner der Webseite alle 5 Minuten (nur Rolle „alles“) + tägliches Server-Backup
#    fw    Firewall (ufw), fail2ban, automatische Sicherheitsupdates
#
#  Aufruf (als root):
#    bash ghostnode-root.sh install            alles einrichten (fragt nach Domain, E-Mail und Schlüsseln)
#    bash ghostnode-root.sh update             Webseite, Weiterleitung und Bot aktualisieren, Dienste neu starten
#    bash ghostnode-root.sh certs              Zertifikate (erneut) holen – z. B. wenn DNS beim install noch nicht stand
#    bash ghostnode-root.sh secrets            Schlüssel (Discord, HypeRate, SMTP …) ändern und übernehmen
#    bash ghostnode-root.sh check              alles prüfen (Dienste, Seiten, PHP-Erweiterungen, Zertifikate)
#    bash ghostnode-root.sh backup             Server-Backup jetzt (Datenbank, data/, Bot-Daten, Einstellungen)
#    bash ghostnode-root.sh olddb              alte MySQL (z. B. Plesk) übernehmen bzw. erneut holen (--force), Zugang ändern (--neu)
#    bash ghostnode-root.sh crons              geplante Aufgaben (Webseite, Wächter, Backup, Auto-Update) mit letztem Ergebnis
#    bash ghostnode-root.sh migrate …          Umzug von Plesk: MySQL-Datenbank + data/ + uploads/ (richtet die Webseite hier ein)
#        --db=/root/plesk-dump.zip            Datenbank-Export aus Plesk (.sql, .sql.gz oder .zip)
#        --from=HOST --src-db=NAME --src-user=BENUTZER [--src-port=3306]   … oder direkt von der Plesk-MySQL (Passwort wird gefragt)
#        --files=/root/httpdocs.zip           Zip/Tar des Plesk-Webordners (oder nur data/ + uploads/)
#        nur --files (+ --from=PLESK-IP): Datenbank-Zugang wird aus data/config.php im Archiv gelesen und direkt geholt
#  Optionen:
#    --yes            keine Rückfragen (Werte aus /etc/ghostnode/setup.conf bzw. Umgebung)
#    --no-tls         keine Zertifikate holen (Test / DNS noch nicht umgestellt)
#    --only=a,b       nur diese Teile (web,db,tls,hr,bot,turn,voice,ftp,mail,cron,fw)
#    --skip=a,b       diese Teile auslassen
#
#  Neben Pterodactyl (Panel + Wings) oder einem anderen nginx: wird automatisch erkannt. Dann bleibt nginx vorne auf 80/443
#  (Panel unverändert), Apache läuft nur intern auf 127.0.0.1:18080, die Firewall wird nie zurückgesetzt, Wings-Ports bleiben
#  offen, PHP bleibt auf der Version des Panels, eine vorhandene MySQL wird mitbenutzt statt ersetzt.
#
#  Geheimnisse (Bot-Token, Client-Secrets, HypeRate-Key, SMTP-Passwort) werden nur auf dem Server abgefragt und
#  in /etc/ghostnode/secrets.env (chmod 600) gespeichert – nie im Repository. Erzeugte Zugangsdaten (Datenbank,
#  FTP, TURN) stehen in /root/ghostnode-zugaenge.txt (chmod 600).
# =====================================================================================================
set -Eeuo pipefail
SCRIPT_VERSION=1
umask 022

CONF_DIR=/etc/ghostnode
CONF=$CONF_DIR/setup.conf
SECRETS=$CONF_DIR/secrets.env
CRED=/root/ghostnode-zugaenge.txt
REPO_WEB=${REPO_WEB:-https://github.com/ghostnode-labs/ghostenode.git}
REPO_NEXUS=${REPO_NEXUS:-https://github.com/ghostnode-labs/vrc_nexus.git}
REPO_BOT=${REPO_BOT:-https://github.com/ghostnode-labs/node_discord.git}
APP_USER=ghostnode
HR_PORT=18091          # nur 127.0.0.1 – hohe Ports, damit sie nicht mit Spielserver-Ports (Pterodactyl) kollidieren
BOT_PORT=18092
LIVE_PORT=18093        # Live-Dienst (WebSocket, Fernsteuerung in Echtzeit) – nur 127.0.0.1, über nginx /live/
BACKEND_PORT=18080     # Apache hinter nginx (nur im nginx-Modus)
FTP_PASV_MIN=40000
FTP_PASV_MAX=40050
TURN_MIN=49160
TURN_MAX=49400

# ---------------------------------------------------------------- Ausgabe
c_ok=$'\e[32m'; c_warn=$'\e[33m'; c_err=$'\e[31m'; c_dim=$'\e[2m'; c_b=$'\e[1m'; c_0=$'\e[0m'
[ -t 1 ] || { c_ok=; c_warn=; c_err=; c_dim=; c_b=; c_0=; }
step() { echo; echo "${c_b}▶ $*${c_0}"; }
ok()   { echo "  ${c_ok}✔${c_0} $*"; }
warn() { echo "  ${c_warn}⚠${c_0} $*"; WARNINGS+=("$*"); }
fail() { echo "  ${c_err}✖${c_0} $*"; FAILS=$((FAILS+1)); }
die()  { echo "${c_err}✖ $*${c_0}" >&2; exit 1; }
WARNINGS=(); FAILS=0
trap 'echo "${c_err}✖ Abbruch in Zeile $LINENO: $BASH_COMMAND${c_0}" >&2' ERR

# ---------------------------------------------------------------- Optionen
CMD=${1:-install}; [ $# -gt 0 ] && shift
ARGS=("$@")
YES=0; NO_TLS=0; ONLY=""; SKIP=""; MIG_DB=""; MIG_FILES=""; MIG_FROM=""; MIG_SRC_DB=""; MIG_SRC_USER=""; MIG_SRC_PORT=3306
for a in "$@"; do
    case "$a" in
        --yes|-y) YES=1 ;;
        --no-tls) NO_TLS=1 ;;
        --only=*) ONLY=${a#--only=} ;;
        --skip=*) SKIP=${a#--skip=} ;;
        --db=*) MIG_DB=${a#--db=} ;;
        --files=*) MIG_FILES=${a#--files=} ;;
        --from=*) MIG_FROM=${a#--from=} ;;
        --src-db=*) MIG_SRC_DB=${a#--src-db=} ;;
        --src-user=*) MIG_SRC_USER=${a#--src-user=} ;;
        --src-port=*) MIG_SRC_PORT=${a#--src-port=} ;;
        --force|--neu) ;;   # für olddb
        -h|--help) sed -n '2,36p' "$0"; exit 0 ;;
        *) die "Unbekannte Option: $a (Hilfe: --help)" ;;
    esac
done
want() {   # want <teil> → läuft dieser Teil?
    local p=$1
    [[ ",$SKIP," == *",$p,"* ]] && return 1
    [ -z "$ONLY" ] && return 0
    [[ ",$ONLY," == *",$p,"* ]]
}

[ "$(id -u)" = 0 ] || die "Bitte als root ausführen (sudo bash $0 $CMD)."
. /etc/os-release 2>/dev/null || die "Unbekanntes System."
case "${ID:-}" in debian|ubuntu) ;; *) die "Nur Debian/Ubuntu werden unterstützt (gefunden: ${PRETTY_NAME:-?})." ;; esac
export DEBIAN_FRONTEND=noninteractive

# Läuft systemd? (In Containern ohne systemd werden Dienste mit „service“ bzw. direkt gestartet – nur zum Testen.)
if [ -d /run/systemd/system ]; then HAS_SYSTEMD=1; else HAS_SYSTEMD=0; fi

# ---------------------------------------------------------------- Einstellungen & Geheimnisse
rand() { local s; s=$(head -c 1024 /dev/urandom | LC_ALL=C tr -dc 'A-Za-z0-9'); printf '%s' "${s:0:${1:-32}}"; }   # ohne SIGPIPE (pipefail)
load_conf() {
    install -d -m 0750 "$CONF_DIR"
    [ -f "$CONF" ] && . "$CONF"
    [ -f "$SECRETS" ] && { set -a; . "$SECRETS"; set +a; }
    return 0
}
ask() {   # ask VAR "Frage" [Vorgabe] [geheim]
    local var=$1 q=$2 def=${3:-} secret=${4:-} cur=${!1:-} ans
    [ -n "$cur" ] && return 0
    if [ "$YES" = 1 ] || [ ! -t 0 ]; then printf -v "$var" '%s' "$def"; return 0; fi
    if [ -n "$secret" ]; then read -r -s -p "  $q${def:+ [$def]}: " ans; echo; else read -r -p "  $q${def:+ [$def]}: " ans; fi
    printf -v "$var" '%s' "${ans:-$def}"
}
save_kv() {   # save_kv DATEI VAR... – Format VAR='wert', lesbar für bash und systemd (EnvironmentFile)
    local f=$1 v val; shift
    : > "$f.tmp"; chmod 600 "$f.tmp"
    for v in "$@"; do
        val=${!v:-}
        case "$val" in *"'"*|*$'\n'*) die "Wert für $v darf kein ' und keinen Zeilenumbruch enthalten." ;; esac
        printf "%s='%s'\n" "$v" "$val" >> "$f.tmp"
    done
    mv "$f.tmp" "$f"
}
SECRET_VARS=(HYPERATE_API_KEY DISCORD_TOKEN DISCORD_APP_ID DISCORD_GUILD_ID RELEASE_CHANNEL_ID DISCORD_CLIENT_SECRET
             GN_DISCORD_CLIENT_ID GN_DISCORD_CLIENT_SECRET SMTP_HOST SMTP_PORT SMTP_USER SMTP_PASS SMTP_FROM STATUS_WEBHOOK
             REDDIT_CLIENT_ID REDDIT_CLIENT_SECRET TWITCH_EXT_CLIENT_ID TWITCH_EXT_SECRET DB_PASS TURN_SECRET FTP_PASS MUMBLE_SUPW MUMBLE_PASS BOT_SSO_SECRET OLD_DB_PASS LIVE_SECRET)
CONF_VARS=(ROLE RUN_MODE DOMAIN LE_EMAIL WEB_DIR DB_ENGINE DB_NAME DB_USER HR_HOST BOT_HOST TURN_HOST VOICE_HOST HR_UPSTREAM BOT_UPSTREAM FTP_USER TZ_NAME OLD_DB_HOST OLD_DB_PORT OLD_DB_NAME OLD_DB_USER OLD_DB_MIRROR AUTO_UPDATE)

ask_config() {
    step "Einstellungen"
    ask ROLE      "Rolle: alles (Webseite + Dienste hier) oder dienste (Webseite bleibt auf Plesk)" "alles"
    ask DOMAIN    "Haupt-Domain der Webseite" "ghostnode-labs.dev"
    ask LE_EMAIL  "E-Mail für Let's Encrypt (Ablauf-Warnungen)" ""
    if [ "$ROLE" = alles ]; then
        ask WEB_DIR   "Verzeichnis der Webseite" "/var/www/ghostnode"
        ask DB_ENGINE "Datenbank: mariadb oder sqlite" "mariadb"
        if [ "$DB_ENGINE" = mariadb ]; then
            ask OLD_DB_HOST "Alte MySQL übernehmen (z. B. Plesk) – Adresse des alten Datenbank-Servers (leer = keine)" ""
            if [ -n "$OLD_DB_HOST" ]; then
                ask OLD_DB_PORT   "… Port" "3306"
                ask OLD_DB_NAME   "… Name der alten Datenbank" ""
                ask OLD_DB_USER   "… Benutzer der alten Datenbank" ""
                ask OLD_DB_MIRROR "Alte MySQL danach als Backup nutzen (jede Nacht die neue Datenbank dorthin spiegeln)? j/n" "j"
                case "$OLD_DB_MIRROR" in j|J|ja|y|yes|1) OLD_DB_MIRROR=1 ;; *) OLD_DB_MIRROR=0 ;; esac
                [ -n "$OLD_DB_NAME" ] && [ -n "$OLD_DB_USER" ] || die "Für die alte MySQL bitte Name und Benutzer angeben."
            fi
        fi
    fi
    ask AUTO_UPDATE "Automatische Updates von GitHub jede Nacht (Webseite, Bot, HypeRate, dieses Script)? 1/0" "1"
    ask HR_HOST    "Adresse der HypeRate-Weiterleitung" "hr.$DOMAIN"
    ask BOT_HOST   "Adresse des Discord-Bot-Dashboards" "discord.$DOMAIN"
    ask TURN_HOST  "Adresse des TURN-Servers (Anrufe)" "turn.$DOMAIN"
    ask VOICE_HOST "Adresse des Mumble-Servers" "voice.$DOMAIN"
    RUN_MODE=${RUN_MODE:-systemd}   # Weiterleitung & Bot laufen als eigene Dienste (keine Pterodactyl-Eggs mehr; egg nur noch per RUN_MODE=egg)
    if [ "$RUN_MODE" = egg ]; then
        ask HR_UPSTREAM  "Zuweisung des HypeRate-Servers im Panel (IP:Port, am besten 127.0.0.1:…)" "127.0.0.1:$HR_PORT"
        ask BOT_UPSTREAM "Zuweisung des Bot-Servers im Panel (IP:Port)" "127.0.0.1:$BOT_PORT"
    fi
    ask TZ_NAME   "Zeitzone" "Europe/Berlin"
    WEB_DIR=${WEB_DIR:-/var/www/ghostnode}; DB_ENGINE=${DB_ENGINE:-mariadb}
    DB_NAME=${DB_NAME:-ghostnode}; DB_USER=${DB_USER:-ghostnode}; FTP_USER=${FTP_USER:-poster}
    case "$ROLE" in dienste|alles) ;; *) die "Rolle muss dienste oder alles sein." ;; esac
    case "$RUN_MODE" in egg|systemd) ;; *) die "Betriebsart muss egg oder systemd sein." ;; esac
    case "$DB_ENGINE" in mariadb|sqlite) ;; *) die "Datenbank muss mariadb oder sqlite sein." ;; esac
    if [ "$RUN_MODE" = egg ]; then for u in "$HR_UPSTREAM" "$BOT_UPSTREAM"; do [[ "$u" =~ ^[0-9.]+:[0-9]{2,5}$ ]] || die "Zuweisung ungültig: $u (Form 127.0.0.1:18091)"; done; fi
    apply_role
    [[ "$DOMAIN" =~ ^[a-z0-9.-]+\.[a-z]{2,}$ ]] || die "Domain ungültig: $DOMAIN"
    save_kv "$CONF" "${CONF_VARS[@]}"; chmod 640 "$CONF"
    ok "gespeichert in $CONF"
}
ask_secrets() {
    step "Schlüssel (leer lassen = Funktion bleibt aus, später mit „$0 secrets“ nachtragen)"
    [ "$RUN_MODE" = egg ] && ok "Weiterleitung & Bot laufen in Pterodactyl – ihre Schlüssel trägst du dort in den Egg-Variablen ein."
    if want hr && [ "$RUN_MODE" = systemd ]; then ask HYPERATE_API_KEY "HypeRate-API-Key" "" s; fi
    if want bot && [ "$RUN_MODE" = systemd ]; then
        ask DISCORD_TOKEN         "Discord-Bot-Token" "" s
        ask DISCORD_APP_ID        "Discord-Application-ID" ""
        ask DISCORD_GUILD_ID      "Discord-Server-ID (optional)" ""
        ask RELEASE_CHANNEL_ID    "Kanal-ID für Release-Meldungen (optional)" ""
        ask DISCORD_CLIENT_SECRET "OAuth2-Client-Secret für das Bot-Dashboard" "" s
    fi
    if want web; then
        ask GN_DISCORD_CLIENT_ID     "Discord-Client-ID für „Mit Discord anmelden“ auf der Webseite (optional, sonst im Admin)" ""
        ask GN_DISCORD_CLIENT_SECRET "… und das zugehörige Client-Secret" "" s
        ask STATUS_WEBHOOK           "Status-Webhook (z. B. Discord, optional)" ""
    fi
    if want mail; then
        ask SMTP_HOST "SMTP-Server für E-Mails (leer = kein Mailversand)" ""
        if [ -n "${SMTP_HOST:-}" ]; then
            ask SMTP_PORT "SMTP-Port (587 = STARTTLS, 465 = TLS)" "587"
            ask SMTP_USER "SMTP-Benutzer" ""
            ask SMTP_PASS "SMTP-Passwort" "" s
            ask SMTP_FROM "Absender-Adresse" "noreply@$DOMAIN"
        fi
    fi
    if [ -n "${OLD_DB_HOST:-}" ]; then ask OLD_DB_PASS "Passwort der alten MySQL ($OLD_DB_USER@$OLD_DB_HOST)" "" s; fi
    if want voice && [ "$PTERO" = 0 ]; then ask MUMBLE_PASS "Mumble-Serverpasswort (leer = offen für alle)" "" s; fi
    DB_PASS=${DB_PASS:-$(rand 32)}; TURN_SECRET=${TURN_SECRET:-$(rand 48)}; FTP_PASS=${FTP_PASS:-$(rand 20)}; MUMBLE_SUPW=${MUMBLE_SUPW:-$(rand 24)}; BOT_SSO_SECRET=${BOT_SSO_SECRET:-$(rand 48)}; LIVE_SECRET=${LIVE_SECRET:-$(rand 48)}
    save_kv "$SECRETS" "${SECRET_VARS[@]}"; chmod 600 "$SECRETS"
    ok "gespeichert in $SECRETS (nur root lesbar)"
}

# ---------------------------------------------------------------- Hilfen
svc() {   # svc start|restart|enable|reload NAME
    local act=$1 name=$2
    if [ "$HAS_SYSTEMD" = 1 ]; then
        case "$act" in
            enable) systemctl enable --now "$name" >/dev/null 2>&1 || systemctl restart "$name" ;;
            *) systemctl "$act" "$name" ;;
        esac
    else
        case "$act" in enable) act=restart ;; esac
        if [ -x "/etc/init.d/$name" ]; then service "$name" "$act" >/dev/null 2>&1 || service "$name" start >/dev/null 2>&1 || true
        elif declare -F "nosd_$act" >/dev/null; then "nosd_$act" "$name"; fi
    fi
}
is_active() {
    if [ "$HAS_SYSTEMD" = 1 ]; then systemctl is-active --quiet "$1"; return; fi
    case "$1" in
        ghostnode-hr|ghostnode-bot) [ -f "/run/$1.pid" ] && kill -0 "$(cat "/run/$1.pid")" 2>/dev/null ;;
        *) service "$1" status >/dev/null 2>&1 ;;
    esac
}
# ohne systemd: eigene Node-Dienste direkt starten (nur Testbetrieb)
nosd_restart() {
    local name=$1 dir env
    case "$name" in
        ghostnode-hr)  dir=/opt/ghostnode/hyperate-relay; env=$CONF_DIR/hr.env; file=app.js ;;
        ghostnode-bot) dir=/opt/ghostnode/discord-bot;    env=$CONF_DIR/bot.env; file=bot.js ;;
        ghostnode-live) dir=/opt/ghostnode/live;          env=$CONF_DIR/live.env; file=live.js ;;
        *) return 0 ;;
    esac
    [ -f "/run/$name.pid" ] && kill "$(cat "/run/$name.pid")" 2>/dev/null || true
    ( set -a; . "$env"; set +a; cd "$dir" && exec runuser -u "$APP_USER" -- node "$file" ) >>"/var/log/$name.log" 2>&1 &
    echo $! > "/run/$name.pid"
}
nosd_start() { nosd_restart "$1"; }
php_ver() { php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;'; }
public_ip() { ip -4 route get 1.1.1.1 2>/dev/null | awk '/src/ {for (i=1;i<=NF;i++) if ($i=="src") print $(i+1)}' | head -1; }
dns_ok() {   # zeigt der Name auf diesen Server?
    local name=$1 ip; ip=$(getent ahostsv4 "$name" 2>/dev/null | awk 'NR==1{print $1}')
    [ -n "$ip" ] || return 1
    ip -4 addr | grep "inet $ip/" >/dev/null && return 0
    # hinter NAT: öffentliche IP vergleichen (nur wenn erreichbar)
    local pub; pub=$(curl -fsS --max-time 5 https://api.ipify.org 2>/dev/null || true)
    [ -n "$pub" ] && [ "$pub" = "$ip" ]
}
git_as() { runuser -u "$APP_USER" -- git "$@"; }

# ---------------------------------------------------------------- Umgebung erkennen (Pterodactyl, nginx, MySQL)
FRONT=apache; PTERO=0; WINGS_API=""; WINGS_SFTP=""
detect_env() {
    { [ -d /var/www/pterodactyl ] || [ -f /etc/pterodactyl/config.yml ] || [ -x /usr/local/bin/wings ]; } && PTERO=1
    if [ -f /etc/pterodactyl/config.yml ]; then
        WINGS_API=$(awk '/^api:/{a=1;next} a&&/^[^ ]/{a=0} a&&/^  port:/{print $2; exit}' /etc/pterodactyl/config.yml)
        WINGS_SFTP=$(awk '/bind_port:/{print $2; exit}' /etc/pterodactyl/config.yml)
    fi
    [ "$PTERO" = 1 ] && { WINGS_API=${WINGS_API:-8080}; WINGS_SFTP=${WINGS_SFTP:-2022}; }
    # nginx belegt schon 80/443 (z. B. Pterodactyl-Panel) – oder wir haben schon im nginx-Modus eingerichtet → nginx bleibt vorne
    if ss -ltnpH 2>/dev/null | awk '$4 ~ /:(80|443)$/' | grep '"nginx"' >/dev/null || [ -f /etc/nginx/sites-available/ghostnode.conf ]; then FRONT=nginx; fi
    return 0
}
# Rolle „dienste“: Webseite bleibt auf Plesk → Teile der Webseite auslassen, nginx vorne
apply_role() {
    ROLE=${ROLE:-alles}; RUN_MODE=${RUN_MODE:-systemd}; VOICE_HOST=${VOICE_HOST:-voice.${DOMAIN:-example.org}}
    if [ "$ROLE" = dienste ]; then SKIP="${SKIP:+$SKIP,}web,db,ftp,mail"; fi
    FRONT=nginx   # immer nginx als Reverse Proxy vorne (80/443, TLS) – Webseite (Apache intern), Bot, HypeRate dahinter
    return 0
}
port_owner() { { ss -ltnupH "sport = :$1" 2>/dev/null | grep -o 'users:(("[^"]*"' | head -1 | cut -d'"' -f2; } || true; }
need_port() {   # need_port PORT ERLAUBTE_PROZESSE... → abbrechen, wenn ein fremdes Programm den Port belegt
    local p=$1 who; shift; who=$(port_owner "$p")
    [ -z "$who" ] && return 0
    for a in "$@"; do [ "$who" = "$a" ] && return 0; done
    die "Port $p ist schon von „$who“ belegt (Spielserver? anderes Programm?). Bitte freigeben oder den Teil mit --skip=… auslassen."
}
# Pakete installieren, ohne dass sich der Dienst sofort startet (Apache neben nginx würde sonst um Port 80 kämpfen)
install_quiet() {
    local made=0
    [ -e /usr/sbin/policy-rc.d ] || { printf '#!/bin/sh\nexit 101\n' > /usr/sbin/policy-rc.d; chmod 755 /usr/sbin/policy-rc.d; made=1; }
    apt-get install -y -qq "$@" >/dev/null; local rc=$?
    [ "$made" = 1 ] && rm -f /usr/sbin/policy-rc.d
    return $rc
}

# ---------------------------------------------------------------- Teile
base_packages() {
    step "System & Pakete"
    local i=0
    while { command -v fuser >/dev/null && fuser /var/lib/dpkg/lock-frontend /var/lib/dpkg/lock >/dev/null 2>&1; } || pgrep -x 'apt|apt-get|dpkg|unattended-upgr' >/dev/null; do
        [ $i = 0 ] && echo "  … warte auf laufende System-Updates"; i=$((i+1)); [ $i -gt 120 ] && die "Paketverwaltung blockiert."; sleep 5
    done
    find /var/lib/dpkg/updates -type f -size 0 -delete 2>/dev/null || true
    [ -z "$(ls -A /var/lib/dpkg/updates 2>/dev/null)$(dpkg --audit 2>/dev/null)" ] || { dpkg --configure -a && apt-get -f install -y -qq >/dev/null; } || die "Paketverwaltung defekt – „dpkg --configure -a“ von Hand ausführen."
    timedatectl set-timezone "$TZ_NAME" 2>/dev/null || ln -sf "/usr/share/zoneinfo/$TZ_NAME" /etc/localtime
    apt-get update -qq
    apt-get install -y -qq ca-certificates curl gnupg git unzip zip acl openssl cron logrotate >/dev/null
    id "$APP_USER" >/dev/null 2>&1 || useradd --system --create-home --home-dir /home/$APP_USER --shell /bin/bash "$APP_USER"
    ok "Grundpakete, Benutzer „$APP_USER“, Zeitzone $TZ_NAME"
}

install_node() {
    local v; v=$(node -v 2>/dev/null | sed 's/^v//; s/\..*//' || true)
    if [ -n "$v" ] && [ "$v" -ge 22 ]; then ok "Node.js $(node -v) vorhanden"; return; fi
    step "Node.js 22 (NodeSource)"
    install -d -m 0755 /etc/apt/keyrings
    curl -fsSL https://deb.nodesource.com/gpgkey/nodesource-repo.gpg.key | gpg --dearmor --yes -o /etc/apt/keyrings/nodesource.gpg
    echo "deb [signed-by=/etc/apt/keyrings/nodesource.gpg] https://deb.nodesource.com/node_22.x nodistro main" > /etc/apt/sources.list.d/nodesource.list
    apt-get update -qq && apt-get install -y -qq nodejs >/dev/null
    ok "Node.js $(node -v)"
}

setup_web() {
    # Bot-Dashboard-Anmeldung über die Webseite (botdash.php): gemeinsamer Schlüssel, automatisch auf beiden Seiten
    local GN_BOT_SSO_SECRET="${BOT_SSO_SECRET:-}" GN_BOT_SSO_URL="https://${BOT_HOST:-discord.$DOMAIN}" GN_LIVE_SECRET="${LIVE_SECRET:-}"
    step "Webseite (Apache + PHP-FPM$([ "$FRONT" = nginx ] && echo ' hinter nginx'))"
    # Vorhandenes PHP (z. B. vom Pterodactyl-Panel) behalten: gleiche Version nachinstallieren, nichts umstellen
    local PV0="" pk; command -v php >/dev/null && PV0=$(php_ver)
    if [ -n "$PV0" ]; then pk="php$PV0-fpm php$PV0-cli php$PV0-sqlite3 php$PV0-mysql php$PV0-curl php$PV0-mbstring php$PV0-zip php$PV0-gd php$PV0-xml php$PV0-intl"
    else pk="php-fpm php-cli php-sqlite3 php-mysql php-curl php-mbstring php-zip php-gd php-xml php-intl"; fi
    # shellcheck disable=SC2086
    if [ "$FRONT" = nginx ]; then install_quiet apache2 $pk; else apt-get install -y -qq apache2 $pk >/dev/null; fi
    local PV; PV=$(php_ver)
    local mods; mods=$(php -m)
    for m in sodium pdo_sqlite pdo_mysql curl mbstring zip gd openssl; do grep -qix "$m" <<<"$mods" || die "PHP-Erweiterung $m fehlt."; done
    ok "PHP $PV mit allen Erweiterungen (sodium, pdo_sqlite, pdo_mysql, curl, mbstring, zip, gd)"

    # Code: Git-Checkout als Benutzer ghostnode (Updates = git pull, wie „Pull ausführen“ in Plesk)
    install -d -o "$APP_USER" -g "$APP_USER" -m 0755 "$WEB_DIR"
    # shellcheck disable=SC2010   # unten werden nur die festen Namen data/ und uploads/ geprüft
    if [ -d "$WEB_DIR/.git" ]; then
        if git_as -C "$WEB_DIR" pull --ff-only -q; then ok "Webseite aktualisiert"; else warn "git pull der Webseite fehlgeschlagen – lokale Änderungen? (git -C $WEB_DIR status)"; fi
    elif [ -z "$(ls -A "$WEB_DIR" | grep -vxE 'data|uploads')" ]; then
        # leer (oder nur data/ und uploads/ aus einem früheren Lauf/Umzug) → in ein Zwischenverzeichnis holen und dazulegen
        local tmp; tmp=$(mktemp -d /var/tmp/ghostnode-web.XXXXXX); chown "$APP_USER:" "$tmp"
        git_as clone -q "$REPO_WEB" "$tmp/web" || { rm -rf "$tmp"; die "Webseite konnte nicht geholt werden ($REPO_WEB) – Internet/DNS prüfen."; }
        cp -a -n "$tmp/web/." "$WEB_DIR/"; rm -rf "$tmp"
        ok "Webseite geholt ($REPO_WEB)"
    else warn "$WEB_DIR ist nicht leer und kein Git-Checkout – Code bleibt, wie er ist."; fi
    install -d -o "$APP_USER" -g "$APP_USER" -m 0750 "$WEB_DIR/data" "$WEB_DIR/data/keys"
    install -d -o "$APP_USER" -g "$APP_USER" -m 0755 "$WEB_DIR/uploads"
    chown -R "$APP_USER:$APP_USER" "$WEB_DIR"
    chmod 711 "$WEB_DIR/data"   # Apache muss nichts aus data/ lesen (per .htaccess gesperrt), PHP läuft als ghostnode

    # PHP-FPM-Pool: läuft als ghostnode, Grenzen für Uploads (Poster, Galerie), Umgebungsvariablen für die Seite
    local POOL=/etc/php/$PV/fpm/pool.d/ghostnode.conf
    {
        echo "; erzeugt von ghostnode-root.sh – Änderungen hier werden beim nächsten Lauf überschrieben"
        echo "[ghostnode]"
        echo "user = $APP_USER"; echo "group = $APP_USER"
        echo "listen = /run/php/ghostnode.sock"; echo "listen.owner = www-data"; echo "listen.group = www-data"; echo "listen.mode = 0660"
        echo "pm = dynamic"; echo "pm.max_children = 24"; echo "pm.start_servers = 4"; echo "pm.min_spare_servers = 2"; echo "pm.max_spare_servers = 8"; echo "pm.max_requests = 1000"
        echo "php_admin_value[upload_max_filesize] = 64M"; echo "php_admin_value[post_max_size] = 70M"; echo "php_admin_value[memory_limit] = 384M"
        echo "php_admin_value[max_execution_time] = 120"; echo "php_admin_value[date.timezone] = $TZ_NAME"; echo "php_admin_flag[expose_php] = off"
        echo "php_admin_value[session.cookie_secure] = 1"; echo "php_admin_value[session.cookie_httponly] = 1"
        [ -x /usr/bin/msmtp ] && echo "php_admin_value[sendmail_path] = \"/usr/bin/msmtp -t -i\""
        for v in GN_DISCORD_CLIENT_ID GN_DISCORD_CLIENT_SECRET STATUS_WEBHOOK REDDIT_CLIENT_ID REDDIT_CLIENT_SECRET TWITCH_EXT_CLIENT_ID TWITCH_EXT_SECRET GN_BOT_SSO_SECRET GN_BOT_SSO_URL GN_LIVE_SECRET; do
            [ -n "${!v:-}" ] && printf 'env[%s] = "%s"\n' "$v" "${!v//\"/}"
        done
        echo 'env[PATH] = /usr/local/bin:/usr/bin:/bin'
    } > "$POOL"
    chmod 640 "$POOL"
    # Standard-Pool „www“ bleibt (das Pterodactyl-Panel nutzt ihn); ältere Script-Versionen hatten ihn abgeschaltet → zurückholen
    if [ -f "/etc/php/$PV/fpm/pool.d/www.conf.aus" ] && [ ! -f "/etc/php/$PV/fpm/pool.d/www.conf" ]; then mv "/etc/php/$PV/fpm/pool.d/www.conf.aus" "/etc/php/$PV/fpm/pool.d/www.conf"; fi
    # gleiche Variablen für Cron (Kommandozeile)
    save_kv "$CONF_DIR/web.env" GN_DISCORD_CLIENT_ID GN_DISCORD_CLIENT_SECRET STATUS_WEBHOOK REDDIT_CLIENT_ID REDDIT_CLIENT_SECRET TWITCH_EXT_CLIENT_ID TWITCH_EXT_SECRET GN_BOT_SSO_SECRET GN_BOT_SSO_URL GN_LIVE_SECRET
    chown root:"$APP_USER" "$CONF_DIR/web.env" "$CONF_DIR"; chmod 640 "$CONF_DIR/web.env"; chmod 750 "$CONF_DIR"
    # Kommandozeilen-PHP: gleiche Grenzen
    printf 'memory_limit = 384M\ndate.timezone = %s\n%s\n' "$TZ_NAME" "$([ -x /usr/bin/msmtp ] && echo 'sendmail_path = "/usr/bin/msmtp -t -i"')" > "/etc/php/$PV/cli/conf.d/90-ghostnode.ini"
    svc restart "php$PV-fpm"

    a2enmod -q proxy proxy_fcgi proxy_http setenvif rewrite headers expires deflate ssl http2 mime >/dev/null
    a2dismod -q mpm_prefork >/dev/null 2>&1 || true; a2enmod -q mpm_event >/dev/null 2>&1 || true
    a2disconf -q "php$PV-fpm" >/dev/null 2>&1 || true
    cat > /etc/apache2/conf-available/ghostnode-security.conf <<'EOF'
ServerTokens Prod
ServerSignature Off
TraceEnable Off
Header always set X-Content-Type-Options "nosniff"
Header always set Referrer-Policy "strict-origin-when-cross-origin"
EOF
    a2enconf -q ghostnode-security >/dev/null

    local vh='*:80' extra='    Protocols h2 http/1.1'
    if [ "$FRONT" = nginx ]; then
        need_port "$BACKEND_PORT" apache2
        # Apache nur intern; nginx (vorne) reicht die echte Absender-IP und https weiter
        [ -f /etc/apache2/ports.conf.orig ] || cp /etc/apache2/ports.conf /etc/apache2/ports.conf.orig
        printf '# erzeugt von ghostnode-root.sh – Apache läuft hinter nginx\nListen 127.0.0.1:%s\n' "$BACKEND_PORT" > /etc/apache2/ports.conf
        a2enmod -q remoteip >/dev/null
        vh="127.0.0.1:$BACKEND_PORT"
        extra=$(printf '    RemoteIPHeader X-Forwarded-For\n    RemoteIPInternalProxy 127.0.0.1\n    SetEnvIf X-Forwarded-Proto "^https$" HTTPS=on')
        for f in /etc/apache2/sites-available/ghostnode-hr.conf /etc/apache2/sites-available/ghostnode-bot.conf /etc/apache2/sites-available/ghostnode-turn.conf; do
            [ -f "$f" ] && { a2dissite -q "$(basename "$f" .conf)" >/dev/null 2>&1 || true; rm -f "$f"; }
        done
    fi
    cat > /etc/apache2/sites-available/ghostnode.conf <<EOF
# erzeugt von ghostnode-root.sh
<VirtualHost $vh>
    ServerName $DOMAIN
    ServerAlias www.$DOMAIN
    DocumentRoot $WEB_DIR
    DirectoryIndex index.php index.html
$extra
    <Directory $WEB_DIR>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
        CGIPassAuth On
    </Directory>
    # Git-Daten, Werkzeuge und Daten nie ausliefern (zusätzlich zur .htaccess)
    <DirectoryMatch "^$WEB_DIR/(\.git|data|inc|tools|server)(/|$)">
        Require all denied
    </DirectoryMatch>
    <FilesMatch "\.php$">
        SetHandler "proxy:unix:/run/php/ghostnode.sock|fcgi://localhost"
    </FilesMatch>
    LimitRequestBody 73400320
    ErrorLog \${APACHE_LOG_DIR}/ghostnode-error.log
    CustomLog \${APACHE_LOG_DIR}/ghostnode-access.log combined
</VirtualHost>
EOF
    a2ensite -q ghostnode >/dev/null; a2dissite -q 000-default >/dev/null 2>&1 || true
    apache2ctl configtest >/dev/null 2>&1 || die "Apache-Konfiguration fehlerhaft: $(apache2ctl configtest 2>&1 | tail -3)"
    if [ "$FRONT" = nginx ]; then svc enable apache2; svc restart apache2; nginx_site ghostnode "$DOMAIN www.$DOMAIN" "$BACKEND_PORT" web
    else svc enable apache2; svc reload apache2 2>/dev/null || svc restart apache2; fi
    ok "Apache: $DOMAIN → $WEB_DIR$([ "$FRONT" = nginx ] && echo " (über nginx → 127.0.0.1:$BACKEND_PORT)")"
}

# nginx als Reverse Proxy vorne: ein Server-Block je Name, Ziel = interner Port (Apache, Bot, HypeRate, Panel-Zuweisung)
nginx_site() {   # nginx_site NAME "HOSTS" PORT web|sse|plain|redirect
    local name=$1 hosts=$2 port=$3 kind=$4 f=/etc/nginx/sites-available/$1.conf up
    up=$port; [[ "$up" == *:* ]] || up="127.0.0.1:$up"
    command -v nginx >/dev/null || { apt-get install -y -qq nginx >/dev/null; rm -f /etc/nginx/sites-enabled/default; }
    {
        echo "# erzeugt von ghostnode-root.sh – GhostNode neben anderen nginx-Seiten (z. B. Pterodactyl-Panel)"
        echo "server {"
        echo "    listen 80;"; [ -s /proc/net/if_inet6 ] && echo "    listen [::]:80;"
        echo "    server_name $hosts;"
        if [ "$kind" = redirect ]; then echo "    return 302 https://$DOMAIN\$request_uri;"; echo "}"
        else
            echo "    access_log /var/log/nginx/$name.access.log;"; echo "    error_log /var/log/nginx/$name.error.log;"
            echo "    client_max_body_size 70m;"
            if [ "$kind" = web ]; then   # Live-Dienst (WebSocket) auf derselben Domain
                echo "    location /live/ {"
                echo "        proxy_pass http://127.0.0.1:$LIVE_PORT;"
                echo "        proxy_http_version 1.1;"
                echo "        proxy_set_header Upgrade \$http_upgrade;"
                echo "        proxy_set_header Connection \"upgrade\";"
                echo "        proxy_set_header Host \$host;"
                echo "        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;"
                echo "        proxy_read_timeout 3600s;"
                echo "        proxy_send_timeout 3600s;"
                echo "    }"
            fi
            echo "    location / {"
            echo "        proxy_pass http://$up;"
            echo "        proxy_http_version 1.1;"
            echo "        proxy_set_header Host \$host;"
            echo "        proxy_set_header X-Real-IP \$remote_addr;"
            echo "        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;"
            echo "        proxy_set_header X-Forwarded-Proto \$scheme;"
            echo "        proxy_set_header Connection \"\";"
            if [ "$kind" = sse ]; then echo "        proxy_buffering off;"; echo "        proxy_cache off;"; echo "        proxy_read_timeout 3600s;"; echo "        gzip off;"
            else echo "        proxy_read_timeout 120s;"; fi
            echo "    }"; echo "}"
        fi
    } > "$f"
    ln -sf "$f" "/etc/nginx/sites-enabled/$name.conf"
    if ! nginx -t >/dev/null 2>&1; then
        # sofort zurücknehmen – nginx (und damit z. B. das Pterodactyl-Panel) läuft mit der alten Konfiguration weiter
        local why; why=$(nginx -t 2>&1 | tail -2 || true)
        rm -f "/etc/nginx/sites-enabled/$name.conf" "$f"
        die "nginx-Konfiguration fehlerhaft ($name) – zurückgenommen, nichts verändert: $why"
    fi
    if is_active nginx; then svc reload nginx 2>/dev/null || svc restart nginx; else svc enable nginx 2>/dev/null || true; svc restart nginx; fi   # z. B. frisch installiert, während Apache noch auf 80 lag
}

setup_db() {
    step "Datenbank ($DB_ENGINE)"
    local cfg="$WEB_DIR/data/config.php"
    if [ "$DB_ENGINE" = sqlite ]; then
        [ -f "$cfg" ] || ok "SQLite – nichts einzurichten (Datei liegt in data/)"
        return
    fi
    local dbsvc=mariadb
    if dpkg -l 'mysql-server*' 2>/dev/null | grep -q '^ii'; then
        dbsvc=mysql; ok "vorhandene MySQL wird mitbenutzt (z. B. vom Pterodactyl-Panel) – nur eigene Datenbank + Benutzer"
    else apt-get install -y -qq mariadb-server >/dev/null; fi
    svc enable "$dbsvc"
    for i in $(seq 1 30); do mysqladmin ping >/dev/null 2>&1 && break; sleep 1; done
    mysql --protocol=socket -uroot <<EOF
CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';
ALTER USER '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';
GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'localhost';
FLUSH PRIVILEGES;
EOF
    # Zugang für die Webseite – gleiches Format wie Admin → Datenbank (data/config.php)
    if [ -f "$cfg" ] && ! grep -q '"driver": "mysql"' "$cfg"; then
        warn "data/config.php nutzt schon SQLite – nicht überschrieben. Umzug zu MariaDB in der Seite: Admin → Datenbank (Zugang steht in $CRED)."
    else
        DB_JSON=$(DB_NAME="$DB_NAME" DB_USER="$DB_USER" DB_PASS="$DB_PASS" php -r 'echo json_encode(["driver"=>"mysql","host"=>"localhost","port"=>3306,"name"=>getenv("DB_NAME"),"user"=>getenv("DB_USER"),"pass"=>getenv("DB_PASS"),"prefix"=>"gn_","socket"=>"/run/mysqld/mysqld.sock"], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);')
        printf '<?php http_response_code(404); exit; ?>\n%s' "$DB_JSON" > "$cfg"
        chown "$APP_USER:$APP_USER" "$cfg"; chmod 600 "$cfg"
    fi
    ok "MariaDB: Datenbank „$DB_NAME“, Benutzer „$DB_USER“ (nur lokal)"
}

# ---------------------------------------------------------------- alte MySQL: einmal übernehmen, danach als Backup-Ziel
old_db_cnf() {   # Zugang zur alten MySQL (nur root lesbar)
    umask 077
    printf '[client]\nhost=%s\nport=%s\nuser=%s\npassword="%s"\ndefault-character-set=utf8mb4\n' "$OLD_DB_HOST" "${OLD_DB_PORT:-3306}" "$OLD_DB_USER" "${OLD_DB_PASS//\"/\\\"}" > "$CONF_DIR/old-db.cnf"
    chmod 600 "$CONF_DIR/old-db.cnf"; umask 022
}
setup_olddb() {
    [ -n "${OLD_DB_HOST:-}" ] && [ "$DB_ENGINE" = mariadb ] || return 0
    step "Alte MySQL ($OLD_DB_USER@$OLD_DB_HOST/$OLD_DB_NAME)"
    old_db_cnf
    local err
    if ! err=$(mysql --defaults-extra-file="$CONF_DIR/old-db.cnf" --connect-timeout=15 -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE()" "$OLD_DB_NAME" 2>&1); then
        warn "Alte MySQL nicht erreichbar: ${err##*: } – in Plesk beim Datenbank-Benutzer Fernzugriff erlauben (Host $(public_ip) oder beliebig), Port $OLD_DB_PORT beim alten Anbieter offen? Danach: $0 olddb"
        return 0
    fi
    ok "erreichbar – $err Tabellen"
    mkdir -p /var/lib/ghostnode
    if [ -f /var/lib/ghostnode/old-db-imported ]; then ok "schon übernommen am $(cat /var/lib/ghostnode/old-db-imported) – neu holen mit: $0 olddb --force"
    else
        ( MIG_DB_ONLY=1 MIG_FROM=$OLD_DB_HOST MIG_SRC_PORT=${OLD_DB_PORT:-3306} MIG_SRC_DB=$OLD_DB_NAME MIG_SRC_USER=$OLD_DB_USER MIG_SRC_PASS=$OLD_DB_PASS MIG_DB="" MIG_FILES=""; export MIG_SRC_PASS; cmd_migrate ) \
            && date '+%d.%m.%Y %H:%M' > /var/lib/ghostnode/old-db-imported \
            || { warn "Übernahme der alten MySQL fehlgeschlagen – vorheriger Stand ist wiederhergestellt. Erneut: $0 olddb"; return 0; }
        warn "Ohne die Schlüsseldateien aus Plesk (data/keys) lassen sich verschlüsselt gespeicherte Werte nicht lesen: im Admin „Mit Discord anmelden“ (Client-Secret) neu eintragen und das VRChat-Dienstkonto neu verbinden."
    fi
    [ "${OLD_DB_MIRROR:-0}" = 1 ] && ok "Alte MySQL ist ab jetzt Backup-Ziel: jede Nacht (03:17) wird die neue Datenbank dorthin gespiegelt" || ok "Alte MySQL bleibt unverändert (kein Spiegeln)"
}

setup_live() {
    step "Live-Dienst (Fernsteuerung in Echtzeit, https://$DOMAIN/live/)"
    local dir=/opt/ghostnode/live src=/opt/ghostnode/src/vrc_nexus
    install -d -o "$APP_USER" -g "$APP_USER" /opt/ghostnode /opt/ghostnode/src "$dir"
    if [ -d "$src/.git" ]; then git_as -C "$src" sparse-checkout add apps/nexus-live 2>/dev/null || true; git_as -C "$src" pull --ff-only -q || warn "git pull (vrc_nexus) fehlgeschlagen – alte Version bleibt."
    else
        git_as clone -q --depth 1 --filter=blob:none --sparse "$REPO_NEXUS" "$src" || die "Live-Dienst konnte nicht geholt werden ($REPO_NEXUS)."
        git_as -C "$src" sparse-checkout set apps/hyperate-relay apps/nexus-live
    fi
    [ -f "$src/apps/nexus-live/live.js" ] || { warn "apps/nexus-live fehlt im Repo – Live-Dienst übersprungen (App nutzt den Briefkasten)."; return 0; }
    install -o "$APP_USER" -g "$APP_USER" -m 0644 "$src/apps/nexus-live/live.js" "$src/apps/nexus-live/package.json" "$dir/"
    local PORT=$LIVE_PORT HOST=127.0.0.1 SITE_BACKEND="http://127.0.0.1:$BACKEND_PORT" SITE_HOST="$DOMAIN"
    save_kv "$CONF_DIR/live.env" LIVE_SECRET PORT HOST SITE_BACKEND SITE_HOST; chmod 600 "$CONF_DIR/live.env"
    need_port "$LIVE_PORT" node
    write_unit ghostnode-live "GhostNode Live (Echtzeit für VRC Nexus)" "$dir" live.js "$CONF_DIR/live.env"
    svc enable ghostnode-live; svc restart ghostnode-live
    ok "läuft auf 127.0.0.1:$LIVE_PORT → wss://$DOMAIN/live/ws"
}

setup_hr() {
    step "HypeRate-Weiterleitung ($HR_HOST)"
    if [ "$RUN_MODE" = egg ]; then
        nginx_site ghostnode-hr "$HR_HOST" "$HR_UPSTREAM" sse
        ok "läuft in Pterodactyl (Egg „GhostNode HypeRate-Weiterleitung“, Zuweisung $HR_UPSTREAM) → https://$HR_HOST"
        return 0
    fi
    local dir=/opt/ghostnode/hyperate-relay src=/opt/ghostnode/src/vrc_nexus
    install -d -o "$APP_USER" -g "$APP_USER" /opt/ghostnode /opt/ghostnode/src "$dir"
    if [ -d "$src/.git" ]; then git_as -C "$src" pull --ff-only -q || warn "git pull (vrc_nexus) fehlgeschlagen – alte Version bleibt."
    else
        rm -rf "$src"
        git_as clone -q --depth 1 --filter=blob:none --sparse "$REPO_NEXUS" "$src" || die "HypeRate-Weiterleitung konnte nicht geholt werden ($REPO_NEXUS)."
        git_as -C "$src" sparse-checkout set apps/hyperate-relay
    fi
    install -o "$APP_USER" -g "$APP_USER" -m 0644 "$src/apps/hyperate-relay/app.js" "$src/apps/hyperate-relay/package.json" "$dir/"
    save_kv "$CONF_DIR/hr.env" HYPERATE_API_KEY; printf 'PORT=%s\nHOST=127.0.0.1\n' "$HR_PORT" >> "$CONF_DIR/hr.env"; chmod 600 "$CONF_DIR/hr.env"
    need_port "$HR_PORT" node
    write_unit ghostnode-hr "GhostNode HypeRate-Weiterleitung" "$dir" app.js "$CONF_DIR/hr.env"
    proxy_vhost ghostnode-hr "$HR_HOST" "$HR_PORT" sse
    [ -n "${HYPERATE_API_KEY:-}" ] || warn "Kein HypeRate-Key – die Weiterleitung läuft, verbindet sich aber nicht (später: $0 secrets)."
    svc enable ghostnode-hr; svc restart ghostnode-hr
    ok "läuft auf 127.0.0.1:$HR_PORT → https://$HR_HOST"
}

setup_bot() {
    step "Discord-Bot ($BOT_HOST)"
    if [ "$RUN_MODE" = egg ]; then
        nginx_site ghostnode-bot "$BOT_HOST" "$BOT_UPSTREAM" plain
        ok "läuft in Pterodactyl (Egg „GhostNode Discord-Bot“, Zuweisung $BOT_UPSTREAM) → https://$BOT_HOST"
        want web && ok "Im Panel als Variable „Schlüssel GhostNode-Anmeldung“ (BOT_SSO_SECRET) eintragen – steht in $CRED"
        ok "Im Panel als Variable „Dashboard-Adresse“: https://$BOT_HOST – im Discord-Portal Redirect https://$BOT_HOST/callback"
        return 0
    fi
    local dir=/opt/ghostnode/discord-bot
    install -d -o "$APP_USER" -g "$APP_USER" /opt/ghostnode
    if [ -d "$dir/.git" ]; then git_as -C "$dir" pull --ff-only -q || warn "git pull (Bot) fehlgeschlagen – alte Version bleibt."
    elif [ -d "$dir" ] && [ -n "$(ls -A "$dir" 2>/dev/null)" ]; then warn "$dir ist kein Git-Checkout – nicht aktualisiert."
    else install -d -o "$APP_USER" -g "$APP_USER" "$dir"; git_as clone -q "$REPO_BOT" "$dir" || die "Bot konnte nicht geholt werden ($REPO_BOT)."; fi
    install -d -o "$APP_USER" -g "$APP_USER" -m 0750 "$dir/data"
    local DASHBOARD_URL="https://$BOT_HOST" NEXUS_BASE="https://$DOMAIN" HYPERATE_RELAY="https://$HR_HOST" BOT_DATA="$dir/data" PORT=$BOT_PORT HOST=127.0.0.1
    save_kv "$CONF_DIR/bot.env" DISCORD_TOKEN DISCORD_APP_ID DISCORD_GUILD_ID RELEASE_CHANNEL_ID DISCORD_CLIENT_SECRET DASHBOARD_URL NEXUS_BASE HYPERATE_RELAY BOT_DATA PORT HOST BOT_SSO_SECRET
    chmod 600 "$CONF_DIR/bot.env"
    need_port "$BOT_PORT" node
    write_unit ghostnode-bot "GhostNode Discord-Bot (VRC Nexus)" "$dir" bot.js "$CONF_DIR/bot.env"
    proxy_vhost ghostnode-bot "$BOT_HOST" "$BOT_PORT"
    [ -n "${DISCORD_TOKEN:-}" ] || warn "Kein Bot-Token – der Bot zeigt nur eine Hinweisseite (später: $0 secrets)."
    [ -n "${DISCORD_CLIENT_SECRET:-}" ] || warn "Kein OAuth2-Secret – Dashboard-Anmeldung aus. Im Discord-Portal Redirect https://$BOT_HOST/callback eintragen."
    svc enable ghostnode-bot; svc restart ghostnode-bot
    ok "läuft auf 127.0.0.1:$BOT_PORT → https://$BOT_HOST"
}

write_unit() {   # write_unit NAME BESCHREIBUNG VERZEICHNIS DATEI ENV
    [ "$HAS_SYSTEMD" = 1 ] || return 0
    cat > "/etc/systemd/system/$1.service" <<EOF
# erzeugt von ghostnode-root.sh – Geheimnisse stehen in $5 (chmod 600), nie hier
[Unit]
Description=$2
After=network-online.target
Wants=network-online.target

[Service]
User=$APP_USER
WorkingDirectory=$3
EnvironmentFile=$5
ExecStart=/usr/bin/node $4
Restart=always
RestartSec=5
NoNewPrivileges=true
ProtectSystem=strict
ProtectHome=true
ReadWritePaths=$3
PrivateTmp=true
PrivateDevices=true
ProtectKernelTunables=true
ProtectControlGroups=true
RestrictSUIDSGID=true
LockPersonality=true

[Install]
WantedBy=multi-user.target
EOF
    systemctl daemon-reload
}

proxy_vhost() {   # proxy_vhost NAME HOST PORT [sse]
    local name=$1 host=$2 port=$3 sse=${4:-}
    if [ "$FRONT" = nginx ]; then nginx_site "$name" "$host" "$port" "${sse:-plain}"; return 0; fi
    cat > "/etc/apache2/sites-available/$name.conf" <<EOF
# erzeugt von ghostnode-root.sh
<VirtualHost *:80>
    ServerName $host
    ProxyPreserveHost On
    ProxyRequests Off
    # echte Absender-IP fest setzen (nicht vom Browser übernehmen) – für Limits je IP
    RequestHeader set X-Real-IP "%{REMOTE_ADDR}s"
    RequestHeader set X-Forwarded-Proto "https"
$( [ -n "$sse" ] && printf '    # Server-Sent Events: nicht puffern, nicht komprimieren, lange offen halten\n    SetEnv no-gzip 1\n    ProxyTimeout 3600\n    ProxyPass / http://127.0.0.1:%s/ flushpackets=on keepalive=On\n' "$port" || printf '    ProxyTimeout 60\n    ProxyPass / http://127.0.0.1:%s/\n' "$port" )
    ProxyPassReverse / http://127.0.0.1:$port/
    ErrorLog \${APACHE_LOG_DIR}/$name-error.log
    CustomLog \${APACHE_LOG_DIR}/$name-access.log combined
</VirtualHost>
EOF
    a2ensite -q "$name" >/dev/null
    apache2ctl configtest >/dev/null 2>&1 || die "Apache-Konfiguration fehlerhaft ($name): $(apache2ctl configtest 2>&1 | tail -3)"
    svc reload apache2 2>/dev/null || svc restart apache2
}

setup_turn() {
    step "TURN/STUN für Anrufe ($TURN_HOST)"
    need_port 3478 turnserver; need_port 5349 turnserver
    apt-get install -y -qq coturn >/dev/null
    install -d -m 0750 -o root -g turnserver /etc/coturn/certs 2>/dev/null || install -d -m 0750 /etc/coturn/certs
    local tls=""; [ -s /etc/coturn/certs/fullchain.pem ] && tls=1
    cat > /etc/turnserver.conf <<EOF
# erzeugt von ghostnode-root.sh – Zugangsdaten werden von der Webseite je Konto für 6 h erzeugt (HMAC, use-auth-secret)
listening-port=3478
$( [ -n "$tls" ] && printf 'tls-listening-port=5349\ncert=/etc/coturn/certs/fullchain.pem\npkey=/etc/coturn/certs/privkey.pem\nno-tlsv1\nno-tlsv1_1\n' )
fingerprint
no-rfc5780
use-auth-secret
static-auth-secret=$TURN_SECRET
realm=$DOMAIN
server-name=$TURN_HOST
min-port=$TURN_MIN
max-port=$TURN_MAX
total-quota=200
user-quota=12
stale-nonce=600
no-cli
no-multicast-peers
no-loopback-peers
# keine Weiterleitung in private Netze (Schutz vor Missbrauch als Brücke ins Server-Netz)
denied-peer-ip=0.0.0.0-0.255.255.255
denied-peer-ip=10.0.0.0-10.255.255.255
denied-peer-ip=100.64.0.0-100.127.255.255
denied-peer-ip=127.0.0.0-127.255.255.255
denied-peer-ip=169.254.0.0-169.254.255.255
denied-peer-ip=172.16.0.0-172.31.255.255
denied-peer-ip=192.168.0.0-192.168.255.255
denied-peer-ip=::1
denied-peer-ip=fc00::-fdff:ffff:ffff:ffff:ffff:ffff:ffff:ffff
denied-peer-ip=fe80::-febf:ffff:ffff:ffff:ffff:ffff:ffff:ffff
simple-log
EOF
    chmod 640 /etc/turnserver.conf; chgrp turnserver /etc/turnserver.conf 2>/dev/null || true
    [ -f /etc/default/coturn ] && sed -i 's/^#\?TURNSERVER_ENABLED=.*/TURNSERVER_ENABLED=1/' /etc/default/coturn
    # Webseite: TURN-Adressen + Geheimnis (data/keys/turn.json, nur für PHP lesbar)
    local urls="\"stun:$TURN_HOST:3478\",\"turn:$TURN_HOST:3478?transport=udp\",\"turn:$TURN_HOST:3478?transport=tcp\""
    [ -n "$tls" ] && urls="$urls,\"turns:$TURN_HOST:5349?transport=tcp\""
    if want web; then
        printf '{"urls":[%s],"secret":"%s"}\n' "$urls" "$TURN_SECRET" > "$WEB_DIR/data/keys/turn.json"
        chown "$APP_USER:$APP_USER" "$WEB_DIR/data/keys/turn.json"; chmod 600 "$WEB_DIR/data/keys/turn.json"
    else
        # Webseite liegt auf Plesk: Datei zum Hochladen nach data/keys/turn.json der Plesk-Seite
        printf '{"urls":[%s],"secret":"%s"}\n' "$urls" "$TURN_SECRET" > "$CONF_DIR/turn-fuer-plesk.json"; chmod 600 "$CONF_DIR/turn-fuer-plesk.json"
    fi
    svc enable coturn; svc restart coturn
    ok "coturn: 3478 (UDP/TCP)$([ -n "$tls" ] && echo ', 5349 (TLS)'), Relais-Ports $TURN_MIN–$TURN_MAX"
    if want web; then ok "Webseite kennt den Server (data/keys/turn.json)"
    else warn "Plesk: $CONF_DIR/turn-fuer-plesk.json als data/keys/turn.json in die Webseite hochladen (Dateimanager, Rechte 600) – dann gehen Anrufe über diesen Server."; fi
    [ -n "$tls" ] || warn "TURN ohne TLS – nach „$0 certs“ wird 5349 (TLS) automatisch ergänzt."
}

setup_voice() {
    step "Mumble-Sprachserver ($VOICE_HOST)"
    if [ "$PTERO" = 1 ]; then
        ok "Pterodactyl erkannt → im Panel einen Server mit dem mitgelieferten Egg „Mumble Server“ (Nest „Voice Servers“) anlegen,"
        ok "Zuweisung <Server-IP>:64738, DNS $VOICE_HOST auf diese IP. Mumble verbindet direkt (ohne nginx); Docker-Ports gehen an ufw vorbei."
        return 0
    fi
    need_port 64738 mumble-server murmurd
    apt-get install -y -qq mumble-server >/dev/null
    local ini=/etc/mumble-server.ini bin
    bin=$(command -v mumble-server || command -v murmurd || echo /usr/sbin/murmurd)
    [ -f "$ini.orig" ] || cp "$ini" "$ini.orig"
    setini() { if grep -qE "^;?$1=" "$ini"; then sed -i "s|^;\?$1=.*|$1=$2|" "$ini"; else echo "$1=$2" >> "$ini"; fi; }
    setini port 64738
    setini users 100
    setini bandwidth 72000
    setini serverpassword "${MUMBLE_PASS:-}"
    setini registerName "GhostNode"
    setini welcometext "\"<b>Willkommen bei GhostNode</b> – sei nett zueinander 💙\""
    setini allowhtml true
    if [ -s /etc/ghostnode/tls/fullchain.pem ]; then
        setini sslCert /etc/ghostnode/tls/fullchain.pem; setini sslKey /etc/ghostnode/tls/privkey.pem
        chgrp mumble-server /etc/ghostnode/tls /etc/ghostnode/tls/*.pem 2>/dev/null || true; chmod 750 /etc/ghostnode/tls
    fi
    svc enable mumble-server; svc restart mumble-server
    sleep 2
    # SuperUser-Passwort setzen (Verwaltung im Mumble-Client: als „SuperUser“ anmelden)
    runuser -u mumble-server -- "$bin" -ini "$ini" -supw "$MUMBLE_SUPW" >/dev/null 2>&1 || "$bin" -ini "$ini" -supw "$MUMBLE_SUPW" >/dev/null 2>&1 || warn "SuperUser-Passwort ließ sich nicht setzen – später: $bin -ini $ini -supw <Passwort>"
    svc restart mumble-server
    ok "Mumble auf Port 64738 (TCP/UDP)$([ -n "${MUMBLE_PASS:-}" ] && echo ', mit Serverpasswort')$([ -s /etc/ghostnode/tls/fullchain.pem ] && echo ', Zertifikat von Lets Encrypt'); SuperUser-Passwort in $CRED"
}

setup_ftp() {
    step "FTPS-Eingang für Poster"
    need_port 21 vsftpd
    apt-get install -y -qq vsftpd libpam-pwdfile >/dev/null
    local inbox="$WEB_DIR/data/uploads/posters/inbox"
    install -d -o "$APP_USER" -g "$APP_USER" -m 0755 "$WEB_DIR/data/uploads" "$WEB_DIR/data/uploads/posters" "$inbox"
    install -d -m 0755 /etc/vsftpd
    # virtueller Benutzer → arbeitet als ghostnode, sieht nur den Poster-Eingang
    printf '%s:%s\n' "$FTP_USER" "$(openssl passwd -6 "$FTP_PASS")" > /etc/vsftpd/ftpd.passwd; chmod 600 /etc/vsftpd/ftpd.passwd
    printf 'auth required pam_pwdfile.so pwdfile=/etc/vsftpd/ftpd.passwd\naccount required pam_permit.so\n' > /etc/pam.d/vsftpd.ghostnode
    local cert=/etc/ssl/certs/ssl-cert-snakeoil.pem key=/etc/ssl/private/ssl-cert-snakeoil.key
    [ -s /etc/coturn/certs/fullchain.pem ] && { cert=/etc/ghostnode/tls/fullchain.pem; key=/etc/ghostnode/tls/privkey.pem; }
    [ -s "$cert" ] || { apt-get install -y -qq ssl-cert >/dev/null; make-ssl-cert generate-default-snakeoil --force-overwrite >/dev/null 2>&1 || true; }
    cat > /etc/vsftpd.conf <<EOF
# erzeugt von ghostnode-root.sh – nur der Poster-Eingang, nur verschlüsselt (FTPS)
listen=YES
listen_ipv6=NO
anonymous_enable=NO
local_enable=YES
write_enable=YES
local_umask=022
guest_enable=YES
guest_username=$APP_USER
virtual_use_local_privs=YES
local_root=$inbox
chroot_local_user=YES
allow_writeable_chroot=YES
pam_service_name=vsftpd.ghostnode
hide_ids=YES
dirmessage_enable=NO
xferlog_enable=YES
pasv_enable=YES
pasv_min_port=$FTP_PASV_MIN
pasv_max_port=$FTP_PASV_MAX
ssl_enable=YES
allow_anon_ssl=NO
force_local_data_ssl=YES
force_local_logins_ssl=YES
ssl_tlsv1=NO
ssl_sslv2=NO
ssl_sslv3=NO
require_ssl_reuse=NO
ssl_ciphers=HIGH
rsa_cert_file=$cert
rsa_private_key_file=$key
max_clients=20
max_per_ip=4
EOF
    svc enable vsftpd; svc restart vsftpd
    ok "FTPS: Benutzer „$FTP_USER“ (Passwort in $CRED), Ordner = Poster-Eingang (je Slot ein Unterordner)"
}

setup_mail() {
    step "Mailversand"
    if [ -z "${SMTP_HOST:-}" ]; then
        [ -f /etc/msmtprc ] && mv /etc/msmtprc /etc/msmtprc.aus
        warn "Kein SMTP-Server – Status-E-Mails werden nicht verschickt (später: $0 secrets)."; return 0
    fi
    apt-get install -y -qq msmtp msmtp-mta >/dev/null
    local starttls=on; [ "${SMTP_PORT:-587}" = 465 ] && starttls=off
    cat > /etc/msmtprc <<EOF
# erzeugt von ghostnode-root.sh
defaults
auth on
tls on
tls_starttls $starttls
tls_trust_file /etc/ssl/certs/ca-certificates.crt
syslog LOG_MAIL
account default
host $SMTP_HOST
port ${SMTP_PORT:-587}
from ${SMTP_FROM:-noreply@$DOMAIN}
user $SMTP_USER
password "$(printf '%s' "$SMTP_PASS" | sed 's/\\/\\\\/g; s/"/\\"/g')"
EOF
    chown root:"$APP_USER" /etc/msmtprc; chmod 640 /etc/msmtprc
    ok "msmtp → $SMTP_HOST:${SMTP_PORT:-587} (PHP mail() nutzt es automatisch)"
}

setup_cron() {
    step "Aufgaben & Backups"
    if want web; then cat > /usr/local/sbin/ghostnode-cron <<EOF
#!/bin/sh
# Aufgabenplaner der Webseite (Feeds, Kalender, Status, Webhooks, Föderation, Backups der Seite …)
set -a; [ -r $CONF_DIR/web.env ] && . $CONF_DIR/web.env; set +a
exec /usr/bin/php $WEB_DIR/cron.php
EOF
    chmod 755 /usr/local/sbin/ghostnode-cron; fi
    cat > /usr/local/sbin/ghostnode-backup <<EOF
#!/bin/bash
# Server-Backup: (Webseite: Datenbank, data/, uploads/), Bot-Daten, Mumble, Einstellungen – 14 Tage aufbewahrt
# Pterodactyl-Server (Eggs) sichert das Panel selbst (Server → Backups).
set -euo pipefail
umask 077
D=/var/backups/ghostnode; T=\$(date +%Y-%m-%d_%H%M); W=\$(mktemp -d)
mkdir -p "\$D"
if [ "$(want web && echo 1)" = 1 ]; then
    if [ "$DB_ENGINE" = mariadb ]; then mysqldump --single-transaction --routines "$DB_NAME" | gzip > "\$W/datenbank.sql.gz"; fi
    # alte MySQL (z. B. Plesk) als zweites Backup-Ziel: Tabellen dort werden mit dem heutigen Stand überschrieben
    if [ "${OLD_DB_MIRROR:-0}" = 1 ] && [ -r $CONF_DIR/old-db.cnf ]; then
        gunzip -c "\$W/datenbank.sql.gz" | sed -e '/^\/\*M!999999/d' | mysql --defaults-extra-file=$CONF_DIR/old-db.cnf --connect-timeout=20 "${OLD_DB_NAME:-}" \
            && echo "Spiegel in alte MySQL ($OLD_DB_HOST): ok" || { echo "Spiegel in alte MySQL ($OLD_DB_HOST) fehlgeschlagen" >&2; SPIEGEL_FEHLER=1; }
    fi
    tar -czf "\$W/webseite-data.tar.gz" -C "$WEB_DIR" data \$( [ -d "$WEB_DIR/uploads" ] && echo uploads )
fi
[ -d /var/lib/mumble-server ] && tar -czf "\$W/mumble.tar.gz" -C /var/lib mumble-server
[ -d /opt/ghostnode/discord-bot/data ] && tar -czf "\$W/bot-data.tar.gz" -C /opt/ghostnode/discord-bot data
tar -czf "\$W/einstellungen.tar.gz" -C / etc/ghostnode
tar -cf "\$D/ghostnode-\$T.tar" -C "\$W" .
rm -rf "\$W"
find "\$D" -name 'ghostnode-*.tar' -mtime +14 -delete
exit \${SPIEGEL_FEHLER:-0}
EOF
    chmod 700 /usr/local/sbin/ghostnode-backup
    # Cron-Dienst: jeder Lauf wird protokolliert (/var/log/ghostnode-cron.log, letzter Stand in /var/lib/ghostnode/cron-*.status)
    cat > /usr/local/sbin/ghostnode-run <<'EOF2'
#!/bin/sh
# ghostnode-run NAME BEFEHL… – führt einen geplanten Job aus und protokolliert Dauer, Ergebnis und bei Fehlern die letzten Zeilen
n=$1; shift; S=/var/lib/ghostnode; L=/var/log/ghostnode-cron.log; mkdir -p "$S"
t0=$(date +%s); out=$("$@" 2>&1); rc=$?
echo "$(date '+%F %T') $n rc=$rc $(( $(date +%s) - t0 ))s" >> "$L"
[ "$rc" -ne 0 ] && printf '%s\n' "$out" | tail -5 | sed 's/^/    /' >> "$L"
echo "$(date +%s) $rc" > "$S/cron-$n.status"
exit "$rc"
EOF2
    chmod 755 /usr/local/sbin/ghostnode-run
    # Wächter: alle 5 min – startet Dienste neu, die zweimal hintereinander nicht antworten (liest setup.conf beim Lauf)
    cat > /usr/local/sbin/ghostnode-watchdog <<'EOF2'
#!/bin/bash
# erzeugt von ghostnode-root.sh – prüft nginx, Webseite, Datenbank, Bot, HypeRate, TURN; Neustart nach 2 Fehlschlägen in Folge
S=/var/lib/ghostnode; mkdir -p "$S"; rc=0
[ -r /etc/ghostnode/setup.conf ] && . /etc/ghostnode/setup.conf
unit() { [ -f "/etc/systemd/system/$1.service" ] || [ -x "/etc/init.d/$1" ] || [ -f "/run/$1.pid" ]; }
restart() {
    for x in "$@"; do
        if [ -d /run/systemd/system ]; then systemctl restart "$x"
        elif [ -x "/etc/init.d/$x" ]; then service "$x" restart >/dev/null 2>&1
        else echo "  (ohne systemd: $x bitte von Hand starten)"; fi   # nur Testumgebung
    done
}
code() { local c; c=$(curl -s -o /dev/null -m 10 -w '%{http_code}' "$@" 2>/dev/null) || true; echo "${c:-000}"; }
probe() {   # probe NAME 1|0 DIENSTE…
    local n=$1 ok=$2 c; shift 2
    if [ "$ok" = 1 ]; then rm -f "$S/wd-$n"; return; fi
    c=$(( $(cat "$S/wd-$n" 2>/dev/null || echo 0) + 1 )); echo "$c" > "$S/wd-$n"
    if [ "$c" -ge 2 ]; then echo "$n antwortet nicht – Neustart: $*"; restart "$@"; rm -f "$S/wd-$n"; rc=1; else echo "$n antwortet nicht (1. Mal)"; fi
}
yes_if() { "$@" >/dev/null 2>&1 && echo 1 || echo 0; }
probe nginx "$(yes_if pgrep -x nginx)" nginx
if [ "${ROLE:-}" = alles ]; then
    c=$(code -H "Host: ${DOMAIN:-localhost}" http://127.0.0.1:18080/)
    fpm=$(ls /etc/init.d 2>/dev/null | grep -m1 '^php.*-fpm$' || systemctl list-unit-files 'php*-fpm.service' 2>/dev/null | grep -om1 '^php[0-9.]*-fpm')
    probe webseite "$([[ "$c" =~ ^[234] ]] && echo 1 || echo 0)" ${fpm:-} apache2
    [ "${DB_ENGINE:-}" = mariadb ] && probe datenbank "$(yes_if mysqladmin ping)" mariadb
fi
if [ "${RUN_MODE:-systemd}" = systemd ]; then
    unit ghostnode-hr  && probe hyperate "$([ "$(code http://127.0.0.1:18091/health)" != 000 ] && echo 1 || echo 0)" ghostnode-hr
    unit ghostnode-bot && probe bot "$([ "$(code http://127.0.0.1:18092/health)" != 000 ] && echo 1 || echo 0)" ghostnode-bot
    unit ghostnode-live && probe live "$([ "$(code http://127.0.0.1:18093/live/health)" = 200 ] && echo 1 || echo 0)" ghostnode-live
fi
command -v turnserver >/dev/null && probe turn "$(yes_if pgrep -x turnserver)" coturn
exit $rc
EOF2
    chmod 700 /usr/local/sbin/ghostnode-watchdog
    # Server-Agent (root, jede Minute): Status für das Admin-Panel (🖥 Server) + erlaubte Aufträge aus der Webseite ausführen
    install -d -m 0750 -o root -g "$APP_USER" /var/lib/ghostnode/results
    install -d -m 1770 -o root -g "$APP_USER" /var/lib/ghostnode/requests
    chgrp "$APP_USER" /var/lib/ghostnode; chmod 0750 /var/lib/ghostnode
    cat > /usr/local/sbin/ghostnode-agent <<EOF2
#!/usr/bin/env python3
# erzeugt von ghostnode-root.sh – schreibt /var/lib/ghostnode/status.json und arbeitet Aufträge aus /var/lib/ghostnode/requests ab.
# Nur fest erlaubte Aufträge (Dienst neu starten, Backup, Update, Prüfung, Zertifikate, Wächter) – nie freie Befehle.
ROOT_SCRIPT = "$(readlink -f "$0")"
APP_GROUP = "$APP_USER"
EOF2
    cat >> /usr/local/sbin/ghostnode-agent <<'EOF2'
import json, os, re, time, shutil, subprocess, glob, fcntl, grp, socket
S = '/var/lib/ghostnode'; REQ = S + '/requests'; RES = S + '/results'
SERVICES = ['nginx', 'apache2', 'php-fpm', 'mariadb', 'ghostnode-live', 'ghostnode-bot', 'ghostnode-hr', 'coturn', 'mumble-server', 'vsftpd', 'cron', 'fail2ban']
ACTIONS = {'backup': ([ '/usr/local/sbin/ghostnode-backup'], 900), 'update': (['bash', ROOT_SCRIPT, 'update', '--yes'], 1800),
           'check': (['bash', ROOT_SCRIPT, 'check'], 300), 'certs': (['bash', ROOT_SCRIPT, 'certs', '--yes'], 600),
           'watchdog': (['/usr/local/sbin/ghostnode-watchdog'], 120)}
def sh(cmd, t=20):
    try: r = subprocess.run(cmd, capture_output=True, text=True, timeout=t); return r.returncode, (r.stdout + r.stderr)
    except Exception as e: return 1, str(e)
def conf():
    c = {}
    try:
        for l in open('/etc/ghostnode/setup.conf'):
            m = re.match(r"^([A-Z_]+)='(.*)'$", l.strip())
            if m: c[m.group(1)] = m.group(2)
    except Exception: pass
    return c
def fpm():
    u = sorted(os.path.basename(x)[:-8] for x in glob.glob('/lib/systemd/system/php*-fpm.service')) or sorted(os.path.basename(x) for x in glob.glob('/etc/init.d/php*-fpm'))
    return u[-1] if u else 'php-fpm'
def unit(n): return fpm() if n == 'php-fpm' else n
def exists(n):
    u = unit(n)
    return any(os.path.exists(p) for p in ('/lib/systemd/system/%s.service' % u, '/etc/systemd/system/%s.service' % u, '/etc/init.d/' + u, '/run/%s.pid' % u))
def active(n):
    u = unit(n)
    if os.path.isdir('/run/systemd/system'): return sh(['systemctl', 'is-active', u], 5)[1].strip() == 'active'
    pidf = '/run/%s.pid' % u
    if os.path.exists(pidf):
        try: os.kill(int(open(pidf).read().strip()), 0); return True
        except Exception: return False
    proc = {'mariadb': 'mariadbd|mysqld', 'coturn': 'turnserver', 'mumble-server': 'murmurd|mumble-server', 'php-fpm': 'php-fpm'}.get(n, n)
    return sh(['pgrep', '-f', proc], 5)[0] == 0
def gitv(d):
    if not os.path.isdir(d + '/.git'): return None
    rc, o = sh(['git', '-c', 'safe.directory=*', '-C', d, 'log', '-1', '--format=%h|%cI|%s'], 10)
    if rc: return None
    h, t, m = (o.strip().split('|', 2) + ['', ''])[:3]
    return {'hash': h, 'date': t, 'msg': m[:120]}
def status():
    c = conf(); now = int(time.time())
    mem = {}
    for l in open('/proc/meminfo'):
        k, v = l.split(':', 1); mem[k] = int(v.split()[0]) * 1024
    du = shutil.disk_usage('/')
    out = {'time': now, 'host': socket.gethostname(), 'domain': c.get('DOMAIN', ''), 'role': c.get('ROLE', ''),
           'os': next((l.split('=', 1)[1].strip().strip('"') for l in open('/etc/os-release') if l.startswith('PRETTY_NAME=')), ''),
           'uptime': int(float(open('/proc/uptime').read().split()[0])), 'load': [float(x) for x in open('/proc/loadavg').read().split()[:3]],
           'cpus': os.cpu_count(), 'mem': {'total': mem.get('MemTotal', 0), 'avail': mem.get('MemAvailable', 0)},
           'swap': {'total': mem.get('SwapTotal', 0), 'free': mem.get('SwapFree', 0)},
           'disk': {'total': du.total, 'used': du.used, 'free': du.free},
           'services': [{'name': n, 'active': active(n)} for n in SERVICES if exists(n)], 'cron': [], 'backups': [], 'versions': {}}
    for f in sorted(glob.glob(S + '/cron-*.status')):
        try: t, rc = open(f).read().split(); out['cron'].append({'name': os.path.basename(f)[5:-7], 'time': int(t), 'rc': int(rc)})
        except Exception: pass
    bk = sorted(glob.glob('/var/backups/ghostnode/*'), key=os.path.getmtime, reverse=True)[:10]
    out['backups'] = [{'name': os.path.basename(b), 'size': os.path.getsize(b) if os.path.isfile(b) else 0, 'time': int(os.path.getmtime(b))} for b in bk]
    for k, d in (('webseite', c.get('WEB_DIR', '/var/www/ghostnode')), ('bot', '/opt/ghostnode/discord-bot'), ('hyperate', '/opt/ghostnode/src/vrc_nexus'),
                 ('installer', os.path.dirname(os.path.dirname(ROOT_SCRIPT)))):
        v = gitv(d)
        if v: out['versions'][k] = v
    dom = c.get('DOMAIN', '')
    for cert in ['/etc/letsencrypt/live/%s/fullchain.pem' % dom, '/etc/ghostnode/tls/fullchain.pem']:
        if os.path.exists(cert):
            rc, o = sh(['openssl', 'x509', '-enddate', '-noout', '-in', cert], 10)
            m = re.search(r'notAfter=(.*)', o)
            if m:
                rc2, o2 = sh(['date', '-d', m.group(1).strip(), '+%s'], 5)
                if rc2 == 0: out['cert'] = {'until': int(o2.strip()), 'file': cert}
            break
    # Paket-Updates nur alle 30 min zählen (apt ist langsam)
    cache = S + '/apt-upgradable'
    if not os.path.exists(cache) or now - os.path.getmtime(cache) > 1800:
        rc, o = sh(['apt-get', '-s', '-q', 'upgrade'], 120)
        open(cache, 'w').write(str(len([l for l in o.splitlines() if l.startswith('Inst ')])) if rc == 0 else '')
    try: out['apt_upgradable'] = int(open(cache).read() or -1)
    except Exception: pass
    try: out['cronlog'] = open('/var/log/ghostnode-cron.log').read().splitlines()[-25:]
    except Exception: out['cronlog'] = []
    out['reboot_required'] = os.path.exists('/var/run/reboot-required')
    tmp = S + '/status.json.tmp'
    with open(tmp, 'w') as f: json.dump(out, f)
    os.chmod(tmp, 0o640); os.chown(tmp, 0, grp.getgrnam(APP_GROUP).gr_gid); os.replace(tmp, S + '/status.json')
def requests():
    lock = open('/run/ghostnode-agent.lock', 'w')
    try: fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
    except OSError: return   # ein Auftrag läuft noch
    gid = grp.getgrnam(APP_GROUP).gr_gid
    for f in sorted(glob.glob(REQ + '/*.json'), key=os.path.getmtime):
        try: r = json.load(open(f))
        except Exception: os.unlink(f); continue
        os.unlink(f)
        rid, act, by = str(r.get('id', '')), str(r.get('action', '')), str(r.get('by', ''))[:60]
        if not re.fullmatch(r'[0-9a-f]{16}', rid) or time.time() - float(r.get('ts', 0)) > 900: continue
        if act.startswith('restart:') and act[8:] in SERVICES: cmd, t = ['systemctl', 'restart', unit(act[8:])] if os.path.isdir('/run/systemd/system') else ['service', unit(act[8:]), 'restart'], 120
        elif act in ACTIONS: cmd, t = ACTIONS[act]
        else: continue
        res = RES + '/' + rid + '.json'
        def write(d):
            with open(res + '.tmp', 'w') as fh: json.dump(d, fh)
            os.chmod(res + '.tmp', 0o640); os.chown(res + '.tmp', 0, gid); os.replace(res + '.tmp', res)
        d = {'id': rid, 'action': act, 'by': by, 'started': int(time.time()), 'state': 'läuft'}; write(d)
        rc, o = sh(['/usr/local/sbin/ghostnode-run', 'web-' + act.replace(':', '-')] + cmd, t)
        o = re.sub(r'\x1b\[[0-9;]*m', '', o)
        d.update({'finished': int(time.time()), 'rc': rc, 'state': 'ok' if rc == 0 else 'Fehler', 'out': '\n'.join(o.splitlines()[-60:])}); write(d)
    for f in glob.glob(RES + '/*.json'):
        if time.time() - os.path.getmtime(f) > 7 * 86400: os.unlink(f)
if __name__ == '__main__':
    try: status()
    except Exception as e: open(S + '/agent-error.log', 'a').write(time.strftime('%F %T ') + repr(e) + '\n')
    requests()
EOF2
    chmod 700 /usr/local/sbin/ghostnode-agent
    {
        echo "# erzeugt von ghostnode-root.sh – Übersicht: $(readlink -f "$0") crons"; echo "SHELL=/bin/sh"; echo "PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin"
        if want web; then echo "*/5 * * * * root ghostnode-run aufgaben runuser -u $APP_USER -- /usr/local/sbin/ghostnode-cron"; fi
        echo "*/5 * * * * root ghostnode-run waechter /usr/local/sbin/ghostnode-watchdog"
        echo "* * * * * root /usr/local/sbin/ghostnode-agent >/dev/null 2>&1"
        echo "17 3 * * * root ghostnode-run backup /usr/local/sbin/ghostnode-backup"
        [ "${AUTO_UPDATE:-1}" = 1 ] && echo "41 4 * * * root ghostnode-run update bash $(readlink -f "$0") update --yes"
    } > /etc/cron.d/ghostnode
    printf '/var/log/ghostnode-cron.log {\n    weekly\n    rotate 8\n    compress\n    missingok\n    notifempty\n}\n' > /etc/logrotate.d/ghostnode-cron
    chmod 644 /etc/cron.d/ghostnode
    svc enable cron
    # Webseite auf „echten Cron“ stellen (nur möglich, wenn die Datenbank schon erreichbar ist)
    if ! want web; then ok "Webseiten-Aufgaben laufen auf Plesk (dort: Geplante Aufgaben → php cron.php alle 5 min)"
    elif runuser -u "$APP_USER" -- php -r 'chdir($argv[1]); $_SERVER["REMOTE_ADDR"]="127.0.0.1"; require "inc/core/pages.php"; set_setting("tasks_mode","cron"); echo "ok";' "$WEB_DIR" 2>/dev/null | grep ok >/dev/null; then
        ok "Aufgaben alle 5 min per Cron, Webseite steht auf „echter Cron“"
    else
        warn "Aufgabenplaner der Seite noch nicht umgestellt – nach dem ersten Login: Admin → System → Aufgaben → Modus „Cron“."
    fi
    ok "Backup täglich 03:17 nach /var/backups/ghostnode (14 Tage)"
    ok "Wächter alle 5 min (Neustart nach 2 Fehlschlägen)$([ "${AUTO_UPDATE:-1}" = 1 ] && echo ", Auto-Update von GitHub täglich 04:41")"
    ok "Übersicht: $0 crons · Protokoll: /var/log/ghostnode-cron.log"
}
cmd_crons() {
    step "Geplante Aufgaben"
    local n st t rc
    for n in aufgaben waechter backup update; do
        grep -q "ghostnode-run $n " /etc/cron.d/ghostnode 2>/dev/null || continue
        st=/var/lib/ghostnode/cron-$n.status
        if [ -f "$st" ]; then read -r t rc < "$st"; [ "$rc" = 0 ] && ok "$n – zuletzt $(date -d "@$t" '+%d.%m. %H:%M') ✔" || fail "$n – zuletzt $(date -d "@$t" '+%d.%m. %H:%M') mit Fehler $rc"
        else ok "$n – noch nicht gelaufen"; fi
    done
    echo; echo "  Zeitplan (/etc/cron.d/ghostnode):"; grep -v '^#\|^SHELL\|^PATH' /etc/cron.d/ghostnode | sed 's/^/    /'
    echo; echo "  Letzte Einträge (/var/log/ghostnode-cron.log):"; tail -8 /var/log/ghostnode-cron.log 2>/dev/null | sed 's/^/    /' || true
}

setup_firewall() {
    step "Firewall & Schutz"
    apt-get install -y -qq ufw fail2ban unattended-upgrades >/dev/null
    local ssh_port=""; command -v sshd >/dev/null && ssh_port=$(sshd -T 2>/dev/null | awk '/^port /{print $2; exit}' || true); ssh_port=${ssh_port:-22}
    local u=1 was=0
    ufw status 2>/dev/null | grep -q '^Status: active' && was=1
    # Nie zurücksetzen – vorhandene Regeln (Pterodactyl, Spielserver, eigene) bleiben, es kommen nur Regeln dazu
    {
        ufw allow "$ssh_port/tcp" comment 'SSH' && ufw allow 80/tcp comment 'HTTP' && ufw allow 443/tcp comment 'HTTPS'
    } >/dev/null 2>&1 || u=0
    if [ "$PTERO" = 1 ]; then
        { ufw allow "$WINGS_API/tcp" comment 'Pterodactyl Wings' && ufw allow "$WINGS_SFTP/tcp" comment 'Pterodactyl SFTP'; } >/dev/null 2>&1 || u=0
        ok "Pterodactyl erkannt: Wings $WINGS_API und SFTP $WINGS_SFTP bleiben offen (Spielserver-Ports laufen über Docker an ufw vorbei)"
    fi
    if want turn; then { ufw allow 3478 comment 'TURN' && ufw allow 5349/tcp comment 'TURN TLS' && ufw allow "$TURN_MIN:$TURN_MAX/udp" comment 'TURN Relais'; } >/dev/null 2>&1 || u=0; fi
    if want voice && [ "$PTERO" = 0 ]; then { ufw allow 64738 comment 'Mumble'; } >/dev/null 2>&1 || u=0; fi
    if want ftp; then { ufw allow 21/tcp comment 'FTPS' && ufw allow "$FTP_PASV_MIN:$FTP_PASV_MAX/tcp" comment 'FTPS passiv'; } >/dev/null 2>&1 || u=0; fi
    if [ "$was" = 0 ] && [ "$u" = 1 ]; then
        # ufw war aus: nur einschalten, wenn kein unbekannter Dienst öffentlich lauscht (sonst würde er plötzlich blockiert)
        local known=" $ssh_port 80 443 21 3478 5349 64738 ${WINGS_API:-} ${WINGS_SFTP:-} " other p
        other=$(ss -ltnH 2>/dev/null | awk '{print $4}' | grep -vE '^(127\.|\[::1\]|\[::ffff:127\.)' | sed 's/.*://' | sort -un | while read -r p; do [[ "$known" == *" $p "* ]] || [ "$(port_owner "$p")" = docker-proxy ] || echo "$p"; done | paste -sd ' ' -)
        if [ -n "$other" ]; then u=0; warn "ufw bleibt aus: weitere öffentliche Ports ($other) würden sonst blockiert. Prüfen, mit „ufw allow …“ freigeben, dann „ufw enable“."; fi
    fi
    if [ "$u" = 1 ] && [ "$was" = 0 ]; then ufw default deny incoming >/dev/null; ufw default allow outgoing >/dev/null; ufw --force enable >/dev/null 2>&1 || warn "ufw ließ sich nicht einschalten (Container?) – auf dem Server: ufw status"; fi
    cat > /etc/fail2ban/jail.d/ghostnode.conf <<EOF
[sshd]
enabled = true
[apache-auth]
enabled = $(want web && echo true || echo false)
[nginx-http-auth]
enabled = $([ "$FRONT" = nginx ] && echo true || echo false)
[vsftpd]
enabled = $(want ftp && echo true || echo false)
EOF
    svc enable fail2ban; svc restart fail2ban
    dpkg-reconfigure -f noninteractive unattended-upgrades >/dev/null 2>&1 || true
    if ufw status 2>/dev/null | grep -q '^Status: active'; then ok "ufw aktiv (SSH $ssh_port, 80, 443$(want turn && echo ', TURN')$(want voice && [ "$PTERO" = 0 ] && echo ', Mumble')$(want ftp && echo ', FTPS')$([ "$PTERO" = 1 ] && echo ', Wings'))"
    else warn "ufw ist aus – Regeln sind vorbereitet, einschalten mit: ufw enable"; fi
    ok "fail2ban, automatische Sicherheitsupdates"
}

setup_tls() {
    step "Zertifikate (Let's Encrypt)"
    [ "$NO_TLS" = 1 ] && { warn "--no-tls: keine Zertifikate. Später: $0 certs"; return; }
    [ -n "${LE_EMAIL:-}" ] || { warn "Keine E-Mail für Let's Encrypt angegeben – später: $0 certs"; return; }
    if [ "$FRONT" = nginx ]; then apt-get install -y -qq certbot python3-certbot-nginx >/dev/null; else apt-get install -y -qq certbot python3-certbot-apache >/dev/null; fi
    local names=() n
    local all=("$HR_HOST" "$BOT_HOST" "$TURN_HOST" "$VOICE_HOST"); if want web; then all=("$DOMAIN" "www.$DOMAIN" "${all[@]}"); fi
    for n in "${all[@]}"; do
        if dns_ok "$n"; then names+=(-d "$n"); else warn "$n zeigt (noch) nicht auf diesen Server – ausgelassen."; fi
    done
    [ ${#names[@]} -gt 0 ] || { warn "Kein Name zeigt auf diesen Server – DNS prüfen, dann $0 certs"; return; }
    # turn.<domain> braucht einen (leeren) Webserver-Eintrag für die Prüfung
    if [[ " ${names[*]} " == *" $TURN_HOST "* ]] || [[ " ${names[*]} " == *" $VOICE_HOST "* ]]; then
        if [ "$FRONT" = nginx ]; then nginx_site ghostnode-turn "$TURN_HOST $VOICE_HOST" 0 redirect
        else
            printf '<VirtualHost *:80>\n    ServerName %s\n    Redirect 302 / https://%s/\n</VirtualHost>\n' "$TURN_HOST" "$DOMAIN" > /etc/apache2/sites-available/ghostnode-turn.conf
            a2ensite -q ghostnode-turn >/dev/null; svc reload apache2
        fi
    fi
    certbot "--$FRONT" --non-interactive --agree-tos -m "$LE_EMAIL" --redirect --expand --cert-name ghostnode "${names[@]}" >/dev/null
    # Kopie für coturn und vsftpd (eigene Benutzer) – wird bei jeder Verlängerung erneuert
    cat > /etc/letsencrypt/renewal-hooks/deploy/ghostnode.sh <<'EOF'
#!/bin/sh
# Zertifikat für coturn und vsftpd bereitstellen (erzeugt von ghostnode-root.sh)
L=/etc/letsencrypt/live/ghostnode
install -d -m 0750 /etc/ghostnode/tls /etc/coturn/certs
install -m 0640 "$L/fullchain.pem" "$L/privkey.pem" /etc/ghostnode/tls/
install -m 0640 "$L/fullchain.pem" "$L/privkey.pem" /etc/coturn/certs/
chgrp -R turnserver /etc/coturn/certs 2>/dev/null || true
chgrp mumble-server /etc/ghostnode/tls /etc/ghostnode/tls/*.pem 2>/dev/null && chmod 750 /etc/ghostnode/tls
for s in coturn vsftpd mumble-server; do (systemctl try-restart "$s" || service "$s" restart) >/dev/null 2>&1 || true; done
for s in apache2 nginx; do (systemctl try-reload-or-restart "$s" || service "$s" reload) >/dev/null 2>&1 || true; done
EOF
    chmod 755 /etc/letsencrypt/renewal-hooks/deploy/ghostnode.sh
    /etc/letsencrypt/renewal-hooks/deploy/ghostnode.sh
    ok "Zertifikat „ghostnode“ für: ${names[*]//-d /}; Verlängerung automatisch (certbot.timer)"
    # TURN/FTPS jetzt mit TLS neu schreiben
    if want turn; then setup_turn; fi
    if want ftp; then setup_ftp; fi
    if want voice && [ "$PTERO" = 0 ]; then setup_voice; fi
    return 0
}

write_credentials() {
    {
        echo "# GhostNode – erzeugte Zugänge ($(date '+%d.%m.%Y %H:%M')). Nur für root. Nicht weitergeben."
        [ "$DB_ENGINE" = mariadb ] && echo "Datenbank:   MariaDB, Name $DB_NAME, Benutzer $DB_USER, Passwort $DB_PASS (nur localhost)"
        want ftp && echo "Poster-FTPS: Server $DOMAIN, Port 21, explizites TLS, Benutzer $FTP_USER, Passwort $FTP_PASS"
        want turn && echo "TURN:        $TURN_HOST, Geheimnis steht in $SECRETS (wird nie direkt an Nutzer gegeben)$(want web || echo "; für Plesk: $CONF_DIR/turn-fuer-plesk.json → data/keys/turn.json")"
        if want voice && [ "$PTERO" = 0 ]; then echo "Mumble:      $VOICE_HOST:64738, SuperUser-Passwort $MUMBLE_SUPW$([ -n "${MUMBLE_PASS:-}" ] && echo ", Serverpasswort $MUMBLE_PASS")"; fi
        echo "Schlüssel:   $SECRETS (ändern mit: $0 secrets)"
        echo "Backups:     /var/backups/ghostnode"
    } > "$CRED"
    chmod 600 "$CRED"
}

# ---------------------------------------------------------------- Prüfung
# lokale Anfrage an einen Namen – über HTTPS, sobald es ein Zertifikat gibt (sonst würde nur die Umleitung geprüft)
lcurl() { local host=$1 path=$2; shift 2
    if [ -d /etc/letsencrypt/live/ghostnode ]; then curl -s -k --max-time 10 --resolve "$host:443:127.0.0.1" "$@" "https://$host$path"
    else curl -s --max-time 10 -H "Host: $host" "$@" "http://127.0.0.1$path"; fi; }
http_code() { local c; c=$(lcurl "$1" "$2" -o /dev/null -w '%{http_code}' 2>/dev/null) || true; echo "${c:-000}"; }
check_all() {
    step "Prüfung"
    FAILS=0
    if want web && [ "$RUN_MODE" = systemd ] && [ -f /opt/ghostnode/live/live.js ]; then
        local lc; lc=$(curl -s -m 8 "http://127.0.0.1:$LIVE_PORT/live/health" 2>/dev/null) || true
        [[ "$lc" == *'"ghostnode-live"'* ]] && ok "Live-Dienst antwortet (Fernsteuerung in Echtzeit)" || fail "Live-Dienst antwortet nicht (journalctl -u ghostnode-live)"
        lc=$(curl -s -m 8 -H "Host: $DOMAIN" "http://127.0.0.1/live/health" 2>/dev/null) || true
        [[ "$lc" == *'"ghostnode-live"'* ]] && ok "nginx leitet /live/ weiter" || fail "nginx leitet /live/ nicht weiter"
    fi
    local PV; PV=$(php_ver 2>/dev/null || echo "?")
    if want web; then
        is_active apache2 && ok "Apache läuft" || fail "Apache läuft nicht"
        is_active "php$PV-fpm" && ok "PHP-FPM $PV läuft" || fail "PHP-FPM läuft nicht"
        local c
        for p in "/" "/index.php" "/status.php" "/status.php?api=1" "/api.php/v2/status" "/Roadmap.php" "/changelog.php" "/market.php" "/dev.php" "/uebersetzen.php" "/oauth.php?r=meta" "/fed.php" "/admin.php"; do
            c=$(http_code "$DOMAIN" "$p"); [[ "$c" =~ ^(200|301|302)$ ]] && ok "Seite $p → $c" || fail "Seite $p → $c"
        done
        for p in "/data/config.php" "/inc/core/common.php" "/.git/config" "/tools/db.php" "/server/ghostnode-root.sh"; do
            c=$(http_code "$DOMAIN" "$p"); [[ "$c" =~ ^(403|404)$ ]] && ok "gesperrt: $p ($c)" || fail "NICHT gesperrt: $p → $c"
        done
        c=$(lcurl "$DOMAIN" "/api.php?r=me" -H "Authorization: Bearer gnk_0000000000000000000000000000000000000000" 2>/dev/null || true)
        [[ "$c" == *"Ungültiger API-Schlüssel"* ]] && ok "Authorization-Kopfzeile kommt bei PHP an (API-Schlüssel)" || fail "Authorization-Kopfzeile kommt nicht an: $c"
        runuser -u "$APP_USER" -- php -r 'chdir($argv[1]); $_SERVER["REMOTE_ADDR"]="127.0.0.1"; require "inc/core/pages.php"; echo site_db()->getAttribute(PDO::ATTR_DRIVER_NAME);' "$WEB_DIR" 2>/dev/null | grep -E 'sqlite|mysql' >/dev/null \
            && ok "Datenbank erreichbar ($DB_ENGINE)" || fail "Datenbank nicht erreichbar"
        [ -w "$WEB_DIR/data" ] && [ "$(stat -c %U "$WEB_DIR/data")" = "$APP_USER" ] && ok "data/ gehört $APP_USER" || fail "data/ falsche Rechte"
    fi
    if [ "$FRONT" = nginx ]; then
        nginx -t >/dev/null 2>&1 && ok "nginx-Konfiguration gültig (vorne auf 80/443)" || fail "nginx-Konfiguration fehlerhaft"
        is_active nginx && ok "nginx läuft" || fail "nginx läuft nicht"
        [ "$(port_owner 80)" = nginx ] && ok "Port 80 gehört nginx" || fail "Port 80 gehört nicht nginx"
        if want web; then
            [ "$(port_owner "$BACKEND_PORT")" = apache2 ] && ok "Apache nur intern auf 127.0.0.1:$BACKEND_PORT" || fail "Apache lauscht nicht auf 127.0.0.1:$BACKEND_PORT"
            c=$(curl -s -o /dev/null -w '%{http_code}' --max-time 8 -H "Host: $DOMAIN" "http://127.0.0.1/status.php?api=1" 2>/dev/null) || true; c=${c:-000}
            [ "$c" = 200 ] && ok "Webseite über nginx erreichbar" || fail "Webseite über nginx → $c"
        fi
    fi
    if [ "$PTERO" = 1 ]; then
        if command -v wings >/dev/null || [ -x /usr/local/bin/wings ]; then is_active wings && ok "Pterodactyl Wings läuft weiter" || fail "Pterodactyl Wings läuft nicht"; fi
        [ -d /var/www/pterodactyl ] && { [ -f "/etc/php/$PV/fpm/pool.d/www.conf" ] && ok "PHP-Pool des Panels (www) unverändert aktiv" || fail "PHP-Pool „www“ fehlt – Panel braucht ihn"; }
    fi
    if want hr; then
        if [ "$RUN_MODE" = systemd ]; then is_active ghostnode-hr && ok "HypeRate-Weiterleitung läuft" || fail "HypeRate-Weiterleitung läuft nicht"; fi
        c=$(lcurl "$HR_HOST" /health 2>/dev/null || true)
        [[ "$c" == *'"ok"'* ]] && ok "https://$HR_HOST/health antwortet: $c" || fail "Weiterleitung /health: ${c:-keine Antwort}$([ "$RUN_MODE" = egg ] && echo " – läuft der Server im Panel auf $HR_UPSTREAM?")"
        if [ "$RUN_MODE" = systemd ]; then [ "$(ss -ltnH "sport = :$HR_PORT" | awk '{print $4}' | grep -c '^127\.0\.0\.1:' || true)" -ge 1 ] && ok "Weiterleitung nur intern (127.0.0.1:$HR_PORT)" || fail "Weiterleitung lauscht öffentlich auf $HR_PORT"; fi
    fi
    if want bot; then
        if [ "$RUN_MODE" = systemd ]; then is_active ghostnode-bot && ok "Discord-Bot läuft" || fail "Discord-Bot läuft nicht"; fi
        c=$(http_code "$BOT_HOST" "/health"); [[ "$c" =~ ^(200|503)$ ]] && ok "Bot /health → $c$([ "$c" = 503 ] && echo ' (ohne Token: Hinweisseite)')" || fail "Bot /health → $c$([ "$RUN_MODE" = egg ] && echo " – läuft der Server im Panel auf $BOT_UPSTREAM?")"
        if [ "$RUN_MODE" = systemd ]; then [ "$(ss -ltnH "sport = :$BOT_PORT" | awk '{print $4}' | grep -c '^127\.0\.0\.1:' || true)" -ge 1 ] && ok "Bot nur intern (127.0.0.1:$BOT_PORT)" || fail "Bot lauscht öffentlich auf $BOT_PORT"; fi
    fi
    if want turn; then
        is_active coturn && ok "coturn läuft" || fail "coturn läuft nicht"
        if want web; then [ -s "$WEB_DIR/data/keys/turn.json" ] && ok "Webseite kennt den TURN-Server" || fail "data/keys/turn.json fehlt"
        else [ -s "$CONF_DIR/turn-fuer-plesk.json" ] && ok "TURN-Zugang für Plesk liegt bereit ($CONF_DIR/turn-fuer-plesk.json)" || fail "turn-fuer-plesk.json fehlt"; fi
        [ -z "$(ss -ltnuH 2>/dev/null | awk '{print $5}' | grep -E ':(3479|5350)$' || true)" ] && ok "TURN ohne Zusatz-Ports (nur 3478/5349)" || fail "coturn öffnet Zusatz-Ports 3479/5350"
    fi
    if want voice && [ "$PTERO" = 0 ]; then
        is_active mumble-server && ok "Mumble läuft" || fail "Mumble läuft nicht"
        [ -n "$(port_owner 64738)" ] && ok "Mumble lauscht auf 64738" || fail "Mumble lauscht nicht auf 64738"
    fi
    if want ftp; then
        is_active vsftpd && ok "FTPS (vsftpd) läuft" || fail "vsftpd läuft nicht"
    fi
    if want cron; then
        [ -f /etc/cron.d/ghostnode ] && ok "Cron eingerichtet" || fail "Cron fehlt"
    fi
    if want cron && want web; then
        local out; out=$(cd /tmp && runuser -u "$APP_USER" -- /usr/local/sbin/ghostnode-cron 2>&1 || true)
        if grep -q '✖' <<<"$out"; then warn "Aufgabe(n) mit Fehler: $(grep '✖' <<<"$out" | awk '{print $2": "substr($0, index($0,$4))}' | paste -sd ';' -)"
        else ok "Aufgabenplaner läuft durch ($(head -1 <<<"$out"))"; fi
    fi
    if [ -d /etc/letsencrypt/live/ghostnode ]; then
        local exp; exp=$(openssl x509 -enddate -noout -in /etc/letsencrypt/live/ghostnode/fullchain.pem | cut -d= -f2)
        ok "Zertifikat gültig bis $exp"
    else warn "Noch kein Zertifikat (HTTPS) – $0 certs"; fi
    echo
    if [ "$FAILS" = 0 ]; then echo "${c_ok}${c_b}✔ Alles in Ordnung.${c_0}"; else echo "${c_err}${c_b}✖ $FAILS Prüfung(en) fehlgeschlagen.${c_0}"; fi
    return "$FAILS"
}

summary() {
    echo
    echo "${c_b}════════ GhostNode ist eingerichtet ════════${c_0}"
    if want web; then echo "  Webseite:        https://$DOMAIN  → zuerst https://$DOMAIN/admin.php öffnen und das Besitzer-Konto anlegen"
    else echo "  Webseite:        bleibt auf Plesk (https://$DOMAIN)"; fi
    want hr  && echo "  HypeRate:        https://$HR_HOST/health"
    want bot && echo "  Bot-Dashboard:   https://$BOT_HOST  (Discord-Portal: Redirect https://$BOT_HOST/callback)"
    want turn && echo "  Anrufe (TURN):   $TURN_HOST:3478 / 5349$(want web || echo "  → Plesk: $CONF_DIR/turn-fuer-plesk.json als data/keys/turn.json hochladen")"
    want voice && echo "  Mumble:          $VOICE_HOST:64738$([ "$PTERO" = 1 ] && echo '  → im Panel mit dem Egg „Mumble Server“ anlegen')"
    want ftp && echo "  Poster-FTPS:     $DOMAIN:21 (Zugang in $CRED)"
    [ "$FRONT" = nginx ] && echo "  Webserver:       nginx vorne (80/443)$([ "$PTERO" = 1 ] && echo ', Pterodactyl unverändert')$(want web && echo "; Apache intern auf 127.0.0.1:$BACKEND_PORT")"
    [ "$RUN_MODE" = egg ] && echo "  Pterodactyl:     Eggs importieren (Admin → Nests → Import Egg): node_discord/pterodactyl/egg-ghostnode-discord-bot.json, vrc_nexus/apps/hyperate-relay/egg-ghostnode-hyperate-relay.json"
    echo "  Zugänge:         $CRED"
    echo "  Aktualisieren:   bash $0 update     ·  Prüfen: bash $0 check  ·  Backup: bash $0 backup"
    echo "  DNS (A-Einträge auf $(public_ip)): $(want web && echo "$DOMAIN, www.$DOMAIN, ")$HR_HOST, $BOT_HOST, $TURN_HOST, $VOICE_HOST"
    if [ ${#WARNINGS[@]} -gt 0 ]; then echo; echo "${c_warn}Hinweise:${c_0}"; for w in "${WARNINGS[@]}"; do echo "  ⚠ $w"; done; fi
}

# ---------------------------------------------------------------- Umzug von Plesk (MySQL + Dateien)
mig_extract() {   # mig_extract ARCHIV ZIEL – Zip oder Tar sicher entpacken (keine absoluten Pfade, kein „..“, keine Links)
    python3 - "$1" "$2" <<'PY'
import sys, os, zipfile, tarfile
src, dst = sys.argv[1], os.path.realpath(sys.argv[2])
def safe(n):
    p = os.path.realpath(os.path.join(dst, n))
    return p == dst or p.startswith(dst + os.sep)
if zipfile.is_zipfile(src):
    with zipfile.ZipFile(src) as z:
        for i in z.infolist():
            if not safe(i.filename) or (i.external_attr >> 16) & 0o170000 == 0o120000: continue
            z.extract(i, dst)
else:
    with tarfile.open(src) as t:
        for m in t.getmembers():
            if (m.isfile() or m.isdir()) and safe(m.name): t.extract(m, dst, set_attrs=False)
PY
}
mig_sql() {   # Datenbank-Export → $1 (reines SQL)
    local out=$1 tmp
    if [ -n "$MIG_FROM" ]; then
        [ -n "$MIG_SRC_DB" ] && [ -n "$MIG_SRC_USER" ] || die "Für --from bitte auch --src-db=NAME und --src-user=BENUTZER angeben."
        local pw="${MIG_SRC_PASS:-}"
        if [ -z "$pw" ]; then [ -t 0 ] || die "Passwort fehlt (MIG_SRC_PASS setzen oder im Terminal starten)."; read -r -s -p "  Passwort der Plesk-Datenbank ($MIG_SRC_USER@$MIG_FROM): " pw; echo; fi
        tmp=$(mktemp); chmod 600 "$tmp"
        printf '[client]\nhost=%s\nport=%s\nuser=%s\npassword="%s"\n' "$MIG_FROM" "$MIG_SRC_PORT" "$MIG_SRC_USER" "${pw//\"/\\\"}" > "$tmp"
        ok "Hole die Datenbank „$MIG_SRC_DB“ direkt von $MIG_FROM …"
        if ! mysqldump --defaults-extra-file="$tmp" --single-transaction --quick --skip-lock-tables --no-tablespaces --default-character-set=utf8mb4 "$MIG_SRC_DB" > "$out" 2>"$out.err"; then
            rm -f "$tmp"; die "Plesk-MySQL nicht erreichbar oder Zugang falsch: $(tail -1 "$out.err") – in Plesk für den Datenbank-Benutzer Fernzugriff erlauben (oder Export-Datei mit --db nutzen)."
        fi
        rm -f "$tmp" "$out.err"; return
    fi
    [ -f "$MIG_DB" ] || die "Datenbank-Export nicht gefunden: $MIG_DB"
    case "$MIG_DB" in
        *.gz) gunzip -c "$MIG_DB" > "$out" ;;
        *.zip)
            tmp=$(mktemp -d); mig_extract "$MIG_DB" "$tmp"
            local f; f=$(find "$tmp" -type f -name '*.sql' -printf '%s %p\n' | sort -rn | head -1 | cut -d' ' -f2-)
            [ -n "$f" ] || die "Im Zip ist keine .sql-Datei."
            mv "$f" "$out"; rm -r "$tmp" ;;
        *) cp "$MIG_DB" "$out" ;;
    esac
}
mig_src_from_archive() {   # Plesk-Datenbankzugang aus data/config.php im Datei-Archiv lesen → MIG_SRC_*
    local j
    j=$(python3 - "$MIG_FILES" <<'PY'
import sys, zipfile, tarfile, json, re
src = sys.argv[1]; raw = None
def pick(names): return next((n for n in names if re.search(r'(^|/)data/config\.php$', n)), None)
if zipfile.is_zipfile(src):
    z = zipfile.ZipFile(src); n = pick(z.namelist()); raw = z.read(n) if n else None
else:
    t = tarfile.open(src); n = pick(t.getnames()); raw = t.extractfile(n).read() if n else None
if not raw: sys.exit(2)
txt = raw.decode('utf-8', 'replace'); i = txt.find('{')
c = json.loads(txt[i:]) if i >= 0 else {}
if c.get('driver') != 'mysql': sys.exit(3)
print('\t'.join(str(c.get(k, '')) for k in ('host', 'port', 'name', 'user', 'pass', 'prefix')))
PY
) || case $? in 2) die "Kein Datenbank-Export angegeben und keine data/config.php im Archiv – bitte --db=/root/export.zip angeben." ;;
                 3) die "Die Webseite auf Plesk nutzt SQLite, nicht MySQL – dann reicht --files (die Datenbank liegt in data/)." ;;
                 *) die "data/config.php im Archiv nicht lesbar." ;; esac
    local h p
    IFS=$'\t' read -r h p MIG_SRC_DB MIG_SRC_USER MIG_SRC_PASS _ <<< "$j"
    MIG_SRC_PORT=${p:-3306}; export MIG_SRC_PASS
    if [ -z "$MIG_FROM" ]; then
        case "$h" in localhost|127.0.0.1|::1|"") die "Die Plesk-Datenbank lauscht dort nur lokal ($h). Adresse des Plesk-Servers mit --from=… angeben (z. B. --from=45.81.232.18) und in Plesk beim Datenbank-Benutzer Fernzugriff erlauben." ;; esac
        MIG_FROM=$h
    fi
    ok "Plesk-Datenbank aus data/config.php: $MIG_SRC_USER@$MIG_FROM:$MIG_SRC_PORT/$MIG_SRC_DB"
}
cmd_migrate() {
    [ -n "$MIG_DB$MIG_FROM$MIG_FILES" ] || die "Angeben: --db=/root/export.zip und/oder --files=/root/dateien.zip (Hilfe: --help)"
    [ -z "$MIG_FILES" ] || [ -f "$MIG_FILES" ] || die "Datei-Archiv nicht gefunden: $MIG_FILES"
    if [ ! -f "$CONF" ] || [ "${ROLE:-}" != alles ] || [ ! -d "$WEB_DIR/.git" ]; then
        step "Webseite auf diesem Server einrichten (Rolle „alles“)"
        if [ -f "$CONF" ]; then sed -i "s/^ROLE=.*/ROLE='alles'/" "$CONF"; grep -q '^ROLE=' "$CONF" || echo "ROLE='alles'" >> "$CONF"; fi
        local yes=(); [ "$YES" = 1 ] && yes=(--yes)
        ROLE=alles DB_ENGINE=mariadb bash "$0" install "${yes[@]}" --no-tls || warn "Einrichtung meldet offene Punkte (z. B. Bot/HypeRate noch ohne Schlüssel) – Umzug der Webseite geht weiter."
        load_conf; [ "${ROLE:-}" = alles ] && [ -d "${WEB_DIR:-/var/www/ghostnode}/.git" ] || die "Die Webseite wurde nicht eingerichtet – Ausgabe oben prüfen."   # Zertifikate erst nach der DNS-Umstellung (certs)
        exec bash "$0" migrate "${ARGS[@]}"
    fi
    [ "$DB_ENGINE" = mariadb ] || die "Die Webseite hier nutzt SQLite – für den MySQL-Umzug DB_ENGINE='mariadb' in $CONF setzen und „install“ erneut ausführen."
    command -v python3 >/dev/null || apt-get install -y -qq python3 >/dev/null
    local ts work sql cfg="$WEB_DIR/data/config.php" pre safety
    ts=$(date +%Y%m%d-%H%M%S); work=$(mktemp -d /root/ghostnode-umzug.XXXXXX); chmod 700 "$work"; sql="$work/datenbank.sql"
    step "Datenbank-Export lesen"
    if [ -z "$MIG_DB" ] && { [ -z "$MIG_SRC_DB" ] || [ -z "$MIG_SRC_USER" ]; }; then mig_src_from_archive; fi   # direkt von der Plesk-MySQL
    mig_sql "$sql"
    [ -s "$sql" ] || die "Der Export ist leer."
    pre=$(grep -m1 -oE 'CREATE TABLE (IF NOT EXISTS )?`[A-Za-z0-9_]*users`' "$sql" | sed -E 's/.*`([A-Za-z0-9_]*)users`/\1/' || true)
    grep -m1 -E 'CREATE TABLE (IF NOT EXISTS )?`[A-Za-z0-9_]*users`' "$sql" >/dev/null || die "Das sieht nicht nach der GhostNode-Datenbank aus (keine Tabelle …users)."
    ok "$(grep -c '^CREATE TABLE' "$sql" || true) Tabellen, Präfix „${pre:-–}“, $(du -h "$sql" | cut -f1)"
    if [ -n "$MIG_FROM" ]; then mkdir -p /var/backups/ghostnode; gzip -c "$sql" > "/var/backups/ghostnode/alte-mysql-$ts.sql.gz"; chmod 600 "/var/backups/ghostnode/alte-mysql-$ts.sql.gz"; ok "Kopie der alten Datenbank: /var/backups/ghostnode/alte-mysql-$ts.sql.gz"; fi
    sed -i -E 's/DEFINER=`[^`]*`@`[^`]*`//g; s/^SET @@GLOBAL\.GTID_PURGED.*$//; /^\/\*M!999999/d' "$sql"   # Plesk-Benutzer gibt es hier nicht; MariaDB-Sandbox-Zeile
    step "Sicherung vor dem Umzug"
    mkdir -p /var/backups/ghostnode; safety=/var/backups/ghostnode/vor-umzug-$ts
    mkdir -p "$safety"; chmod 700 "$safety"
    mysqldump --single-transaction "$DB_NAME" > "$safety/datenbank.sql"
    tar -czf "$safety/data.tar.gz" -C "$WEB_DIR" data $( [ -d "$WEB_DIR/uploads" ] && echo uploads ) 2>/dev/null || true
    ok "$safety"
    step "Datenbank übernehmen"
    svc stop apache2 >/dev/null 2>&1 || true   # kurz Wartung: nginx zeigt 502, nichts schreibt dazwischen
    mysql --protocol=socket -uroot -e "DROP DATABASE IF EXISTS \`$DB_NAME\`; CREATE DATABASE \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'localhost'; FLUSH PRIVILEGES;"
    if ! mysql --protocol=socket -uroot --default-character-set=utf8mb4 "$DB_NAME" < "$sql" 2>"$work/import.err"; then
        warn "Import fehlgeschlagen: $(tail -1 "$work/import.err") – stelle den vorherigen Stand wieder her."
        mysql --protocol=socket -uroot -e "DROP DATABASE IF EXISTS \`$DB_NAME\`; CREATE DATABASE \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
        mysql --protocol=socket -uroot "$DB_NAME" < "$safety/datenbank.sql"; svc start apache2 || true
        die "Umzug abgebrochen, alter Stand wiederhergestellt. Export prüfen (vollständig? richtige Datenbank?)."
    fi
    ok "$(mysql --protocol=socket -uroot -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$DB_NAME'") Tabellen importiert"
    if [ -n "$pre" ] && [ -f "$cfg" ]; then
        sed -i -E "s/\"prefix\": *\"[A-Za-z0-9_]*\"/\"prefix\": \"$pre\"/" "$cfg"; ok "Tabellen-Präfix „$pre“ in data/config.php"
    fi
    if [ -n "$MIG_FILES" ]; then
        step "Dateien übernehmen (data/, uploads/)"
        local x="$work/dateien" d u
        mkdir -p "$x"; mig_extract "$MIG_FILES" "$x"
        d=$(find "$x" -maxdepth 4 -type d -name data \( -exec test -d '{}/keys' \; -o -exec test -d '{}/json' \; -o -exec test -d '{}/db' \; \) -print | head -1)
        u=$(find "$x" -maxdepth 4 -type d -name uploads -print | head -1)
        [ -z "$d" ] && [ -n "$(find "$x" -maxdepth 1 -mindepth 1 -name keys -o -maxdepth 1 -mindepth 1 -name json)" ] && d=$x   # Archiv enthält direkt den Inhalt von data/
        [ -n "$d$u" ] || die "Im Archiv weder data/ noch uploads/ gefunden."
        if [ -n "$d" ]; then
            rm -f "$d/config.php"   # Datenbank-Zugang dieses Servers behalten
            cp -a "$d/." "$WEB_DIR/data/"; ok "data/ übernommen (Schlüssel: $(ls "$d"/keys/.*_key_*.php 2>/dev/null | wc -l))"
            [ -d "$d/keys" ] || warn "Keine Schlüssel (data/keys) im Archiv – verschlüsselte Einstellungen (z. B. Discord-Secret) müssen neu eingetragen werden."
        fi
        if [ -n "$u" ]; then mkdir -p "$WEB_DIR/uploads"; cp -a "$u/." "$WEB_DIR/uploads/"; ok "uploads/ übernommen ($(find "$u" -type f | wc -l) Dateien)"; fi
        chown -R "$APP_USER:$APP_USER" "$WEB_DIR/data" "$WEB_DIR/uploads" 2>/dev/null || true
        chmod 600 "$cfg"
    elif [ -z "${MIG_DB_ONLY:-}" ]; then
        warn "Ohne --files: Schlüssel, JSON-Daten und Bilder aus Plesk fehlen (data/, uploads/) – später mit „migrate --db=… --files=…“ nachholen."
    fi
    svc start apache2
    rm -rf "$work"
    step "Ergebnis"
    local c; c=$(http_code "$DOMAIN" "/")
    [[ "$c" =~ ^(200|301|302)$ ]] && ok "Webseite antwortet ($c)" || fail "Webseite antwortet mit $c – Log: /var/log/apache2/ghostnode-error.log"
    echo
    echo "  ${c_b}Nächste Schritte:${c_0}"
    echo "   1. Anmelden testen:  in /etc/hosts deines PCs „$(public_ip) $DOMAIN www.$DOMAIN“ eintragen und https://$DOMAIN öffnen (oder erst nach DNS)."
    echo "   2. DNS: A-Einträge für $DOMAIN und www.$DOMAIN auf $(public_ip) (CNAMEs auf Plesk löschen)."
    echo "   3. Zertifikat:  bash $0 certs"
    echo "   4. Plesk erst abschalten, wenn alles läuft. Sicherung vom Stand vorher: $safety"
}

# ---------------------------------------------------------------- Befehle
load_conf
detect_env
if [ -f "$CONF" ]; then apply_role; fi
case "$CMD" in
    install)
        ask_config; ask_secrets
        base_packages
        if { want hr || want bot; } && [ "$RUN_MODE" = systemd ]; then install_node; fi
        want mail && setup_mail
        want web  && setup_web
        want db   && setup_db
        want web  && setup_olddb
        want web  && [ "$RUN_MODE" = systemd ] && setup_live
        want hr   && setup_hr
        want bot  && setup_bot
        want turn && setup_turn
        want voice && setup_voice
        want ftp  && setup_ftp
        want cron && setup_cron
        want tls  && setup_tls
        want fw   && setup_firewall
        write_credentials
        check_all || true
        summary ;;
    update)
        [ -f "$CONF" ] || die "Noch nicht eingerichtet – zuerst: $0 install"
        # Auto-Updater: zuerst dieses Script selbst von GitHub holen, dann mit der neuen Fassung weitermachen
        self_repo=$(cd "$(dirname "$(readlink -f "$0")")/.." && pwd)
        if [ -z "${GN_SELF_UPDATED:-}" ] && [ -d "$self_repo/.git" ]; then
            step "Installer-Script aktualisieren"
            git -C "$self_repo" fetch -q origin main && git -C "$self_repo" reset -q --hard origin/main && ok "$(git -C "$self_repo" log -1 --format='%h %s')" || warn "Script-Update fehlgeschlagen – weiter mit der vorhandenen Fassung."
            GN_SELF_UPDATED=1 exec bash "$(readlink -f "$0")" update "${ARGS[@]}"
        fi
        if want web; then step "Webseite"; git_as -C "$WEB_DIR" pull --ff-only || die "git pull der Webseite fehlgeschlagen."; ok "aktuell: $(git_as -C "$WEB_DIR" log -1 --format='%h %s')"; svc reload apache2 || true; fi
        want web && [ "$RUN_MODE" = systemd ] && setup_live
        want hr  && setup_hr
        want bot && setup_bot
        want cron && setup_cron   # Wächter/Backup/Auto-Update mit dem neuesten Stand
        check_all ;;
    certs)
        [ -f "$CONF" ] || die "Noch nicht eingerichtet – zuerst: $0 install"
        [ -n "${LE_EMAIL:-}" ] || { YES=0; ask LE_EMAIL "E-Mail für Let's Encrypt" ""; save_kv "$CONF" "${CONF_VARS[@]}"; }
        NO_TLS=0; setup_tls; check_all || true ;;
    secrets)
        [ -f "$CONF" ] || die "Noch nicht eingerichtet – zuerst: $0 install"
        for v in HYPERATE_API_KEY DISCORD_TOKEN DISCORD_APP_ID DISCORD_GUILD_ID RELEASE_CHANNEL_ID DISCORD_CLIENT_SECRET GN_DISCORD_CLIENT_ID GN_DISCORD_CLIENT_SECRET STATUS_WEBHOOK SMTP_HOST SMTP_PORT SMTP_USER SMTP_PASS SMTP_FROM; do
            [ -n "${!v:-}" ] && echo "  $v: gesetzt (Enter = behalten, - = löschen)" || echo "  $v: leer"
            if [ -t 0 ]; then read -r -s -p "  neuer Wert für $v: " nv; echo; [ "$nv" = "-" ] && printf -v "$v" '%s' "" || { [ -n "$nv" ] && printf -v "$v" '%s' "$nv"; }; fi
        done
        save_kv "$SECRETS" "${SECRET_VARS[@]}"
        want mail && setup_mail; want web && setup_web; want hr && setup_hr; want bot && setup_bot
        check_all || true ;;
    check)  [ -f "$CONF" ] || die "Noch nicht eingerichtet – zuerst: $0 install"; check_all ;;
    migrate) cmd_migrate ;;
    crons) cmd_crons ;;
    olddb)
        [ -f "$CONF" ] || die "Noch nicht eingerichtet – zuerst: $0 install"
        if [ -z "${OLD_DB_HOST:-}" ] || [[ " ${ARGS[*]} " == *" --neu "* ]]; then
            YES=0; OLD_DB_HOST=""; ask OLD_DB_HOST "Adresse der alten MySQL" ""; ask OLD_DB_PORT "Port" "3306"; ask OLD_DB_NAME "Datenbank" ""; ask OLD_DB_USER "Benutzer" ""
            OLD_DB_PASS=""; ask OLD_DB_PASS "Passwort" "" s; ask OLD_DB_MIRROR "Danach als Backup-Ziel nutzen? j/n" "j"
            case "$OLD_DB_MIRROR" in j|J|ja|y|yes|1) OLD_DB_MIRROR=1 ;; *) OLD_DB_MIRROR=0 ;; esac
            save_kv "$CONF" "${CONF_VARS[@]}"; save_kv "$SECRETS" "${SECRET_VARS[@]}"; chmod 600 "$SECRETS"
        fi
        [[ " ${ARGS[*]} " == *" --force "* ]] && rm -f /var/lib/ghostnode/old-db-imported
        setup_olddb; want cron && setup_cron ;;
    backup) [ -x /usr/local/sbin/ghostnode-backup ] || die "Backup nicht eingerichtet."; /usr/local/sbin/ghostnode-backup && ok "Backup: $(ls -t /var/backups/ghostnode/ghostnode-*.tar | head -1)" ;;
    *) die "Unbekannter Befehl: $CMD (install, update, certs, secrets, check, backup, migrate, crons, olddb)" ;;
esac
