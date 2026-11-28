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
#  Aufruf (als root):   ghostnode <befehl>   (Befehl wird bei install/update angelegt; sonst einmal: bash ghostnode-root.sh cli)
#    bash ghostnode-root.sh install            alles einrichten (fragt nach Domain, E-Mail und Schlüsseln)
#    bash ghostnode-root.sh update             Webseite, Weiterleitung und Bot aktualisieren, Dienste neu starten
#    bash ghostnode-root.sh bot                nur den Discord-Bot aktualisieren (Git oder neueste Release-ZIP), neu starten und prüfen
#    bash ghostnode-root.sh certs              Zertifikate (erneut) holen – z. B. wenn DNS beim install noch nicht stand
#    bash ghostnode-root.sh secrets            Schlüssel (Discord, HypeRate, SMTP …) ändern und übernehmen
#    bash ghostnode-root.sh bungie             nur die Bungie-Werte für D2 Nexus (Anmeldung + Chat) eintragen
#    bash ghostnode-root.sh check              alles prüfen (Dienste, Seiten, PHP-Erweiterungen, Zertifikate)
#    bash ghostnode-root.sh backup             Server-Backup jetzt (Datenbank, data/, Bot-Daten, Einstellungen)
#    bash ghostnode-root.sh login …            Notfall-Zugang zur Webseite: liste | entsperren | passwort BENUTZER | 2fa-aus BENUTZER | aktivieren BENUTZER
#    bash ghostnode-root.sh bericht            Zustand zum Weitergeben (Versionen, Dienste, Datenbank, letzte Fehler) – ohne Passwörter
#    bash ghostnode-root.sh usedb              Webseite auf die MariaDB umschalten (zeigt vorher die Konten dort)
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
#    --skip=a,b       diese Teile auslassen (music = Musik-Dienst Lavalink für den Bot)
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

# ---------------------------------------------------------------- Hilfe + Befehl „ghostnode“
show_help() {
    local b='' d='' z=''; [ -t 1 ] && { b=$'\e[1;36m'; d=$'\e[2m'; z=$'\e[0m'; }
    cat <<EOF
${b}ghostnode${z} – GhostNode Root-Server verwalten (als root)

${b}Aufruf:${z} ghostnode <befehl> [optionen]      ${d}(oder: bash $(readlink -f "$0") <befehl>)${z}

${b}Täglich${z}
  update            alles aktualisieren: Webseite, Bot, Musik (Lavalink, yt-dlp, ffmpeg), Firewall/fail2ban, Befehl – dann prüfen
  bot               nur den Discord-Bot aktualisieren (Git oder neueste Release-ZIP), neu starten, prüfen
  check             alles prüfen (Dienste, Seiten, PHP-Erweiterungen, Zertifikate)
  bericht           Zustand zum Weitergeben (Versionen, Dienste, letzte Fehler) – ohne Passwörter
  crons             geplante Aufgaben mit letztem Ergebnis
  backup            Server-Backup jetzt

${b}Einstellungen${z}
  secrets           Schlüssel (Discord, HypeRate, SMTP …) ändern und übernehmen
  bungie            Bungie-Werte für D2 Nexus eintragen
  certs             Zertifikate (erneut) holen
  scriptload        eigene Skripte aus server/scripts (Git) auf den Server spielen → Befehle gn-NAME
  scripts           installierte eigene Skripte anzeigen      script NAME [arg]  eines ausführen
  musik-login [neu|aus]   YouTube-Anmeldung für die Bot-Musik („This video requires login“)

${b}Notfall & Umzug${z}
  login liste | entsperren | passwort BENUTZER | 2fa-aus BENUTZER | aktivieren BENUTZER
  usedb             Webseite auf die MariaDB umschalten
  olddb [--force|--neu]   alte MySQL (z. B. Plesk) übernehmen
  migrate --db=… --files=… | --from=HOST --src-db=NAME --src-user=BENUTZER

${b}Einrichtung${z}
  install           alles einrichten (fragt nach Domain, E-Mail und Schlüsseln)
  cli               den Befehl „ghostnode“ (und Tab-Vervollständigung) neu anlegen
  help              diese Hilfe

${b}Optionen${z}
  --yes / -y        nicht nachfragen          --no-tls     ohne Zertifikate
  --only=teil,…     nur diese Teile           --skip=teil,…  diese Teile auslassen
  ${d}Teile: web db tls hr bot music turn ftp mail voice cron fw${z}

${b}Beispiele${z}
  ghostnode update            ghostnode bot            ghostnode check
  ghostnode update --skip=bot ghostnode login liste    ghostnode bericht
EOF
}
install_cli() {   # /usr/local/bin/ghostnode → dieses Script (bleibt nach Updates gültig, weil es auf den Git-Checkout zeigt)
    local self; self=$(readlink -f "$0")
    ln -sfn "$self" /usr/local/bin/ghostnode
    if [ -d /etc/bash_completion.d ]; then
        cat > /etc/bash_completion.d/ghostnode <<'EOF'
# erzeugt von ghostnode-root.sh
_ghostnode() {
    local cur=${COMP_WORDS[COMP_CWORD]} prev=${COMP_WORDS[1]:-}
    if [ "$COMP_CWORD" -eq 1 ]; then
        COMPREPLY=($(compgen -W "update bot check bericht crons backup secrets bungie certs scriptload scripts script musik-login login usedb olddb migrate install cli help" -- "$cur"))
    elif [ "$prev" = musik-login ] && [ "$COMP_CWORD" -eq 2 ]; then
        COMPREPLY=($(compgen -W "neu aus" -- "$cur"))
    elif [ "$prev" = login ] && [ "$COMP_CWORD" -eq 2 ]; then
        COMPREPLY=($(compgen -W "liste entsperren passwort 2fa-aus aktivieren" -- "$cur"))
    else
        COMPREPLY=($(compgen -W "--yes --no-tls --only= --skip= --force --neu --db= --files= --from= --src-db= --src-user= --src-port=" -- "$cur"))
    fi
}
complete -o nospace -F _ghostnode ghostnode
EOF
    fi
}

# ---------------------------------------------------------------- Optionen
CMD=${1:-help}; [ $# -gt 0 ] && shift
case "$CMD" in -h|--help|help|hilfe|'?') show_help; exit 0 ;; esac
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
        -h|--help) show_help; exit 0 ;;
        *) [ "$CMD" = login ] || [ "$CMD" = musik-login ] || [ "$CMD" = script ] || die "Unbekannte Option: $a (Hilfe: --help)" ;;   # login: Unterbefehl + Benutzername
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
             REDDIT_CLIENT_ID REDDIT_CLIENT_SECRET TWITCH_EXT_CLIENT_ID TWITCH_EXT_SECRET DB_PASS TURN_SECRET FTP_PASS MUMBLE_SUPW MUMBLE_PASS BOT_SSO_SECRET OLD_DB_PASS LIVE_SECRET GN_BUNGIE_KEY GN_BUNGIE_CLIENT_ID GN_BUNGIE_CLIENT_SECRET LAVALINK_PASSWORD YT_REFRESH_TOKEN)
CONF_VARS=(ROLE RUN_MODE DOMAIN LE_EMAIL WEB_DIR DB_ENGINE DB_NAME DB_USER HR_HOST BOT_HOST TURN_HOST VOICE_HOST HR_UPSTREAM BOT_UPSTREAM FTP_USER TZ_NAME OLD_DB_HOST OLD_DB_PORT OLD_DB_NAME OLD_DB_USER OLD_DB_MIRROR AUTO_UPDATE YT_OAUTH)

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
        ask GN_BUNGIE_KEY            "Bungie-API-Key (D2 Nexus: Anmeldung + Webseite, optional)" "" s
        ask GN_BUNGIE_CLIENT_ID      "… Bungie-OAuth-Client-ID" ""
        ask GN_BUNGIE_CLIENT_SECRET  "… Bungie-OAuth-Client-Secret" "" s
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
    DB_PASS=${DB_PASS:-$(rand 32)}; TURN_SECRET=${TURN_SECRET:-$(rand 48)}; FTP_PASS=${FTP_PASS:-$(rand 20)}; MUMBLE_SUPW=${MUMBLE_SUPW:-$(rand 24)}; BOT_SSO_SECRET=${BOT_SSO_SECRET:-$(rand 48)}; LAVALINK_PASSWORD=${LAVALINK_PASSWORD:-$(rand 40)}; LIVE_SECRET=${LIVE_SECRET:-$(rand 48)}
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
        for v in GN_DISCORD_CLIENT_ID GN_DISCORD_CLIENT_SECRET STATUS_WEBHOOK REDDIT_CLIENT_ID REDDIT_CLIENT_SECRET TWITCH_EXT_CLIENT_ID TWITCH_EXT_SECRET GN_BOT_SSO_SECRET GN_BOT_SSO_URL GN_LIVE_SECRET GN_BUNGIE_KEY GN_BUNGIE_CLIENT_ID GN_BUNGIE_CLIENT_SECRET; do
            [ -n "${!v:-}" ] && printf 'env[%s] = "%s"\n' "$v" "${!v//\"/}"
        done
        echo 'env[PATH] = /usr/local/bin:/usr/bin:/bin'
    } > "$POOL"
    chmod 640 "$POOL"
    # Standard-Pool „www“ bleibt (das Pterodactyl-Panel nutzt ihn); ältere Script-Versionen hatten ihn abgeschaltet → zurückholen
    if [ -f "/etc/php/$PV/fpm/pool.d/www.conf.aus" ] && [ ! -f "/etc/php/$PV/fpm/pool.d/www.conf" ]; then mv "/etc/php/$PV/fpm/pool.d/www.conf.aus" "/etc/php/$PV/fpm/pool.d/www.conf"; fi
    # gleiche Variablen für Cron (Kommandozeile)
    save_kv "$CONF_DIR/web.env" GN_DISCORD_CLIENT_ID GN_DISCORD_CLIENT_SECRET STATUS_WEBHOOK REDDIT_CLIENT_ID REDDIT_CLIENT_SECRET TWITCH_EXT_CLIENT_ID TWITCH_EXT_SECRET GN_BOT_SSO_SECRET GN_BOT_SSO_URL GN_LIVE_SECRET GN_BUNGIE_KEY GN_BUNGIE_CLIENT_ID GN_BUNGIE_CLIENT_SECRET
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
nginx_site() {   # nginx_site NAME "HOSTS" PORT web|sse|plain|redirect – schreibt HTTP + (mit Zertifikat) HTTPS selbst
    local name=$1 hosts=$2 port=$3 kind=$4 f=/etc/nginx/sites-available/$1.conf up
    up=$port; [[ "$up" == *:* ]] || up="127.0.0.1:$up"
    command -v nginx >/dev/null || { apt-get install -y -qq nginx >/dev/null; rm -f /etc/nginx/sites-enabled/default; }
    # HTTPS-Standard: unbekannte Namen ablehnen – sonst beantwortet der erste Block mit Zertifikat alles (z. B. die turn/voice-
    # Umleitung → Weiterleitungs-Schleife). Nur, wenn noch kein anderer default_server auf 443 existiert (z. B. Pterodactyl-Panel).
    local dflt=/etc/nginx/sites-available/00-ghostnode-default-tls.conf
    if [ ! -f "$dflt" ] && ! grep -rqs 'listen[^;]*443[^;]*default_server' /etc/nginx/sites-enabled/ /etc/nginx/conf.d/; then
        { echo "# erzeugt von ghostnode-root.sh – unbekannte Namen auf HTTPS ablehnen"; echo "server {"; echo "    listen 443 ssl default_server;"
          [ -s /proc/net/if_inet6 ] && echo "    listen [::]:443 ssl default_server;"; echo "    server_name _;"; echo "    ssl_reject_handshake on;"; echo "}"; } > "$dflt"
        ln -sf "$dflt" /etc/nginx/sites-enabled/00-ghostnode-default-tls.conf
        nginx -t >/dev/null 2>&1 || rm -f /etc/nginx/sites-enabled/00-ghostnode-default-tls.conf "$dflt"   # zu altes nginx → weglassen
    fi
    # merken, damit „certs“ alle Blöcke mit HTTPS neu schreiben kann
    install -d -m 0750 "$CONF_DIR"; touch "$CONF_DIR/nginx-sites"
    grep -v "^$name|" "$CONF_DIR/nginx-sites" > "$CONF_DIR/nginx-sites.tmp" || true
    printf '%s|%s|%s|%s\n' "$name" "$hosts" "$port" "$kind" >> "$CONF_DIR/nginx-sites.tmp"; mv "$CONF_DIR/nginx-sites.tmp" "$CONF_DIR/nginx-sites"
    install -d -m 0755 /var/www/letsencrypt
    local cert=/etc/letsencrypt/live/ghostnode v6=""; [ -s /proc/net/if_inet6 ] && v6=1
    local tls=""; [ -s "$cert/fullchain.pem" ] && [ -s "$cert/privkey.pem" ] && tls=1
    body() {   # Inhalt eines Server-Blocks (gleich für HTTP ohne Zertifikat und HTTPS)
        if [ "$kind" = redirect ]; then echo "    location / { return 302 https://$DOMAIN\$request_uri; }"; return; fi
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
        echo "    }"
    }
    {
        echo "# erzeugt von ghostnode-root.sh – HTTP$([ -n "$tls" ] && echo ' → HTTPS') für: $hosts (nicht von Hand ändern, wird bei install/certs neu geschrieben)"
        echo "server {"
        echo "    listen 80;"; [ -n "$v6" ] && echo "    listen [::]:80;"
        echo "    server_name $hosts;"
        echo "    location /.well-known/acme-challenge/ { root /var/www/letsencrypt; }"
        if [ -n "$tls" ]; then echo "    location / { return 301 https://\$host\$request_uri; }"; else body; fi
        echo "}"
        if [ -n "$tls" ]; then
            echo "server {"
            echo "    listen 443 ssl;"; [ -n "$v6" ] && echo "    listen [::]:443 ssl;"
            echo "    server_name $hosts;"
            echo "    ssl_certificate $cert/fullchain.pem;"; echo "    ssl_certificate_key $cert/privkey.pem;"
            echo "    ssl_protocols TLSv1.2 TLSv1.3;"; echo "    ssl_session_cache shared:GhostNodeSSL:10m;"; echo "    ssl_session_timeout 1d;"
            echo "    add_header Strict-Transport-Security \"max-age=31536000\" always;"
            body
            echo "}"
        fi
    } > "$f"
    unset -f body
    ln -sf "$f" "/etc/nginx/sites-enabled/$name.conf"
    if ! nginx -t >/dev/null 2>&1; then
        # sofort zurücknehmen – nginx (und damit z. B. das Pterodactyl-Panel) läuft mit der alten Konfiguration weiter
        local why; why=$(nginx -t 2>&1 | tail -2 || true)
        rm -f "/etc/nginx/sites-enabled/$name.conf" "$f"
        die "nginx-Konfiguration fehlerhaft ($name) – zurückgenommen, nichts verändert: $why"
    fi
    if is_active nginx; then svc reload nginx 2>/dev/null || svc restart nginx; else svc enable nginx 2>/dev/null || true; svc restart nginx; fi   # z. B. frisch installiert, während Apache noch auf 80 lag
}
nginx_refresh() {   # alle gemerkten Blöcke neu schreiben (z. B. nach neuem Zertifikat → HTTPS überall)
    [ -f "$CONF_DIR/nginx-sites" ] || return 0
    local n h p k
    while IFS='|' read -r n h p k; do [ -n "$n" ] && [ -f "/etc/nginx/sites-available/$n.conf" ] && nginx_site "$n" "$h" "$p" "$k"; done < <(cat "$CONF_DIR/nginx-sites")
    return 0
}

write_db_cfg() {   # write_db_cfg PRÄFIX – data/config.php auf die lokale MariaDB
    local cfg="$WEB_DIR/data/config.php" j
    j=$(DB_NAME="$DB_NAME" DB_USER="$DB_USER" DB_PASS="$DB_PASS" PRE="$1" php -r 'echo json_encode(["driver"=>"mysql","host"=>"localhost","port"=>3306,"name"=>getenv("DB_NAME"),"user"=>getenv("DB_USER"),"pass"=>getenv("DB_PASS"),"prefix"=>getenv("PRE"),"socket"=>"/run/mysqld/mysqld.sock"], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);')
    install -d -o "$APP_USER" -g "$APP_USER" "$WEB_DIR/data"
    printf '<?php http_response_code(404); exit; ?>\n%s' "$j" > "$cfg"
    chown "$APP_USER:$APP_USER" "$cfg"; chmod 600 "$cfg"
}
mariadb_prefix() {   # Präfix der GhostNode-Tabellen in der MariaDB (z. B. gn_) – dort, wo roles, users und settings zusammen liegen
    mysql --protocol=socket -uroot -N -e "SELECT LEFT(r.table_name, LENGTH(r.table_name) - 5) FROM information_schema.tables r
        JOIN information_schema.tables u ON u.table_schema = r.table_schema AND u.table_name = CONCAT(LEFT(r.table_name, LENGTH(r.table_name) - 5), 'users')
        JOIN information_schema.tables s ON s.table_schema = r.table_schema AND s.table_name = CONCAT(LEFT(r.table_name, LENGTH(r.table_name) - 5), 'settings')
        WHERE r.table_schema = '$DB_NAME' AND r.table_name LIKE '%roles' ORDER BY LENGTH(r.table_name) LIMIT 1" 2>/dev/null || true
}
cmd_bericht() {   # Zustand zum Weitergeben (z. B. an Claude) – ohne Passwörter, Tokens oder Schlüssel
    local mask='s/(pass(word)?|secret|token|key|supw)([^=:]*)[=:]["'"'"' ]*[^"'"'"' ,}]+/\1\3=***/Ig'
    echo "===== GhostNode-Bericht $(date '+%d.%m.%Y %H:%M') ====="
    echo "System:     $(. /etc/os-release; echo "$PRETTY_NAME") · $(uname -r) · $(nproc) Kerne · RAM $(free -h | awk '/^Mem/{print $3"/"$2}') · Platte $(df -h / | awk 'NR==2{print $3"/"$2" ("$5")"}')"
    echo "Rolle:      ${ROLE:-?} · Domain ${DOMAIN:-?} · Datenbank ${DB_ENGINE:-?} · Betrieb ${RUN_MODE:-?} · Auto-Update ${AUTO_UPDATE:-1}"
    echo "Installer:  $(git -C "$(dirname "$(readlink -f "$0")")/.." log -1 --format='%h %cd' --date=short 2>/dev/null || echo ?)"
    want web && echo "Webseite:   $(git -c safe.directory='*' -C "$WEB_DIR" log -1 --format='%h %cd' --date=short 2>/dev/null || echo ?) · DB-Treiber $(grep -o '"driver": *"[a-z]*"' "$WEB_DIR/data/config.php" 2>/dev/null | cut -d'"' -f4 || echo ?) · Präfix $(grep -o '"prefix": *"[A-Za-z0-9_]*"' "$WEB_DIR/data/config.php" 2>/dev/null | cut -d'"' -f4)"
    [ -f /opt/ghostnode/discord-bot/package.json ] && echo "Bot:        $(grep -o '"version": *"[^"]*"' /opt/ghostnode/discord-bot/package.json | cut -d'"' -f4)"
    echo "PHP:        $(php -r 'echo PHP_VERSION;' 2>/dev/null) · Node $(node -v 2>/dev/null) · nginx $(nginx -v 2>&1 | cut -d/ -f2) · MariaDB $(mysql -V 2>/dev/null | grep -o 'Distrib [0-9.]*\|from [0-9.]*' | head -1)"
    [ -f /var/lib/ghostnode/old-db-imported ] && echo "Alte MySQL: übernommen am $(cat /var/lib/ghostnode/old-db-imported) · Spiegeln ${OLD_DB_MIRROR:-0}"
    if [ "$DB_ENGINE" = mariadb ] && want db; then
        local pre; pre=$(mariadb_prefix)
        echo "MariaDB:    $(mysql --protocol=socket -uroot -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$DB_NAME'" 2>/dev/null) Tabellen · Präfix ${pre:-–} · Konten: $(mysql --protocol=socket -uroot -N -e "SELECT GROUP_CONCAT(username SEPARATOR ', ') FROM \`$DB_NAME\`.\`${pre}users\`" 2>/dev/null)"
    fi
    echo; echo "--- Dienste"
    local u; for u in nginx apache2 "php$(php_ver 2>/dev/null)-fpm" mariadb ghostnode-live ghostnode-bot ghostnode-lavalink ghostnode-hr coturn mumble-server vsftpd cron fail2ban; do
        if [ -f "/lib/systemd/system/$u.service" ] || [ -f "/etc/systemd/system/$u.service" ] || [ -x "/etc/init.d/$u" ]; then printf '  %-22s %s\n' "$u" "$(is_active "$u" && echo läuft || echo AUS)"; fi
    done
    echo; echo "--- Geplante Aufgaben"; for u in /var/lib/ghostnode/cron-*.status; do [ -f "$u" ] && { read -r t rc < "$u"; printf '  %-22s %s rc=%s\n' "$(basename "$u" .status | cut -c6-)" "$(date -d "@$t" '+%d.%m. %H:%M')" "$rc"; }; done
    echo; echo "--- Letzte Fehler Webseite (apache)"; tail -n 12 /var/log/apache2/ghostnode-error.log 2>/dev/null | cut -c1-300 | sed -E "$mask" | sed 's/^/  /'
    echo; echo "--- Letzte Fehler PHP-FPM"; tail -n 8 /var/log/php*-fpm.log 2>/dev/null | cut -c1-300 | sed 's/^/  /'
    for u in ghostnode-bot ghostnode-hr ghostnode-live; do echo; echo "--- $u (letzte Zeilen)"; journalctl -u "$u" -n 6 --no-pager -o cat 2>/dev/null | cut -c1-240 | sed -E "$mask" | sed 's/^/  /'; done
    echo; echo "--- Cron-Protokoll"; tail -n 10 /var/log/ghostnode-cron.log 2>/dev/null | sed 's/^/  /'
    echo "===== Ende (enthält keine Passwörter/Schlüssel) ====="
}
cmd_usedb() {   # Webseite auf die lokale MariaDB umschalten (z. B. nach Übernahme der alten MySQL)
    step "Webseite auf MariaDB umschalten"
    want web && [ "$DB_ENGINE" = mariadb ] || die "Nur mit Rolle „alles“ und MariaDB."
    local pre n cfg="$WEB_DIR/data/config.php"
    pre=$(mariadb_prefix)
    n=$(mysql --protocol=socket -uroot -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$DB_NAME'" 2>/dev/null || echo 0)
    [ "${n:-0}" -gt 0 ] && [ -n "$pre$(mysql --protocol=socket -uroot -N -e "SHOW TABLES IN \`$DB_NAME\` LIKE 'users'" 2>/dev/null)" ] || die "In MariaDB „$DB_NAME“ liegt noch keine GhostNode-Datenbank – zuerst: $0 olddb"
    ok "MariaDB „$DB_NAME“: $n Tabellen, Präfix „${pre:-–}“"
    echo "  Konten dort:"; mysql --protocol=socket -uroot -N -e "SELECT CONCAT('    ', username, IF(last_login IS NULL, '', CONCAT('  (zuletzt ', last_login, ')'))) FROM \`$DB_NAME\`.\`${pre}users\` ORDER BY username" 2>/dev/null | head -20
    if [ -f "$cfg" ] && grep -q '"driver": *"mysql"' "$cfg" && grep -q "\"prefix\": *\"$pre\"" "$cfg"; then ok "Webseite nutzt MariaDB bereits"; return 0; fi
    if [ "$YES" != 1 ] && [ -t 0 ]; then read -r -p "  Webseite jetzt auf diese Daten umschalten? (SQLite-Stand wird gesichert) [j/N] " a; [[ "$a" =~ ^[jJyY] ]] || { warn "abgebrochen"; return 0; }; fi
    [ -f "$cfg" ] && cp -a "$cfg" "$cfg.vor-umschalten-$(date +%Y%m%d-%H%M%S)"
    write_db_cfg "$pre"
    svc reload "php$(php_ver)-fpm" 2>/dev/null || true
    ok "Webseite nutzt jetzt MariaDB (Präfix „$pre“) – alte Einstellung gesichert als data/config.php.vor-umschalten-…"
    echo "  Anmelden mit deinem alten Konto (Passwort vergessen? $0 login passwort BENUTZER)"
}
sqlite_users() {   # Anzahl Benutzer in den SQLite-Dateien der Webseite (0 = leer/frisch)
    php -r '$n = 0; foreach (glob($argv[1] . "/data/{,db/}*.{sqlite,db}", GLOB_BRACE) ?: [] as $f) { try { $p = new PDO("sqlite:" . $f); $n += (int)$p->query("SELECT COUNT(*) FROM users")->fetchColumn(); } catch (Throwable $e) {} } echo $n;' "$WEB_DIR" 2>/dev/null || echo 0
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
    if [ -f "$cfg" ] && ! grep -q '"driver": *"mysql"' "$cfg"; then
        # SQLite-Einstellung vorhanden (z. B. weil die Seite vor der Datenbank aufgerufen wurde): umstellen, wenn dort noch nichts
        # Wichtiges liegt oder die alte MySQL übernommen wurde – sonst würde die Seite die MariaDB nie benutzen
        local n; n=$(sqlite_users)
        if [ -f /var/lib/ghostnode/old-db-imported ] || [ "$n" = 0 ]; then
            cp -a "$cfg" "$cfg.sqlite-$(date +%Y%m%d-%H%M%S)"; local p2; p2=$(mariadb_prefix); write_db_cfg "${p2:-gn_}"
            ok "Webseite von SQLite auf MariaDB umgestellt$([ "$n" != 0 ] && echo " (SQLite hatte $n Benutzer – MariaDB mit der übernommenen alten MySQL ist maßgeblich)")"
        else
            warn "data/config.php nutzt SQLite mit $n Benutzer(n) – nicht überschrieben. Zu MariaDB umziehen: Admin → Datenbank (Zugang steht in $CRED)."
        fi
    else
        local pre=gn_; [ -f "$cfg" ] && pre=$(grep -o '"prefix": *"[A-Za-z0-9_]*"' "$cfg" | sed -E 's/.*"([A-Za-z0-9_]*)"$/\1/')
        write_db_cfg "${pre:-gn_}"
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
    local before=""; [ -f "$dir/package.json" ] && before=$(grep -o '"version": *"[^"]*"' "$dir/package.json" | cut -d'"' -f4)
    if [ -d "$dir/.git" ]; then
        # fetch + reset statt pull: klappt auch, wenn am Server etwas an den Programmdateien geändert wurde (data/ und .env sind nicht im Git)
        if git_as -C "$dir" fetch -q origin main && git_as -C "$dir" reset -q --hard origin/main; then ok "Code: $(git_as -C "$dir" log -1 --format='%h %s' | cut -c1-90)"
        else warn "Git-Update (Bot) fehlgeschlagen – versuche die Release-ZIP."; bot_zip_update "$dir" || warn "Bot nicht aktualisiert – alte Version bleibt."; fi
    elif [ -d "$dir" ] && [ -n "$(ls -A "$dir" 2>/dev/null)" ]; then
        bot_zip_update "$dir" || warn "Bot nicht aktualisiert (kein Git-Checkout, ZIP nicht ladbar) – alte Version bleibt."
    else install -d -o "$APP_USER" -g "$APP_USER" "$dir"; git_as clone -q "$REPO_BOT" "$dir" || die "Bot konnte nicht geholt werden ($REPO_BOT)."; fi
    local after; after=$(grep -o '"version": *"[^"]*"' "$dir/package.json" 2>/dev/null | cut -d'"' -f4)
    [ -n "$before" ] && [ "$before" != "$after" ] && ok "Version $before → $after" || ok "Version ${after:-?}"
    install -d -o "$APP_USER" -g "$APP_USER" -m 0750 "$dir/data"
    local DASHBOARD_URL="https://$BOT_HOST" NEXUS_BASE="https://$DOMAIN" HYPERATE_RELAY="https://$HR_HOST" BOT_DATA="$dir/data" PORT=$BOT_PORT HOST=127.0.0.1
    local LAVALINK_URL="" YTDLP="" YTDLP_COOKIES=""
    [ -n "${LAVALINK_PASSWORD:-}" ] || { LAVALINK_PASSWORD=$(rand 40); save_kv "$SECRETS" "${SECRET_VARS[@]}"; chmod 600 "$SECRETS"; }
    if [[ ",$SKIP," != *",music,"* ]] && setup_lavalink; then
        LAVALINK_URL="http://127.0.0.1:$LAVALINK_PORT"
        if setup_ytdlp; then
            YTDLP=/usr/local/bin/yt-dlp
            if [ -f "$CONF_DIR/yt-cookies.txt" ]; then   # Kopie im Datenordner des Bots (dort darf der Dienst lesen/schreiben; /etc/ghostnode nicht)
                install -o "$APP_USER" -g "$APP_USER" -m 600 "$CONF_DIR/yt-cookies.txt" "$dir/data/yt-cookies.txt"; YTDLP_COOKIES="$dir/data/yt-cookies.txt"
            else rm -f "$dir/data/yt-cookies.txt"; fi
        fi
    fi
    save_kv "$CONF_DIR/bot.env" DISCORD_TOKEN DISCORD_APP_ID DISCORD_GUILD_ID RELEASE_CHANNEL_ID DISCORD_CLIENT_SECRET DASHBOARD_URL NEXUS_BASE HYPERATE_RELAY BOT_DATA PORT HOST BOT_SSO_SECRET LAVALINK_URL LAVALINK_PASSWORD YTDLP YTDLP_COOKIES
    chmod 600 "$CONF_DIR/bot.env"
    need_port "$BOT_PORT" node
    write_unit ghostnode-bot "GhostNode Discord-Bot (VRC Nexus)" "$dir" bot.js "$CONF_DIR/bot.env"
    proxy_vhost ghostnode-bot "$BOT_HOST" "$BOT_PORT"
    [ -n "${DISCORD_TOKEN:-}" ] || warn "Kein Bot-Token – der Bot zeigt nur eine Hinweisseite (später: $0 secrets)."
    [ -n "${DISCORD_CLIENT_SECRET:-}" ] || warn "Kein OAuth2-Secret – Dashboard-Anmeldung aus. Im Discord-Portal Redirect https://$BOT_HOST/callback eintragen."
    svc enable ghostnode-bot; svc restart ghostnode-bot
    ok "läuft auf 127.0.0.1:$BOT_PORT → https://$BOT_HOST"
    bot_check
}

self_update() {   # Auto-Updater: zuerst dieses Script selbst von GitHub holen, dann mit der neuen Fassung weitermachen
    local self_repo; self_repo=$(cd "$(dirname "$(readlink -f "$0")")/.." && pwd)
    if [ -z "${GN_SELF_UPDATED:-}" ] && [ -d "$self_repo/.git" ]; then
        step "Installer-Script aktualisieren"
        git -C "$self_repo" fetch -q origin main && git -C "$self_repo" reset -q --hard origin/main && ok "$(git -C "$self_repo" log -1 --format='%h %s')" || warn "Script-Update fehlgeschlagen – weiter mit der vorhandenen Fassung."
        GN_SELF_UPDATED=1 exec bash "$(readlink -f "$0")" "$1" "${ARGS[@]}"
    fi
}

LAVALINK_PORT=${LAVALINK_PORT:-2333}
gh_latest() {   # gh_latest OWNER/REPO → Tag der neuesten Veröffentlichung
    curl -fsSL -m 20 -H 'Accept: application/vnd.github+json' "https://api.github.com/repos/$1/releases/latest" 2>/dev/null | python3 -c 'import sys,json
try: print(json.load(sys.stdin).get("tag_name",""))
except Exception: print("")' || true
}
lavalink_yml() {   # lavalink_yml YOUTUBE-VERSION → application.yml (nur 127.0.0.1)
    echo "# erzeugt von ghostnode-root.sh – nur auf 127.0.0.1; Passwort steht nur hier und in $CONF_DIR/bot.env"
    echo "server:"
    echo "  port: $LAVALINK_PORT"
    echo "  address: 127.0.0.1"
    echo "lavalink:"
    if [ -n "$1" ]; then
        echo "  plugins:"
        echo "    - dependency: \"dev.lavalink.youtube:youtube-plugin:$1\""
        echo "      snapshot: false"
    fi
    echo "  server:"
    echo "    password: \"$LAVALINK_PASSWORD\""
    echo "    sources:"
    echo "      youtube: false"
    echo "      bandcamp: true"
    echo "      soundcloud: true"
    echo "      twitch: true"
    echo "      vimeo: true"
    echo "      http: true"
    echo "      local: false"
    echo "    bufferDurationMs: 400"
    echo "    frameBufferDurationMs: 5000"
    echo "    playerUpdateInterval: 5"
    echo "    soundcloudSearchEnabled: true"
    echo "plugins:"
    echo "  youtube:"
    echo "    enabled: true"
    echo "    allowSearch: true"
    echo "    allowDirectVideoIds: true"
    echo "    allowDirectPlaylistIds: true"
    # YouTube verlangt von Rechenzentrums-IPs oft eine Anmeldung („This video requires login“) → mehrere Clients, mit Anmeldung zuerst TV
    if [ "${YT_OAUTH:-0}" = 1 ]; then
        echo "    clients: [\"MUSIC\", \"TV\", \"ANDROID_VR\", \"WEB\", \"WEBEMBEDDED\", \"TVHTML5_SIMPLY\"]"
        echo "    oauth:"
        echo "      enabled: true"
        [ -n "${YT_REFRESH_TOKEN:-}" ] && echo "      refreshToken: \"$YT_REFRESH_TOKEN\""
    else
        echo "    clients: [\"MUSIC\", \"ANDROID_VR\", \"WEB\", \"WEBEMBEDDED\", \"TVHTML5_SIMPLY\"]"
    fi
    echo "logging:"
    echo "  file:"
    echo "    path: ./logs/"
    echo "  level:"
    echo "    root: WARN"
    echo "    lavalink: INFO"
    echo "    dev.lavalink.youtube.http.YoutubeOauth2Handler: INFO"
}
cmd_musik_login() {   # YouTube-Anmeldung für den Musik-Dienst (Google-Gerätecode; Zweitkonto empfohlen)
    local dir=/opt/ghostnode/lavalink yt
    [ -s "$dir/Lavalink.jar" ] || die "Musik-Dienst ist nicht eingerichtet (ghostnode bot)."
    yt=$(sed -n 's/.*youtube-plugin:\([^"]*\)".*/\1/p' "$dir/application.yml" 2>/dev/null | head -1)
    if [ "${ARGS[0]:-}" = aus ]; then
        YT_OAUTH=0; YT_REFRESH_TOKEN=""; save_kv "$CONF" "${CONF_VARS[@]}"; save_kv "$SECRETS" "${SECRET_VARS[@]}"; chmod 600 "$SECRETS"
        ( umask 077; lavalink_yml "$yt" > "$dir/application.yml" ); chown "$APP_USER:$APP_USER" "$dir/application.yml"; chmod 600 "$dir/application.yml"
        svc restart ghostnode-lavalink; ok "YouTube-Anmeldung entfernt (Token gelöscht)."; return 0
    fi
    echo "  ${c_warn}Bitte ein ZWEIT-Google-Konto nehmen, nicht dein Hauptkonto – YouTube kann Konten sperren, die Bots nutzen.${c_0}"
    YT_OAUTH=1; [ "${ARGS[0]:-}" = neu ] && YT_REFRESH_TOKEN=""
    save_kv "$CONF" "${CONF_VARS[@]}"
    ( umask 077; lavalink_yml "$yt" > "$dir/application.yml" ); chown "$APP_USER:$APP_USER" "$dir/application.yml"; chmod 600 "$dir/application.yml"
    if [ -n "${YT_REFRESH_TOKEN:-}" ]; then svc restart ghostnode-lavalink; ok "Anmeldung ist schon gespeichert – Musik-Dienst neu gestartet (neu anmelden: ghostnode musik-login neu)."; return 0; fi
    local since; since=$(date '+%Y-%m-%d %H:%M:%S')
    svc restart ghostnode-lavalink
    step "Warte auf den Anmelde-Code (bis zu 10 Minuten) …"
    local tok="" line shown=0 end=$((SECONDS + 600))
    while [ $SECONDS -lt $end ]; do
        while IFS= read -r line; do
            if [ $shown = 0 ] && echo "$line" | grep -qiE 'google\.com/device|enter (the )?code|code:'; then
                local code; code=$(echo "$line" | grep -oiE 'code[: ]+[A-Z0-9]{3,}(-[A-Z0-9]{2,})+' | grep -oE '[A-Z0-9]{3,}(-[A-Z0-9]{2,})+' | head -1 || true)
                echo; echo "  ${c_ok}➜ Im Browser öffnen: https://www.google.com/device${c_0}"
                if [ -n "$code" ]; then echo; echo "     Code:  ${c_ok}$code${c_0}"; echo
                else echo "$line" | sed 's/^.*OAUTH INTEGRATION: *//; s/^.*\]: //' | fold -s -w 100 | sed 's/^/  /'; fi
                echo "  Dort mit dem Zweitkonto anmelden und den Code eingeben. Ich warte …"; shown=1
            fi
            t=$(echo "$line" | grep -oE '1//[A-Za-z0-9_-]{20,}' | head -1 || true); [ -n "$t" ] && tok=$t
        done < <(journalctl -u ghostnode-lavalink --since "$since" --no-pager -o cat 2>/dev/null)
        [ -n "$tok" ] && break
        sleep 5
    done
    if [ -z "$tok" ]; then
        [ $shown = 1 ] || warn "Kein Code im Protokoll gefunden. Ansehen: journalctl -u ghostnode-lavalink -n 80"
        warn "Kein Token erhalten – nochmal: ghostnode musik-login"; return 1
    fi
    YT_REFRESH_TOKEN=$tok; save_kv "$SECRETS" "${SECRET_VARS[@]}"; chmod 600 "$SECRETS"
    ( umask 077; lavalink_yml "$yt" > "$dir/application.yml" ); chown "$APP_USER:$APP_USER" "$dir/application.yml"; chmod 600 "$dir/application.yml"
    svc restart ghostnode-lavalink
    ok "YouTube-Anmeldung gespeichert (nur in $SECRETS und application.yml, beide nur für root/Dienst lesbar). Musik-Dienst neu gestartet."
}
setup_ytdlp() {   # yt-dlp + ffmpeg: Ausweichweg des Bots, wenn YouTube einen Titel über Lavalink verweigert (nur Streamen, nichts wird gespeichert)
    step "YouTube-Ausweichweg (yt-dlp + ffmpeg)"
    command -v ffmpeg >/dev/null 2>&1 || install_quiet ffmpeg || warn "ffmpeg ließ sich nicht installieren – yt-dlp läuft trotzdem (ohne Umwandlung)."
    command -v python3 >/dev/null 2>&1 || install_quiet python3 || { warn "python3 fehlt – yt-dlp übersprungen."; return 1; }
    local b=/usr/local/bin/yt-dlp
    if [ -x "$b" ]; then
        "$b" -U >/dev/null 2>&1 || true   # offizielles Selbst-Update (GitHub-Release von yt-dlp)
    else
        curl -fsSL -m 120 -o "$b.new" https://github.com/yt-dlp/yt-dlp/releases/latest/download/yt-dlp && chmod 755 "$b.new" && mv -f "$b.new" "$b" || { rm -f "$b.new"; warn "yt-dlp nicht ladbar (GitHub nicht erreichbar?) – übersprungen."; return 1; }
    fi
    local v; v=$("$b" --version 2>/dev/null || true)
    [ -n "$v" ] || { warn "yt-dlp startet nicht (python3 ≥ 3.9 nötig)."; return 1; }
    # Optional: Cookies eines Zweit-Kontos (Netscape-Format) – nur auf dem Server, nie in Git
    if [ -f "$CONF_DIR/yt-cookies.txt" ]; then chmod 600 "$CONF_DIR/yt-cookies.txt"; ok "yt-dlp nutzt die Cookies aus $CONF_DIR/yt-cookies.txt"; fi
    ok "yt-dlp $v$(command -v ffmpeg >/dev/null 2>&1 && echo " · ffmpeg $(ffmpeg -version 2>/dev/null | head -1 | awk '{print $3}')")"
    return 0
}
setup_lavalink() {   # Musik-Dienst für den Bot (Lavalink ≥ 4.2 mit DAVE – Discord verlangt Ende-zu-Ende-Verschlüsselung in Sprachkanälen)
    step "Musik-Dienst (Lavalink)"
    if [ "$RUN_MODE" != systemd ] || [ "$HAS_SYSTEMD" != 1 ]; then warn "Musik braucht systemd (Lavalink als eigener Dienst) – übersprungen."; return 1; fi
    local glibc; glibc=$(ldd --version 2>/dev/null | head -1 | grep -oE '[0-9]+\.[0-9]+$' || echo 0.0)
    if ! python3 -c "import sys; a,b=map(int,'$glibc'.split('.')); sys.exit(0 if (a,b)>=(2,35) else 1)" 2>/dev/null; then
        warn "glibc $glibc < 2.35 – die Discord-Verschlüsselung (DAVE) braucht Ubuntu 22.04 / Debian 12 oder neuer. Musik übersprungen."; return 1
    fi
    if ! command -v java >/dev/null 2>&1 || ! java -version 2>&1 | grep -qE 'version "(1[7-9]|2[0-9])'; then
        install_quiet openjdk-21-jre-headless || install_quiet openjdk-17-jre-headless || { warn "Java 17+ ließ sich nicht installieren – Musik übersprungen."; return 1; }
    fi
    local dir=/opt/ghostnode/lavalink ver yt cur
    install -d -o "$APP_USER" -g "$APP_USER" "$dir" "$dir/plugins" "$dir/logs"
    ver=$(gh_latest lavalink-devs/Lavalink); yt=$(gh_latest lavalink-devs/youtube-source)
    # Ausweichweg ohne GitHub-API (Rate-Limit): Plugin-Version aus dem Maven-Verzeichnis von Lavalink
    [ -n "$yt" ] || yt=$(curl -fsSL -m 20 https://maven.lavalink.dev/releases/dev/lavalink/youtube/youtube-plugin/maven-metadata.xml 2>/dev/null | sed -n 's:.*<release>\(.*\)</release>.*:\1:p' | head -1)
    cur=$(cat "$dir/VERSION" 2>/dev/null || true)
    if [ -z "$ver" ] && [ ! -s "$dir/Lavalink.jar" ]; then   # API nicht erreichbar, noch nichts da → direkt die neueste Datei
        curl -fsSL -m 300 -o "$dir/Lavalink.jar.new" https://github.com/lavalink-devs/Lavalink/releases/latest/download/Lavalink.jar \
            && mv -f "$dir/Lavalink.jar.new" "$dir/Lavalink.jar" && echo "neueste" > "$dir/VERSION" && ok "Lavalink (neueste) geladen" || rm -f "$dir/Lavalink.jar.new"
    fi
    if [ -n "$ver" ] && { [ "$ver" != "$cur" ] || [ ! -s "$dir/Lavalink.jar" ]; }; then
        if curl -fsSL -m 300 -o "$dir/Lavalink.jar.new" "https://github.com/lavalink-devs/Lavalink/releases/download/$ver/Lavalink.jar"; then
            mv -f "$dir/Lavalink.jar.new" "$dir/Lavalink.jar"; echo "$ver" > "$dir/VERSION"; ok "Lavalink $ver geladen"
        else rm -f "$dir/Lavalink.jar.new"; warn "Lavalink $ver nicht ladbar – vorhandene Version bleibt."; fi
    fi
    [ -s "$dir/Lavalink.jar" ] || { warn "Lavalink.jar fehlt (GitHub nicht erreichbar?) – Musik übersprungen."; return 1; }
    case "$(cat "$dir/VERSION" 2>/dev/null)" in 3.*|4.0.*|4.1.*) warn "Lavalink $(cat "$dir/VERSION") kann noch kein DAVE – Musik in Sprachkanälen geht erst ab 4.2.";; esac
    [ -n "$yt" ] || yt=$(sed -n 's/.*youtube-plugin:\([^"]*\)".*/\1/p' "$dir/application.yml" 2>/dev/null | head -1)
    [ -n "$yt" ] || warn "YouTube-Erweiterung: Version nicht ermittelbar – Suche läuft dann über SoundCloud."
    ( umask 077; lavalink_yml "$yt" > "$dir/application.yml" )
    chown -R "$APP_USER:$APP_USER" "$dir"; chmod 600 "$dir/application.yml"
    {
        echo "# erzeugt von ghostnode-root.sh – Musik-Dienst für den GhostNode-Bot (nur 127.0.0.1)"
        echo "[Unit]"; echo "Description=GhostNode Musik (Lavalink)"; echo "After=network-online.target"; echo "Wants=network-online.target"; echo
        echo "[Service]"; echo "User=$APP_USER"; echo "WorkingDirectory=$dir"
        echo "ExecStart=$(command -v java || echo /usr/bin/java) -Xms128m -Xmx${LAVALINK_MEM:-512m} -jar Lavalink.jar"
        echo "Restart=always"; echo "RestartSec=10"; echo "NoNewPrivileges=true"; echo "ProtectSystem=strict"; echo "ProtectHome=true"; echo "ReadWritePaths=$dir"
        echo "PrivateTmp=true"; echo "PrivateDevices=true"; echo "ProtectKernelTunables=true"; echo "ProtectControlGroups=true"; echo "RestrictSUIDSGID=true"; echo "LockPersonality=true"; echo
        echo "[Install]"; echo "WantedBy=multi-user.target"
    } > /etc/systemd/system/ghostnode-lavalink.service
    systemctl daemon-reload
    need_port "$LAVALINK_PORT" java
    svc enable ghostnode-lavalink; svc restart ghostnode-lavalink
    local i c=000
    for i in $(seq 1 45); do c=$(curl --noproxy '*' -s -o /dev/null -m 3 -w '%{http_code}' -H "Authorization: $LAVALINK_PASSWORD" "http://127.0.0.1:$LAVALINK_PORT/version" 2>/dev/null || true); [ "$c" = 200 ] && break; sleep 2; done
    if [ "$c" = 200 ]; then ok "Lavalink $(cat "$dir/VERSION") läuft auf 127.0.0.1:$LAVALINK_PORT${yt:+ (YouTube-Erweiterung $yt)}"
    else warn "Lavalink antwortet noch nicht (lädt beim ersten Start Erweiterungen) – später: journalctl -u ghostnode-lavalink -n 40"; fi
    return 0
}

bot_zip_update() {   # bot_zip_update ORDNER – neueste Release-ZIP von GitHub einspielen (data/, .env und bot-state.json bleiben)
    local dir=$1 api="https://api.github.com/repos/${REPO_BOT_GH:-ghostnode-labs/node_discord}/releases/latest" url tmp
    url=$(curl -fsSL -m 20 -H 'Accept: application/vnd.github+json' "$api" 2>/dev/null | python3 -c 'import sys,json
try:
    a=[x["browser_download_url"] for x in json.load(sys.stdin).get("assets",[]) if x.get("name")=="ghostnode-discord-bot.zip"]
    print(a[0] if a else "")
except Exception: print("")') || true
    [[ "$url" == https://github.com/* ]] || { warn "Release-ZIP des Bots nicht gefunden ($api)."; return 1; }
    tmp=$(mktemp -d)
    if ! curl -fsSL -m 120 -o "$tmp/bot.zip" "$url"; then warn "Download fehlgeschlagen: $url"; rm -rf "$tmp"; return 1; fi
    install -d "$tmp/x"; mig_extract "$tmp/bot.zip" "$tmp/x"
    local src="$tmp/x"; [ -f "$src/bot.js" ] || src=$(dirname "$(find "$tmp/x" -maxdepth 2 -name bot.js | head -1)")
    if [ ! -f "$src/bot.js" ] || [ ! -f "$src/package.json" ]; then warn "ZIP enthält keinen Bot (bot.js fehlt)."; rm -rf "$tmp"; return 1; fi
    # nur Programmdateien ersetzen – Daten und Schlüssel bleiben unangetastet
    find "$src" -maxdepth 1 -type f \( -name '*.js' -o -name '*.html' -o -name '*.json' -o -name '*.md' -o -name '*.service' \) ! -name 'bot-state.json' -exec cp -f {} "$dir/" \;
    chown -R "$APP_USER:$APP_USER" "$dir" 2>/dev/null || true
    rm -rf "$tmp"
    ok "Release-ZIP eingespielt ($(basename "$(dirname "$url")"))"
}

bot_check() {   # nach dem Neustart: läuft der Bot, ist er mit Discord verbunden, antwortet das Dashboard?
    [ "$RUN_MODE" = systemd ] || return 0
    local h="" i
    for i in $(seq 1 20); do h=$(curl --noproxy '*' -sS -m 3 "http://127.0.0.1:$BOT_PORT/health" 2>/dev/null) && [ -n "$h" ] && break; h=""; sleep 1; done
    # Hinweisseite (Bot startet ohne Token/App-ID): Antwort mit „error“ statt Version
    local miss; miss=$(printf '%s' "$h" | python3 -c 'import sys,json; d=json.load(sys.stdin); print(d.get("error","") if not d.get("version") else "")' 2>/dev/null || true)
    if [ -n "$miss" ]; then fail "Bot startet nicht richtig: $miss → $0 secrets"; return 0; fi
    if [ -z "$h" ]; then fail "Bot antwortet nicht auf /health – letzte Zeilen: journalctl -u ghostnode-bot -n 30"; journalctl -u ghostnode-bot -n 8 --no-pager -o cat 2>/dev/null | cut -c1-200 | sed 's/^/    /'; return 0; fi
    local v okk; v=$(printf '%s' "$h" | python3 -c 'import sys,json; d=json.load(sys.stdin); print(d.get("version","?"))' 2>/dev/null || echo "?")
    for i in $(seq 1 15); do okk=$(curl --noproxy '*' -fsS -m 3 "http://127.0.0.1:$BOT_PORT/health" 2>/dev/null | python3 -c 'import sys,json; print(1 if json.load(sys.stdin).get("ok") else 0)' 2>/dev/null || echo 0); [ "$okk" = 1 ] && break; sleep 1; done
    [ "$okk" = 1 ] && ok "Bot $v ist mit Discord verbunden" || warn "Bot $v läuft, ist aber (noch) nicht mit Discord verbunden – Token prüfen ($0 secrets) oder kurz warten."
    local c; c=$(curl --noproxy '*' -s -o /dev/null -m 5 -w '%{http_code}' "http://127.0.0.1:$BOT_PORT/api/me" 2>/dev/null || true)
    [ "${c:-000}" = 401 ] && ok "Dashboard antwortet (Anmeldung nötig, wie erwartet)" || warn "Dashboard /api/me: HTTP ${c:-000}"
    local mu; mu=$(curl --noproxy '*' -s -m 3 "http://127.0.0.1:$BOT_PORT/health" 2>/dev/null | python3 -c 'import sys,json
try:
    m=json.load(sys.stdin).get("music") or {}
    print("an" if m.get("online") else ("verbindet" if m.get("enabled") else "aus"))
except Exception: print("?")' 2>/dev/null || echo "?")
    case "$mu" in
        an) ok "Musik: Bot ist mit Lavalink verbunden" ;;
        verbindet) warn "Musik: Bot verbindet sich noch mit Lavalink (beim ersten Start bis zu 1 Minute)" ;;
        aus) [[ ",$SKIP," == *",music,"* ]] || warn "Musik ist aus (Lavalink nicht eingerichtet)" ;;
    esac
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
[ -n "$GN_ECHO" ] && printf '%s\n' "$out"   # Aufträge aus dem Admin-Panel zeigen die Ausgabe an
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
import json, os, re, sys, time, shutil, subprocess, glob, fcntl, grp, socket
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
AK = '/root/.ssh/authorized_keys'
KEYRE = re.compile(r'^(ssh-ed25519|ssh-rsa|ecdsa-sha2-nistp(256|384|521)|sk-ssh-ed25519@openssh\.com|sk-ecdsa-sha2-nistp256@openssh\.com) [A-Za-z0-9+/=]{40,1000}( [\x20-\x7e]{0,100})?$')
def keys():
    out = []
    try: lines = open(AK).read().splitlines()
    except Exception: return out
    for l in lines:
        l = l.strip()
        if not l or l.startswith('#'): continue
        tmp = S + '/.k'; open(tmp, 'w').write(l + '\n'); rc, o = sh(['ssh-keygen', '-lf', tmp], 10); os.unlink(tmp)
        p = o.split()
        if rc == 0 and len(p) >= 2: out.append({'bits': p[0], 'fp': p[1], 'comment': ' '.join(p[2:-1])[:80], 'type': p[-1].strip('()')})
    return out
def sshd_opt(name):
    rc, o = sh(['sshd', '-T'], 10)
    for l in o.splitlines():
        k, _, v = l.partition(' ')
        if k == name: return v.strip()
    return ''
def ssh_info():
    unit = 'ssh' if os.path.exists('/lib/systemd/system/ssh.service') else 'sshd'
    info = {'active': active(unit) if exists(unit) else False, 'port': sshd_opt('port'), 'password': sshd_opt('passwordauthentication'),
            'root': sshd_opt('permitrootlogin'), 'keys': keys(), 'logins': [], 'failed_24h': None}
    rc, o = sh(['last', '-n', '12', '-i', '-w'], 10)
    info['logins'] = [l for l in o.splitlines() if l.strip() and not l.startswith('wtmp')][:12]
    rc, o = sh(['journalctl', '-u', unit, '--since', '-24h', '--no-pager', '-o', 'cat'], 20)
    if rc == 0: info['failed_24h'] = len([l for l in o.splitlines() if 'Failed password' in l or 'Invalid user' in l])
    return info
def f2b_info():
    rc, o = sh(['fail2ban-client', 'status'], 10)
    if rc: return []
    m = re.search(r'Jail list:\s*(.*)', o); out = []
    for j in [x.strip() for x in (m.group(1) if m else '').split(',') if x.strip()]:
        rc, o2 = sh(['fail2ban-client', 'status', j], 10)
        b = re.search(r'Banned IP list:\s*(.*)', o2); t = re.search(r'Total banned:\s*(\d+)', o2)
        out.append({'jail': j, 'banned': (b.group(1).split() if b else [])[:50], 'total': int(t.group(1)) if t else 0})
    return out
def procs_snapshot(top=40):
    # 9.0: Top-Prozesse nach CPU für die Prozessanzeige der Webseite (Rückfall, wenn PHP /proc nicht lesen darf)
    import pwd
    hz = os.sysconf('SC_CLK_TCK'); page = os.sysconf('SC_PAGE_SIZE')
    def cpu():
        out = []
        for l in open('/proc/stat'):
            if not l.startswith('cpu'): continue
            v = [int(x) for x in l.split()[1:9]]; v += [0] * (8 - len(v))
            out.append((sum(v) - v[3] - v[4], sum(v)))
        return out
    def plist():
        r = {}
        for d in os.listdir('/proc'):
            if not d.isdigit(): continue
            try:
                raw = open('/proc/%s/stat' % d).read(); i = raw.rindex(')'); f = raw[i + 2:].split()
                r[int(d)] = (int(f[11]) + int(f[12]), int(f[19]), int(f[17]), int(f[21]), f[0], raw[raw.index('(') + 1:i])
            except Exception: pass
        return r
    c1, p1 = cpu(), plist(); time.sleep(1); c2, p2 = cpu(), plist()
    cores = [round(max(0.0, min(100.0, (b[0] - a[0]) * 100.0 / max(1, b[1] - a[1]))), 1) for a, b in zip(c1[1:], c2[1:])]
    wall = max(1, (c2[0][1] - c1[0][1]) / max(1, len(cores)))
    up = float(open('/proc/uptime').read().split()[0])
    mt = 1
    for l in open('/proc/meminfo'):
        if l.startswith('MemTotal:'): mt = int(l.split()[1]) * 1024
    rows = []; threads = 0; running = 0
    mask = re.compile(r'(--?(?:pass(?:word)?|pwd|token|secret|api[-_]?key|key|auth)(?:=|\s+))\S+', re.I)
    for pid, (ticks, thr, start, rss, st, comm) in p2.items():
        threads += thr; running += st == 'R'
        old = p1.get(pid)
        pc = (ticks - old[0]) * 100.0 / wall if old and old[2] == start else 0.0
        try: uid = os.stat('/proc/%d' % pid).st_uid; user = pwd.getpwuid(uid).pw_name
        except Exception: user = '?'
        try: cmd = open('/proc/%d/cmdline' % pid, 'rb').read().replace(b'\0', b' ').decode('utf-8', 'replace').strip()
        except Exception: cmd = ''
        rows.append({'pid': pid, 'user': user, 'cpu': round(pc, 1), 'mem': round(rss * page * 100.0 / mt, 1), 'rss': rss * page,
                     'time': max(0, int(up - start / hz)), 'state': st, 'threads': thr, 'cmd': mask.sub(r'\1•••', cmd or '[%s]' % comm)[:200]})
    rows.sort(key=lambda r: (r['cpu'], r['rss']), reverse=True)
    return {'time': int(time.time()), 'cores': cores, 'tasks': len(rows), 'threads': threads, 'running': running, 'list': rows[:top]}
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
    try: out['ssh'] = ssh_info()
    except Exception as e: out['ssh'] = {'error': str(e)[:200]}
    try: out['f2b'] = f2b_info()
    except Exception: out['f2b'] = []
    rc, o = sh(['ufw', 'status'], 10)
    out['ufw'] = o.strip().splitlines()[:40] if rc == 0 else []
    try: out['procs'] = procs_snapshot()
    except Exception as e: out['procs'] = {'error': str(e)[:200]}
    tmp = S + '/status.json.tmp'
    with open(tmp, 'w') as f: json.dump(out, f)
    os.chmod(tmp, 0o640); os.chown(tmp, 0, grp.getgrnam(APP_GROUP).gr_gid); os.replace(tmp, S + '/status.json')
IPRE = re.compile(r'^([0-9]{1,3}\.){3}[0-9]{1,3}$|^[0-9a-fA-F:]{2,39}$')
def special_action(act, arg):
    # nur diese Aufträge mit Argument – jedes Argument wird streng geprüft, nie an eine Shell gegeben
    if act == 'ssh_key_add':
        if not KEYRE.match(arg.strip()): return (['sh', '-c', 'echo "Ungültiger SSH-Schlüssel (erwartet: ssh-ed25519 AAAA… Name)"; exit 1'], 10)
        return ([sys.executable, __file__, '--key-add', arg.strip()], 30)
    if act == 'ssh_key_del':
        if not re.fullmatch(r'SHA256:[A-Za-z0-9+/]{43}', arg): return (['sh', '-c', 'echo "Ungültiger Fingerabdruck"; exit 1'], 10)
        return ([sys.executable, __file__, '--key-del', arg], 30)
    if act == 'ssh_password':
        if arg not in ('on', 'off'): return (['sh', '-c', 'exit 1'], 5)
        return ([sys.executable, __file__, '--ssh-password', arg], 60)
    if act == 'f2b_unban':
        if not IPRE.match(arg): return (['sh', '-c', 'echo "Ungültige IP"; exit 1'], 5)
        return (['fail2ban-client', 'unban', arg], 30)
    if act == 'logs':
        if arg not in SERVICES: return (['sh', '-c', 'echo "Unbekannter Dienst"; exit 1'], 5)
        if arg == 'apache2': return (['tail', '-n', '80', '/var/log/apache2/ghostnode-error.log'], 20)
        return (['journalctl', '-u', unit(arg), '-n', '80', '--no-pager', '-o', 'short-iso'], 30)
    if act == 'apt_upgrade': return (['sh', '-c', 'export DEBIAN_FRONTEND=noninteractive; apt-get update -q && apt-get -y -q -o Dpkg::Options::=--force-confold upgrade'], 1800)
    if act == 'reboot': return (['shutdown', '-r', '+1', 'GhostNode: Neustart aus dem Admin-Panel'], 30)
    return None
def key_add(line):
    os.makedirs('/root/.ssh', mode=0o700, exist_ok=True)
    have = open(AK).read() if os.path.exists(AK) else ''
    if line.split()[1] in have: print('Schlüssel ist schon eingetragen.'); return 0
    with open(AK, 'a') as f: f.write(('' if not have or have.endswith('\n') else '\n') + line + '\n')
    os.chmod(AK, 0o600); print('✔ SSH-Schlüssel hinzugefügt.'); return 0
def key_del(fp):
    if not os.path.exists(AK): print('Keine Schlüssel.'); return 1
    keep, gone = [], 0
    for l in open(AK).read().splitlines():
        tmp = S + '/.k'; open(tmp, 'w').write(l + '\n'); rc, o = sh(['ssh-keygen', '-lf', tmp], 10); os.unlink(tmp)
        if rc == 0 and fp in o.split(): gone += 1; continue
        keep.append(l)
    if not gone: print('Schlüssel nicht gefunden.'); return 1
    if not [l for l in keep if KEYRE.match(l.strip())] and sshd_opt('passwordauthentication') == 'no':
        print('Abgelehnt: das ist der letzte Schlüssel und Passwort-Login ist aus – du würdest dich aussperren.'); return 1
    open(AK, 'w').write('\n'.join(keep) + ('\n' if keep else '')); print('✔ SSH-Schlüssel entfernt.'); return 0
def ssh_password(mode):
    if mode == 'off' and not [k for k in keys()]:
        print('Abgelehnt: erst einen SSH-Schlüssel hinterlegen – sonst sperrst du dich aus.'); return 1
    d = '/etc/ssh/sshd_config.d'; os.makedirs(d, exist_ok=True); f = d + '/10-ghostnode.conf'
    open(f, 'w').write('# erzeugt von ghostnode-agent (Admin → Server)\nPasswordAuthentication %s\nKbdInteractiveAuthentication no\n' % ('yes' if mode == 'on' else 'no'))
    rc, o = sh(['sshd', '-t'], 10)
    if rc: os.unlink(f); print('sshd-Konfiguration fehlerhaft – zurückgenommen: ' + o[-300:]); return 1
    unitn = 'ssh' if os.path.exists('/lib/systemd/system/ssh.service') else 'sshd'
    sh(['systemctl', 'reload', unitn], 20)
    print('✔ Passwort-Login per SSH ist jetzt ' + ('an' if mode == 'on' else 'aus (nur noch mit Schlüssel)') + '.'); return 0
def requests():
    lock = open('/run/ghostnode-agent.lock', 'w')
    try: fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
    except OSError: return   # ein Auftrag läuft noch
    gid = grp.getgrnam(APP_GROUP).gr_gid
    for f in sorted(glob.glob(REQ + '/*.json'), key=os.path.getmtime):
        try: r = json.load(open(f))
        except Exception: os.unlink(f); continue
        os.unlink(f)
        rid, act, by, arg = str(r.get('id', '')), str(r.get('action', '')), str(r.get('by', ''))[:60], str(r.get('arg', ''))[:1200]
        if not re.fullmatch(r'[0-9a-f]{16}', rid) or time.time() - float(r.get('ts', 0)) > 900: continue
        special = special_action(act, arg)
        if special is not None: cmd, t = special
        elif act.startswith('restart:') and act[8:] in SERVICES: cmd, t = ['systemctl', 'restart', unit(act[8:])] if os.path.isdir('/run/systemd/system') else ['service', unit(act[8:]), 'restart'], 120
        elif act in ACTIONS: cmd, t = ACTIONS[act]
        else: continue
        res = RES + '/' + rid + '.json'
        def write(d):
            with open(res + '.tmp', 'w') as fh: json.dump(d, fh)
            os.chmod(res + '.tmp', 0o640); os.chown(res + '.tmp', 0, gid); os.replace(res + '.tmp', res)
        d = {'id': rid, 'action': act, 'arg': ' '.join(arg.split()[2:])[:100] if act == 'ssh_key_add' else arg[:100], 'by': by, 'started': int(time.time()), 'state': 'läuft'}; write(d)
        rc, o = sh(['env', 'GN_ECHO=1', '/usr/local/sbin/ghostnode-run', 'web-' + act.replace(':', '-')] + cmd, t)
        o = re.sub(r'\x1b\[[0-9;]*m', '', o)
        d.update({'finished': int(time.time()), 'rc': rc, 'state': 'ok' if rc == 0 else 'Fehler', 'out': '\n'.join(o.splitlines()[-60:])}); write(d)
    for f in glob.glob(RES + '/*.json'):
        if time.time() - os.path.getmtime(f) > 7 * 86400: os.unlink(f)
if __name__ == '__main__':
    if len(sys.argv) == 3 and sys.argv[1] == '--key-add': sys.exit(key_add(sys.argv[2]))
    if len(sys.argv) == 3 and sys.argv[1] == '--key-del': sys.exit(key_del(sys.argv[2]))
    if len(sys.argv) == 3 and sys.argv[1] == '--ssh-password': sys.exit(ssh_password(sys.argv[2]))
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
# ---------------------------------------------------------------- eigene Skripte (server/scripts → Server)
SCRIPTS_DST=${GN_SCRIPTS_DST:-/opt/ghostnode/scripts}; SCRIPTS_BIN=${GN_SCRIPTS_BIN:-/usr/local/bin}; SCRIPTS_CRON=${GN_SCRIPTS_CRON:-/etc/cron.d/ghostnode-scripts}; SCRIPTS_STATE=${GN_SCRIPTS_STATE:-/var/lib/ghostnode}
scripts_src() { echo "$(cd "$(dirname "$(readlink -f "$0")")" && pwd)/scripts"; }
script_meta() { sed -n "s/^#[[:space:]]*gn-$2:[[:space:]]*//p" "$1" | head -1; }   # script_meta DATEI schlüssel → Wert aus „# gn-schlüssel: …“
scripts_sync() {   # Skripte aus dem Git-Ordner server/scripts installieren, Befehle gn-NAME, Cron und Einmal-Ausführung
    local src; src=$(scripts_src)
    step "Eigene Skripte (server/scripts)"
    [ -d "$src" ] || { ok "kein Ordner $src – nichts zu tun"; return 0; }
    install -d -m 0755 "$SCRIPTS_DST" "$SCRIPTS_STATE"
    local f name n=0 bad=0 cron="" state=$SCRIPTS_STATE/scripts.done keep=" "
    touch "$state"; chmod 600 "$state"
    for f in "$src"/*; do
        [ -f "$f" ] || continue
        name=$(basename "$f")
        case "$name" in _*|*.md|*.txt|README*) continue ;; esac          # _name = ausgeschaltet, Doku wird übersprungen
        [[ "$name" =~ ^[A-Za-z0-9][A-Za-z0-9._-]*$ ]] || { warn "übersprungen (Name): $name"; bad=$((bad+1)); continue; }
        [ -L "$f" ] && { warn "übersprungen (symbolischer Link): $name"; bad=$((bad+1)); continue; }
        case "$name" in
            *.sh) bash -n "$f" 2>/dev/null || { warn "Syntaxfehler – nicht installiert: $name (bash -n $f)"; bad=$((bad+1)); continue; } ;;
            *.py) python3 -m py_compile "$f" 2>/dev/null || { warn "Syntaxfehler – nicht installiert: $name"; bad=$((bad+1)); continue; } ;;
        esac
        head -1 "$f" | grep -q '^#!' || { warn "übersprungen (erste Zeile braucht #!/bin/bash o. ä.): $name"; bad=$((bad+1)); continue; }
        install -m 0755 "$f" "$SCRIPTS_DST/$name"
        local cmd="gn-${name%.*}"
        ln -sfn "$SCRIPTS_DST/$name" "$SCRIPTS_BIN/$cmd"
        keep+="$name "
        local c r h; c=$(script_meta "$f" cron); r=$(script_meta "$f" run)
        if [ -n "$c" ]; then
            if [[ "$c" =~ ^(@(hourly|daily|weekly|monthly|reboot)|[0-9*/,-]+[[:space:]]+[0-9*/,-]+[[:space:]]+[0-9*/,-]+[[:space:]]+[0-9*/,-]+[[:space:]]+[0-9*/,-]+)$ ]]; then
                cron+="$c root $SCRIPTS_DST/$name >> /var/log/ghostnode-scripts.log 2>&1"$'\n'
            else warn "$name: ungültige Zeitangabe „gn-cron: $c“ – kein Cron"; fi
        fi
        h=$(sha256sum "$f" | cut -c1-16)
        if [ "$r" = always ] || { [ "$r" = once ] && ! grep -qx "$name $h" "$state"; }; then
            if "$SCRIPTS_DST/$name" >> "${GN_SCRIPTS_LOG:-/var/log/ghostnode-scripts.log}" 2>&1; then ok "$name ausgeführt ($r)"; else warn "$name ($r) endete mit Fehler – /var/log/ghostnode-scripts.log"; fi
            [ "$r" = once ] && { grep -v "^$name " "$state" > "$state.tmp" || true; echo "$name $h" >> "$state.tmp"; mv "$state.tmp" "$state"; }
        fi
        n=$((n+1)); ok "$cmd${c:+  · Cron: $c}${r:+  · Ausführen: $r}"
    done
    # entfernte Skripte aufräumen (nur, was wir selbst installiert haben)
    for f in "$SCRIPTS_DST"/*; do
        [ -f "$f" ] || continue; name=$(basename "$f")
        [[ "$keep" == *" $name "* ]] && continue
        rm -f "$f" "$SCRIPTS_BIN/gn-${name%.*}"; ok "entfernt: $name"
    done
    if [ -n "$cron" ]; then { echo "# erzeugt von ghostnode-root.sh (ghostnode scriptload) – Quelle: server/scripts im Git"; echo "SHELL=/bin/bash"; echo "PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin"; printf '%s' "$cron"; } > "$SCRIPTS_CRON"; chmod 644 "$SCRIPTS_CRON"
    else rm -f "$SCRIPTS_CRON"; fi
    ok "$n Skript(e) installiert$([ $bad -gt 0 ] && echo ", $bad übersprungen")"
}
cmd_scripts() {   # Liste der installierten eigenen Skripte
    local f
    [ -d "$SCRIPTS_DST" ] && ls "$SCRIPTS_DST" >/dev/null 2>&1 || { echo "Noch keine eigenen Skripte (Ordner server/scripts, dann: ghostnode scriptload)."; return 0; }
    for f in "$SCRIPTS_DST"/*; do [ -f "$f" ] || continue
        printf '  %-22s %s%s%s\n' "gn-$(basename "${f%.*}")" "$(script_meta "$f" desc)" "$(c=$(script_meta "$f" cron); [ -n "$c" ] && echo "  [Cron: $c]")" "$(r=$(script_meta "$f" run); [ -n "$r" ] && echo "  [$r]")"
    done
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
    install_quiet python3-systemd || true   # fail2ban liest SSH-Anmeldungen aus dem Journal (Debian 12 / Ubuntu 24 haben kein auth.log mehr)
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
    # Jails nur mit vorhandener Protokollquelle – sonst startet fail2ban gar nicht („aus“ im Dashboard)
    local lf=/etc/fail2ban/jail.d/ghostnode.conf
    {
        echo "# erzeugt von ghostnode-root.sh"
        echo "[sshd]"; echo "enabled = true"
        if [ -f /var/log/auth.log ]; then echo "backend = auto"; else echo "backend = systemd"; fi
        local jail logf
        for jail in apache-auth:/var/log/apache2/error.log nginx-http-auth:/var/log/nginx/error.log vsftpd:/var/log/vsftpd.log; do
            logf=${jail#*:}; jail=${jail%%:*}
            echo "[$jail]"
            case "$jail" in apache-auth) want web || logf="";; nginx-http-auth) [ "$FRONT" = nginx ] || logf="";; vsftpd) want ftp || logf="";; esac
            if [ -n "$logf" ] && [ -f "$logf" ]; then echo "enabled = true"; else echo "enabled = false"; fi
        done
    } > "$lf"
    svc enable fail2ban; svc restart fail2ban
    sleep 2
    if is_active fail2ban; then ok "fail2ban läuft ($(fail2ban-client status 2>/dev/null | sed -n 's/.*Jail list:[[:space:]]*//p'))"
    else warn "fail2ban startet nicht – Ursache: fail2ban-client -t ; journalctl -u fail2ban -n 30"; fi
    dpkg-reconfigure -f noninteractive unattended-upgrades >/dev/null 2>&1 || true
    if ufw status 2>/dev/null | grep -q '^Status: active'; then ok "ufw aktiv (SSH $ssh_port, 80, 443$(want turn && echo ', TURN')$(want voice && [ "$PTERO" = 0 ] && echo ', Mumble')$(want ftp && echo ', FTPS')$([ "$PTERO" = 1 ] && echo ', Wings'))"
    else warn "ufw ist aus – Regeln sind vorbereitet, einschalten mit: ufw enable"; fi
    ok "fail2ban, automatische Sicherheitsupdates"
}

setup_tls() {
    step "Zertifikate (Let's Encrypt)"
    [ "$NO_TLS" = 1 ] && { warn "--no-tls: keine Zertifikate. Später: $0 certs"; return; }
    [ -n "${LE_EMAIL:-}" ] || { warn "Keine E-Mail für Let's Encrypt angegeben – später: $0 certs"; return; }
    if [ "$FRONT" = nginx ]; then apt-get install -y -qq certbot >/dev/null; else apt-get install -y -qq certbot python3-certbot-apache >/dev/null; fi
    if [ "$FRONT" = nginx ] && want web && [ ! -f /etc/nginx/sites-available/ghostnode.conf ]; then
        warn "Die Webseite ist in nginx noch nicht eingerichtet – Zertifikate erst danach (sonst landen alle Namen in einem falschen Block). Zuerst: $0 install"; return 0
    fi
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
    if [ "$FRONT" = nginx ]; then
        # nur das Zertifikat holen (Webroot) – die HTTPS-Blöcke schreibt das Script selbst, für jeden Namen
        install -d -m 0755 /var/www/letsencrypt
        local out; out=$(certbot certonly --webroot -w /var/www/letsencrypt --non-interactive --agree-tos -m "$LE_EMAIL" --expand --keep-until-expiring --cert-name ghostnode "${names[@]}" 2>&1) \
            || { echo "$out" | tail -8 | sed 's/^/    /'; die "Zertifikat konnte nicht geholt werden – DNS aller Namen auf diesen Server? Port 80 offen?"; }
        nginx_refresh
    else
        certbot --apache --non-interactive --agree-tos -m "$LE_EMAIL" --redirect --expand --cert-name ghostnode "${names[@]}" >/dev/null
    fi
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
    if [ -d /etc/letsencrypt/live/ghostnode ]; then curl --noproxy "*" -s -k --max-time 10 --resolve "$host:443:127.0.0.1" "$@" "https://$host$path"
    else curl --noproxy "*" -s --max-time 10 -H "Host: $host" "$@" "http://127.0.0.1$path"; fi; }
http_code() { local c; c=$(lcurl "$1" "$2" -o /dev/null -w '%{http_code}' 2>/dev/null) || true; echo "${c:-000}"; }
check_all() {
    step "Prüfung"
    FAILS=0
    if [ -d /etc/letsencrypt/live/ghostnode ]; then   # HTTPS: leitet ein Name auf sich selbst um? (Schleife)
        local h u r
        for h in $(want web && echo "$DOMAIN") "$HR_HOST" "$BOT_HOST"; do
            [ -n "$h" ] || continue
            r=$(curl --noproxy '*' -sk -m 8 -o /dev/null -w '%{http_code} %{redirect_url}' --resolve "$h:443:127.0.0.1" "https://$h/" 2>/dev/null) || true
            u=${r#* }; r=${r:-000}
            if [[ "$r" =~ ^30 ]] && [ "${u%/}" = "https://$h" ]; then fail "https://$h leitet auf sich selbst um (Schleife) – Reparatur: $0 install --yes && $0 certs"
            elif [[ "$r" =~ ^000 ]]; then fail "https://$h antwortet nicht (kein HTTPS-Block für diesen Namen?) – Reparatur: $0 install --yes && $0 certs"
            else ok "https://$h → ${r%% *}"; fi
        done
    fi
    if want web && [ "$RUN_MODE" = systemd ] && [ -f /opt/ghostnode/live/live.js ]; then
        local lc; lc=$(curl -s -m 8 "http://127.0.0.1:$LIVE_PORT/live/health" 2>/dev/null) || true
        [[ "$lc" == *'"ghostnode-live"'* ]] && ok "Live-Dienst antwortet (Fernsteuerung in Echtzeit)" || fail "Live-Dienst antwortet nicht (journalctl -u ghostnode-live)"
        if [ -s /etc/letsencrypt/live/ghostnode/fullchain.pem ]; then lc=$(curl --noproxy '*' -sk -m 8 --resolve "$DOMAIN:443:127.0.0.1" "https://$DOMAIN/live/health" 2>/dev/null) || true
        else lc=$(curl --noproxy '*' -s -m 8 -H "Host: $DOMAIN" "http://127.0.0.1/live/health" 2>/dev/null) || true; fi
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
        c=$(lcurl "$DOMAIN" "/api.php?r=me" -H "Authorization: Bearer gnk_$(printf "%040d" 0)" 2>/dev/null || true)
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
            [[ "$c" =~ ^(200|301|308)$ ]] && ok "Webseite über nginx erreichbar ($c$([[ "$c" =~ ^30 ]] && echo ' → HTTPS'))" || fail "Webseite über nginx → $c"
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
    if [ -f "$cfg" ] && ! grep -q '"driver": *"mysql"' "$cfg"; then cp -a "$cfg" "$cfg.sqlite-$ts"; fi
    write_db_cfg "${pre:-gn_}"; ok "Webseite nutzt MariaDB („$DB_NAME“, Präfix „${pre:-gn_}“)"
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
        install_cli
        check_all || true
        summary ;;
    update)
        [ -f "$CONF" ] || die "Noch nicht eingerichtet – zuerst: $0 install"
        self_update update
        if want web; then step "Webseite"; git_as -C "$WEB_DIR" pull --ff-only || die "git pull der Webseite fehlgeschlagen."; ok "aktuell: $(git_as -C "$WEB_DIR" log -1 --format='%h %s')"; svc reload apache2 || true; fi
        want web && [ "$RUN_MODE" = systemd ] && setup_live
        want hr  && setup_hr
        want bot && setup_bot
        want cron && setup_cron   # Wächter/Backup/Auto-Update mit dem neuesten Stand
        want fw   && setup_firewall   # nur hinzufügen, nie zurücksetzen; fail2ban mit passender Protokollquelle
        scripts_sync || true          # eigene Skripte aus server/scripts
        install_cli
        check_all ;;
    bot)   # nur der Discord-Bot: Code holen (Git oder Release-ZIP), Einstellungen schreiben, neu starten, prüfen
        [ -f "$CONF" ] || die "Noch nicht eingerichtet – zuerst: $0 install"
        self_update bot
        want bot || die "Der Bot ist auf diesem Server abgeschaltet (--skip=bot / Rolle)."
        setup_bot
        [ "${FAILS:-0}" -eq 0 ] && ok "Fertig. Dashboard: https://$BOT_HOST" || exit 1 ;;
    certs)
        [ -f "$CONF" ] || die "Noch nicht eingerichtet – zuerst: $0 install"
        [ -n "${LE_EMAIL:-}" ] || { YES=0; ask LE_EMAIL "E-Mail für Let's Encrypt" ""; save_kv "$CONF" "${CONF_VARS[@]}"; }
        NO_TLS=0; setup_tls; check_all || true ;;
    bungie)
        # nur die drei Bungie-Werte für D2 Nexus (Anmeldung, Chat, Webseite) – Eingabe unsichtbar
        [ -f "$CONF" ] || die "Noch nicht eingerichtet – zuerst: $0 install"
        [ -t 0 ] || die "Bitte direkt im Terminal ausführen (Eingabe nötig)."
        echo "Bungie-App für D2 Nexus (bungie.net/en/Application) – Enter = Wert behalten"
        for v in GN_BUNGIE_KEY GN_BUNGIE_CLIENT_ID GN_BUNGIE_CLIENT_SECRET; do
            case $v in GN_BUNGIE_KEY) lbl="API-Key";; GN_BUNGIE_CLIENT_ID) lbl="OAuth client_id";; *) lbl="OAuth client_secret (nur bei „Confidential“, sonst Enter)";; esac
            [ -n "${!v:-}" ] && st="gesetzt" || st="leer"
            if [ "$v" = GN_BUNGIE_CLIENT_ID ]; then read -r -p "  $lbl ($st): " nv; else read -r -s -p "  $lbl ($st, unsichtbar): " nv; echo; fi
            nv=$(printf '%s' "$nv" | tr -d '[:space:]')
            [ -n "$nv" ] && printf -v "$v" '%s' "$nv"
        done
        [[ "${GN_BUNGIE_KEY:-}" =~ ^[A-Fa-f0-9]{32}$ ]] || warn "Der API-Key hat normalerweise 32 Zeichen (0-9, a-f) – bitte prüfen."
        [[ "${GN_BUNGIE_CLIENT_ID:-}" =~ ^[0-9]+$ ]] || warn "Die client_id ist eine Zahl – bitte prüfen."
        if [ -z "${GN_BUNGIE_CLIENT_SECRET:-}" ]; then warn "Kein client_secret: geht mit einer Bungie-App vom Typ „Public“ – dann aber stündlich neu anmelden. Besser: Typ „Confidential“, dann erscheint das Secret."
        elif [ "${#GN_BUNGIE_CLIENT_SECRET}" -lt 16 ]; then warn "Das client_secret ist zu kurz – bitte prüfen."; fi
        save_kv "$SECRETS" "${SECRET_VARS[@]}"; chmod 600 "$SECRETS"
        want web && setup_web
        c=$(curl --noproxy '*' -sk --resolve "$DOMAIN:443:127.0.0.1" -o /dev/null -w '%{http_code}' -X POST -H 'X-D2-Client: 0' -d '{}' "https://$DOMAIN/d2auth.php" || true)
        case "${c:-000}" in 403) ok "D2-Nexus-Anmeldung ist eingerichtet – in der App jetzt „Mit Bungie anmelden“.";; 503) warn "Es fehlt noch ein Wert – $0 bungie erneut ausführen.";; *) warn "Prüfung ergab HTTP ${c:-000} – Webseite erreichbar?";; esac ;;
    secrets)
        [ -f "$CONF" ] || die "Noch nicht eingerichtet – zuerst: $0 install"
        for v in HYPERATE_API_KEY DISCORD_TOKEN DISCORD_APP_ID DISCORD_GUILD_ID RELEASE_CHANNEL_ID DISCORD_CLIENT_SECRET GN_DISCORD_CLIENT_ID GN_DISCORD_CLIENT_SECRET STATUS_WEBHOOK GN_BUNGIE_KEY GN_BUNGIE_CLIENT_ID GN_BUNGIE_CLIENT_SECRET SMTP_HOST SMTP_PORT SMTP_USER SMTP_PASS SMTP_FROM; do
            [ -n "${!v:-}" ] && echo "  $v: gesetzt (Enter = behalten, - = löschen)" || echo "  $v: leer"
            if [ -t 0 ]; then read -r -s -p "  neuer Wert für $v: " nv; echo; [ "$nv" = "-" ] && printf -v "$v" '%s' "" || { [ -n "$nv" ] && printf -v "$v" '%s' "$nv"; }; fi
        done
        save_kv "$SECRETS" "${SECRET_VARS[@]}"
        want mail && setup_mail; want web && setup_web; want hr && setup_hr; want bot && setup_bot
        check_all || true ;;
    check)  [ -f "$CONF" ] || die "Noch nicht eingerichtet – zuerst: $0 install"; check_all || exit 1 ;;
    migrate) cmd_migrate ;;
    crons) cmd_crons ;;
    bericht|report) [ -f "$CONF" ] || die "Noch nicht eingerichtet – zuerst: $0 install"; cmd_bericht ;;
    usedb) [ -f "$CONF" ] || die "Noch nicht eingerichtet – zuerst: $0 install"; cmd_usedb ;;
    login)   # Notfall-Zugang zur Webseite über SSH (Sperre aufheben, Passwort, Zwei-Faktor)
        [ -f "$CONF" ] || die "Noch nicht eingerichtet – zuerst: $0 install"
        want web || die "Die Webseite läuft nicht auf diesem Server (Rolle „dienste“)."
        [ -f "$WEB_DIR/tools/login.php" ] || { git_as -C "$WEB_DIR" pull --ff-only -q || true; }
        [ -f "$WEB_DIR/tools/login.php" ] || die "tools/login.php fehlt – zuerst: $0 update"
        runuser -u "$APP_USER" -- php "$WEB_DIR/tools/login.php" "${ARGS[@]}" || exit $? ;;
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
    scriptload) self_update scriptload; scripts_sync ;;
    scripts) cmd_scripts ;;
    script) n=${ARGS[0]:-}; [ -n "$n" ] || die "Aufruf: ghostnode script NAME [Argumente]"; [ -x "$SCRIPTS_DST/$n" ] || n=$(cd "$SCRIPTS_DST" 2>/dev/null && ls | grep -m1 "^${n}\\." || true)
        [ -n "$n" ] && [ -x "$SCRIPTS_DST/$n" ] || die "Skript nicht gefunden: ${ARGS[0]} (Liste: ghostnode scripts)"; exec "$SCRIPTS_DST/$n" "${ARGS[@]:1}" ;;
    musik-login) [ -f "$CONF" ] || die "Noch nicht eingerichtet – zuerst: $0 install"; cmd_musik_login ;;
    cli) install_cli; ok "Befehl angelegt: ghostnode → $(readlink -f "$0")"; echo "  Tab-Vervollständigung gilt ab der nächsten Anmeldung (oder sofort: source /etc/bash_completion.d/ghostnode)." ;;
    *) die "Unbekannter Befehl: $CMD – Hilfe: ghostnode help" ;;
esac
