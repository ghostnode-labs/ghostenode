#!/usr/bin/env bash
# =====================================================================================================
#  GhostNode Root-Server – Einrichtung, Updates und Prüfung (Debian 12 / Ubuntu 22.04 / 24.04)
#
#  Richtet alles ein, was GhostNode und VRC Nexus auf einem eigenen Server brauchen:
#    web   Webseite (Apache + PHP-FPM, eigener Benutzer „ghostnode“, Git-Checkout → Updates per git pull)
#    db    MariaDB (oder SQLite), Datenbank + Benutzer mit Zufallspasswort
#    tls   Let's-Encrypt-Zertifikate für alle Namen, automatische Verlängerung
#    hr    HypeRate-Weiterleitung (Node.js, hr.<domain>)
#    bot   GhostNode-Discord-Bot mit Web-Dashboard (Node.js, discord.<domain>)
#    turn  TURN/STUN-Server (coturn) für Sprach-/Videoanrufe im Nexus-Chat (turn.<domain>)
#    ftp   FTPS-Eingang für VRChat-Poster (nur der Poster-Eingangsordner, kein Zugriff auf die Seite)
#    mail  Mailversand über einen SMTP-Anbieter (Status-Meldungen per E-Mail)
#    cron  Aufgabenplaner der Webseite alle 5 Minuten + tägliches Server-Backup
#    fw    Firewall (ufw), fail2ban, automatische Sicherheitsupdates
#
#  Aufruf (als root):
#    bash ghostnode-root.sh install            alles einrichten (fragt nach Domain, E-Mail und Schlüsseln)
#    bash ghostnode-root.sh update             Webseite, Weiterleitung und Bot aktualisieren, Dienste neu starten
#    bash ghostnode-root.sh certs              Zertifikate (erneut) holen – z. B. wenn DNS beim install noch nicht stand
#    bash ghostnode-root.sh secrets            Schlüssel (Discord, HypeRate, SMTP …) ändern und übernehmen
#    bash ghostnode-root.sh check              alles prüfen (Dienste, Seiten, PHP-Erweiterungen, Zertifikate)
#    bash ghostnode-root.sh backup             Server-Backup jetzt (Datenbank, data/, Bot-Daten, Einstellungen)
#  Optionen:
#    --yes            keine Rückfragen (Werte aus /etc/ghostnode/setup.conf bzw. Umgebung)
#    --no-tls         keine Zertifikate holen (Test / DNS noch nicht umgestellt)
#    --only=a,b       nur diese Teile (web,db,tls,hr,bot,turn,ftp,mail,cron,fw)
#    --skip=a,b       diese Teile auslassen
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
HR_PORT=8091
BOT_PORT=8092
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
YES=0; NO_TLS=0; ONLY=""; SKIP=""
for a in "$@"; do
    case "$a" in
        --yes|-y) YES=1 ;;
        --no-tls) NO_TLS=1 ;;
        --only=*) ONLY=${a#--only=} ;;
        --skip=*) SKIP=${a#--skip=} ;;
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
             REDDIT_CLIENT_ID REDDIT_CLIENT_SECRET TWITCH_EXT_CLIENT_ID TWITCH_EXT_SECRET DB_PASS TURN_SECRET FTP_PASS)
CONF_VARS=(DOMAIN LE_EMAIL WEB_DIR DB_ENGINE DB_NAME DB_USER HR_HOST BOT_HOST TURN_HOST FTP_USER TZ_NAME)

ask_config() {
    step "Einstellungen"
    ask DOMAIN    "Haupt-Domain der Webseite" "ghostnode-labs.dev"
    ask LE_EMAIL  "E-Mail für Let's Encrypt (Ablauf-Warnungen)" ""
    ask WEB_DIR   "Verzeichnis der Webseite" "/var/www/ghostnode"
    ask DB_ENGINE "Datenbank: mariadb oder sqlite" "mariadb"
    ask HR_HOST   "Adresse der HypeRate-Weiterleitung" "hr.$DOMAIN"
    ask BOT_HOST  "Adresse des Discord-Bot-Dashboards" "discord.$DOMAIN"
    ask TURN_HOST "Adresse des TURN-Servers (Anrufe)" "turn.$DOMAIN"
    ask TZ_NAME   "Zeitzone" "Europe/Berlin"
    DB_NAME=${DB_NAME:-ghostnode}; DB_USER=${DB_USER:-ghostnode}; FTP_USER=${FTP_USER:-poster}
    case "$DB_ENGINE" in mariadb|sqlite) ;; *) die "Datenbank muss mariadb oder sqlite sein." ;; esac
    [[ "$DOMAIN" =~ ^[a-z0-9.-]+\.[a-z]{2,}$ ]] || die "Domain ungültig: $DOMAIN"
    save_kv "$CONF" "${CONF_VARS[@]}"; chmod 640 "$CONF"
    ok "gespeichert in $CONF"
}
ask_secrets() {
    step "Schlüssel (leer lassen = Funktion bleibt aus, später mit „$0 secrets“ nachtragen)"
    want hr  && ask HYPERATE_API_KEY     "HypeRate-API-Key" "" s
    if want bot; then
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
    DB_PASS=${DB_PASS:-$(rand 32)}; TURN_SECRET=${TURN_SECRET:-$(rand 48)}; FTP_PASS=${FTP_PASS:-$(rand 20)}
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

# ---------------------------------------------------------------- Teile
base_packages() {
    step "System & Pakete"
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
    step "Webseite (Apache + PHP-FPM)"
    apt-get install -y -qq apache2 php-fpm php-cli php-sqlite3 php-mysql php-curl php-mbstring php-zip php-gd php-xml php-intl >/dev/null
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
        for v in GN_DISCORD_CLIENT_ID GN_DISCORD_CLIENT_SECRET STATUS_WEBHOOK REDDIT_CLIENT_ID REDDIT_CLIENT_SECRET TWITCH_EXT_CLIENT_ID TWITCH_EXT_SECRET; do
            [ -n "${!v:-}" ] && printf 'env[%s] = "%s"\n' "$v" "${!v//\"/}"
        done
        echo 'env[PATH] = /usr/local/bin:/usr/bin:/bin'
    } > "$POOL"
    chmod 640 "$POOL"
    [ -f "/etc/php/$PV/fpm/pool.d/www.conf" ] && mv "/etc/php/$PV/fpm/pool.d/www.conf" "/etc/php/$PV/fpm/pool.d/www.conf.aus"
    # gleiche Variablen für Cron (Kommandozeile)
    save_kv "$CONF_DIR/web.env" GN_DISCORD_CLIENT_ID GN_DISCORD_CLIENT_SECRET STATUS_WEBHOOK REDDIT_CLIENT_ID REDDIT_CLIENT_SECRET TWITCH_EXT_CLIENT_ID TWITCH_EXT_SECRET
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

    cat > /etc/apache2/sites-available/ghostnode.conf <<EOF
# erzeugt von ghostnode-root.sh
<VirtualHost *:80>
    ServerName $DOMAIN
    ServerAlias www.$DOMAIN
    DocumentRoot $WEB_DIR
    DirectoryIndex index.php index.html
    Protocols h2 http/1.1
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
    svc enable apache2; svc reload apache2 2>/dev/null || svc restart apache2
    ok "Apache: $DOMAIN → $WEB_DIR"
}

setup_db() {
    step "Datenbank ($DB_ENGINE)"
    local cfg="$WEB_DIR/data/config.php"
    if [ "$DB_ENGINE" = sqlite ]; then
        [ -f "$cfg" ] || ok "SQLite – nichts einzurichten (Datei liegt in data/)"
        return
    fi
    apt-get install -y -qq mariadb-server >/dev/null
    svc enable mariadb
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

setup_hr() {
    step "HypeRate-Weiterleitung ($HR_HOST)"
    local dir=/opt/ghostnode/hyperate-relay src=/opt/ghostnode/src/vrc_nexus
    install -d -o "$APP_USER" -g "$APP_USER" /opt/ghostnode /opt/ghostnode/src "$dir"
    if [ -d "$src/.git" ]; then git_as -C "$src" pull --ff-only -q || warn "git pull (vrc_nexus) fehlgeschlagen – alte Version bleibt."
    else
        rm -rf "$src"
        git_as clone -q --depth 1 --filter=blob:none --sparse "$REPO_NEXUS" "$src" || die "HypeRate-Weiterleitung konnte nicht geholt werden ($REPO_NEXUS)."
        git_as -C "$src" sparse-checkout set apps/hyperate-relay
    fi
    install -o "$APP_USER" -g "$APP_USER" -m 0644 "$src/apps/hyperate-relay/app.js" "$src/apps/hyperate-relay/package.json" "$dir/"
    save_kv "$CONF_DIR/hr.env" HYPERATE_API_KEY; echo "PORT=$HR_PORT" >> "$CONF_DIR/hr.env"; chmod 600 "$CONF_DIR/hr.env"
    write_unit ghostnode-hr "GhostNode HypeRate-Weiterleitung" "$dir" app.js "$CONF_DIR/hr.env"
    proxy_vhost ghostnode-hr "$HR_HOST" "$HR_PORT" sse
    [ -n "${HYPERATE_API_KEY:-}" ] || warn "Kein HypeRate-Key – die Weiterleitung läuft, verbindet sich aber nicht (später: $0 secrets)."
    svc enable ghostnode-hr; svc restart ghostnode-hr
    ok "läuft auf 127.0.0.1:$HR_PORT → https://$HR_HOST"
}

setup_bot() {
    step "Discord-Bot ($BOT_HOST)"
    local dir=/opt/ghostnode/discord-bot
    install -d -o "$APP_USER" -g "$APP_USER" /opt/ghostnode
    if [ -d "$dir/.git" ]; then git_as -C "$dir" pull --ff-only -q || warn "git pull (Bot) fehlgeschlagen – alte Version bleibt."
    elif [ -d "$dir" ] && [ -n "$(ls -A "$dir" 2>/dev/null)" ]; then warn "$dir ist kein Git-Checkout – nicht aktualisiert."
    else install -d -o "$APP_USER" -g "$APP_USER" "$dir"; git_as clone -q "$REPO_BOT" "$dir" || die "Bot konnte nicht geholt werden ($REPO_BOT)."; fi
    install -d -o "$APP_USER" -g "$APP_USER" -m 0750 "$dir/data"
    local DASHBOARD_URL="https://$BOT_HOST" NEXUS_BASE="https://$DOMAIN" HYPERATE_RELAY="https://$HR_HOST" BOT_DATA="$dir/data" PORT=$BOT_PORT
    save_kv "$CONF_DIR/bot.env" DISCORD_TOKEN DISCORD_APP_ID DISCORD_GUILD_ID RELEASE_CHANNEL_ID DISCORD_CLIENT_SECRET DASHBOARD_URL NEXUS_BASE HYPERATE_RELAY BOT_DATA PORT
    chmod 600 "$CONF_DIR/bot.env"
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
    apt-get install -y -qq coturn >/dev/null
    install -d -m 0750 -o root -g turnserver /etc/coturn/certs 2>/dev/null || install -d -m 0750 /etc/coturn/certs
    local tls=""; [ -s /etc/coturn/certs/fullchain.pem ] && tls=1
    cat > /etc/turnserver.conf <<EOF
# erzeugt von ghostnode-root.sh – Zugangsdaten werden von der Webseite je Konto für 6 h erzeugt (HMAC, use-auth-secret)
listening-port=3478
$( [ -n "$tls" ] && printf 'tls-listening-port=5349\ncert=/etc/coturn/certs/fullchain.pem\npkey=/etc/coturn/certs/privkey.pem\nno-tlsv1\nno-tlsv1_1\n' )
fingerprint
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
    printf '{"urls":[%s],"secret":"%s"}\n' "$urls" "$TURN_SECRET" > "$WEB_DIR/data/keys/turn.json"
    chown "$APP_USER:$APP_USER" "$WEB_DIR/data/keys/turn.json"; chmod 600 "$WEB_DIR/data/keys/turn.json"
    svc enable coturn; svc restart coturn
    ok "coturn: 3478 (UDP/TCP)$([ -n "$tls" ] && echo ', 5349 (TLS)'), Relais-Ports $TURN_MIN–$TURN_MAX; Webseite kennt den Server (data/keys/turn.json)"
    [ -n "$tls" ] || warn "TURN ohne TLS – nach „$0 certs“ wird 5349 (TLS) automatisch ergänzt."
}

setup_ftp() {
    step "FTPS-Eingang für Poster"
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
    cat > /usr/local/sbin/ghostnode-cron <<EOF
#!/bin/sh
# Aufgabenplaner der Webseite (Feeds, Kalender, Status, Webhooks, Föderation, Backups der Seite …)
set -a; [ -r $CONF_DIR/web.env ] && . $CONF_DIR/web.env; set +a
exec /usr/bin/php $WEB_DIR/cron.php
EOF
    cat > /usr/local/sbin/ghostnode-backup <<EOF
#!/bin/bash
# Server-Backup: Datenbank, data/, uploads/, Bot-Daten, Einstellungen – 14 Tage aufbewahrt
set -euo pipefail
umask 077
D=/var/backups/ghostnode; T=\$(date +%Y-%m-%d_%H%M); W=\$(mktemp -d)
mkdir -p "\$D"
if [ "$DB_ENGINE" = mariadb ]; then mysqldump --single-transaction --routines "$DB_NAME" | gzip > "\$W/datenbank.sql.gz"; fi
tar -czf "\$W/webseite-data.tar.gz" -C "$WEB_DIR" data \$( [ -d "$WEB_DIR/uploads" ] && echo uploads )
[ -d /opt/ghostnode/discord-bot/data ] && tar -czf "\$W/bot-data.tar.gz" -C /opt/ghostnode/discord-bot data
tar -czf "\$W/einstellungen.tar.gz" -C / etc/ghostnode
tar -cf "\$D/ghostnode-\$T.tar" -C "\$W" .
rm -rf "\$W"
find "\$D" -name 'ghostnode-*.tar' -mtime +14 -delete
EOF
    chmod 755 /usr/local/sbin/ghostnode-cron; chmod 700 /usr/local/sbin/ghostnode-backup
    cat > /etc/cron.d/ghostnode <<EOF
# erzeugt von ghostnode-root.sh
SHELL=/bin/sh
PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
*/5 * * * * $APP_USER /usr/local/sbin/ghostnode-cron >/dev/null 2>&1
17 3 * * * root /usr/local/sbin/ghostnode-backup >/dev/null 2>&1
EOF
    chmod 644 /etc/cron.d/ghostnode
    svc enable cron
    # Webseite auf „echten Cron“ stellen (nur möglich, wenn die Datenbank schon erreichbar ist)
    if runuser -u "$APP_USER" -- php -r 'chdir($argv[1]); $_SERVER["REMOTE_ADDR"]="127.0.0.1"; require "inc/core/pages.php"; set_setting("tasks_mode","cron"); echo "ok";' "$WEB_DIR" 2>/dev/null | grep ok >/dev/null; then
        ok "Aufgaben alle 5 min per Cron, Webseite steht auf „echter Cron“"
    else
        warn "Aufgabenplaner der Seite noch nicht umgestellt – nach dem ersten Login: Admin → System → Aufgaben → Modus „Cron“."
    fi
    ok "Backup täglich 03:17 nach /var/backups/ghostnode (14 Tage)"
}

setup_firewall() {
    step "Firewall & Schutz"
    apt-get install -y -qq ufw fail2ban unattended-upgrades >/dev/null
    local ssh_port=""; command -v sshd >/dev/null && ssh_port=$(sshd -T 2>/dev/null | awk '/^port /{print $2; exit}' || true); ssh_port=${ssh_port:-22}
    local u=1
    {
        ufw --force reset && ufw default deny incoming && ufw default allow outgoing \
        && ufw allow "$ssh_port/tcp" comment 'SSH' && ufw allow 80/tcp comment 'HTTP' && ufw allow 443/tcp comment 'HTTPS'
    } >/dev/null 2>&1 || u=0
    if want turn; then { ufw allow 3478 comment 'TURN' && ufw allow 5349/tcp comment 'TURN TLS' && ufw allow "$TURN_MIN:$TURN_MAX/udp" comment 'TURN Relais'; } >/dev/null 2>&1 || u=0; fi
    if want ftp; then { ufw allow 21/tcp comment 'FTPS' && ufw allow "$FTP_PASV_MIN:$FTP_PASV_MAX/tcp" comment 'FTPS passiv'; } >/dev/null 2>&1 || u=0; fi
    [ "$u" = 1 ] && ufw --force enable >/dev/null 2>&1 || warn "ufw ließ sich nicht einschalten (Container ohne Firewall-Rechte?) – auf dem echten Server: ufw status"
    cat > /etc/fail2ban/jail.d/ghostnode.conf <<EOF
[sshd]
enabled = true
[apache-auth]
enabled = true
[vsftpd]
enabled = $(want ftp && echo true || echo false)
EOF
    svc enable fail2ban; svc restart fail2ban
    dpkg-reconfigure -f noninteractive unattended-upgrades >/dev/null 2>&1 || true
    ok "ufw (SSH $ssh_port, 80, 443$(want turn && echo ', TURN')$(want ftp && echo ', FTPS')), fail2ban, automatische Sicherheitsupdates"
}

setup_tls() {
    step "Zertifikate (Let's Encrypt)"
    [ "$NO_TLS" = 1 ] && { warn "--no-tls: keine Zertifikate. Später: $0 certs"; return; }
    [ -n "${LE_EMAIL:-}" ] || { warn "Keine E-Mail für Let's Encrypt angegeben – später: $0 certs"; return; }
    apt-get install -y -qq certbot python3-certbot-apache >/dev/null
    local names=() n
    for n in "$DOMAIN" "www.$DOMAIN" "$HR_HOST" "$BOT_HOST" "$TURN_HOST"; do
        if dns_ok "$n"; then names+=(-d "$n"); else warn "$n zeigt (noch) nicht auf diesen Server – ausgelassen."; fi
    done
    [ ${#names[@]} -gt 0 ] || { warn "Kein Name zeigt auf diesen Server – DNS prüfen, dann $0 certs"; return; }
    # turn.<domain> braucht einen (leeren) Webserver-Eintrag für die Prüfung
    if [[ " ${names[*]} " == *" $TURN_HOST "* ]]; then
        printf '<VirtualHost *:80>\n    ServerName %s\n    Redirect 302 / https://%s/\n</VirtualHost>\n' "$TURN_HOST" "$DOMAIN" > /etc/apache2/sites-available/ghostnode-turn.conf
        a2ensite -q ghostnode-turn >/dev/null; svc reload apache2
    fi
    certbot --apache --non-interactive --agree-tos -m "$LE_EMAIL" --redirect --expand --cert-name ghostnode "${names[@]}" >/dev/null
    # Kopie für coturn und vsftpd (eigene Benutzer) – wird bei jeder Verlängerung erneuert
    cat > /etc/letsencrypt/renewal-hooks/deploy/ghostnode.sh <<'EOF'
#!/bin/sh
# Zertifikat für coturn und vsftpd bereitstellen (erzeugt von ghostnode-root.sh)
L=/etc/letsencrypt/live/ghostnode
install -d -m 0750 /etc/ghostnode/tls /etc/coturn/certs
install -m 0640 "$L/fullchain.pem" "$L/privkey.pem" /etc/ghostnode/tls/
install -m 0640 "$L/fullchain.pem" "$L/privkey.pem" /etc/coturn/certs/
chgrp -R turnserver /etc/coturn/certs 2>/dev/null || true
for s in coturn vsftpd apache2; do (systemctl try-restart "$s" || service "$s" restart) >/dev/null 2>&1 || true; done
EOF
    chmod 755 /etc/letsencrypt/renewal-hooks/deploy/ghostnode.sh
    /etc/letsencrypt/renewal-hooks/deploy/ghostnode.sh
    ok "Zertifikat „ghostnode“ für: ${names[*]//-d /}; Verlängerung automatisch (certbot.timer)"
    # TURN/FTPS jetzt mit TLS neu schreiben
    if want turn; then setup_turn; fi
    if want ftp; then setup_ftp; fi
    return 0
}

write_credentials() {
    {
        echo "# GhostNode – erzeugte Zugänge ($(date '+%d.%m.%Y %H:%M')). Nur für root. Nicht weitergeben."
        [ "$DB_ENGINE" = mariadb ] && echo "Datenbank:   MariaDB, Name $DB_NAME, Benutzer $DB_USER, Passwort $DB_PASS (nur localhost)"
        want ftp && echo "Poster-FTPS: Server $DOMAIN, Port 21, explizites TLS, Benutzer $FTP_USER, Passwort $FTP_PASS"
        want turn && echo "TURN:        $TURN_HOST, Geheimnis steht in $SECRETS (wird nie direkt an Nutzer gegeben)"
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
http_code() { lcurl "$1" "$2" -o /dev/null -w '%{http_code}' 2>/dev/null || echo 000; }
check_all() {
    step "Prüfung"
    FAILS=0
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
    if want hr; then
        is_active ghostnode-hr && ok "HypeRate-Weiterleitung läuft" || fail "HypeRate-Weiterleitung läuft nicht"
        c=$(lcurl "$HR_HOST" /health 2>/dev/null || true)
        [[ "$c" == *'"ok"'* ]] && ok "https://$HR_HOST/health antwortet: $c" || fail "Weiterleitung /health: ${c:-keine Antwort}"
    fi
    if want bot; then
        is_active ghostnode-bot && ok "Discord-Bot läuft" || fail "Discord-Bot läuft nicht"
        c=$(http_code "$BOT_HOST" "/health"); [[ "$c" =~ ^(200|503)$ ]] && ok "Bot /health → $c$([ "$c" = 503 ] && echo ' (ohne Token: Hinweisseite)')" || fail "Bot /health → $c"
    fi
    if want turn; then
        is_active coturn && ok "coturn läuft" || fail "coturn läuft nicht"
        [ -s "$WEB_DIR/data/keys/turn.json" ] && ok "Webseite kennt den TURN-Server" || fail "data/keys/turn.json fehlt"
    fi
    if want ftp; then
        is_active vsftpd && ok "FTPS (vsftpd) läuft" || fail "vsftpd läuft nicht"
    fi
    if want cron; then
        [ -f /etc/cron.d/ghostnode ] && ok "Cron eingerichtet" || fail "Cron fehlt"
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
    echo "  Webseite:        https://$DOMAIN  → zuerst https://$DOMAIN/admin.php öffnen und das Besitzer-Konto anlegen"
    want hr  && echo "  HypeRate:        https://$HR_HOST/health"
    want bot && echo "  Bot-Dashboard:   https://$BOT_HOST  (Discord-Portal: Redirect https://$BOT_HOST/callback)"
    want turn && echo "  Anrufe (TURN):   $TURN_HOST:3478 / 5349"
    want ftp && echo "  Poster-FTPS:     $DOMAIN:21 (Zugang in $CRED)"
    echo "  Zugänge:         $CRED"
    echo "  Aktualisieren:   bash $0 update     ·  Prüfen: bash $0 check  ·  Backup: bash $0 backup"
    echo "  DNS (A-Einträge auf $(public_ip)): $DOMAIN, www.$DOMAIN, $HR_HOST, $BOT_HOST, $TURN_HOST"
    if [ ${#WARNINGS[@]} -gt 0 ]; then echo; echo "${c_warn}Hinweise:${c_0}"; for w in "${WARNINGS[@]}"; do echo "  ⚠ $w"; done; fi
}

# ---------------------------------------------------------------- Befehle
load_conf
case "$CMD" in
    install)
        ask_config; ask_secrets
        base_packages
        { want hr || want bot; } && install_node
        want mail && setup_mail
        want web  && setup_web
        want db   && setup_db
        want hr   && setup_hr
        want bot  && setup_bot
        want turn && setup_turn
        want ftp  && setup_ftp
        want cron && setup_cron
        want tls  && setup_tls
        want fw   && setup_firewall
        write_credentials
        check_all || true
        summary ;;
    update)
        [ -f "$CONF" ] || die "Noch nicht eingerichtet – zuerst: $0 install"
        if want web; then step "Webseite"; git_as -C "$WEB_DIR" pull --ff-only || die "git pull der Webseite fehlgeschlagen."; ok "aktuell: $(git_as -C "$WEB_DIR" log -1 --format='%h %s')"; svc reload apache2 || true; fi
        want hr  && setup_hr
        want bot && setup_bot
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
    backup) [ -x /usr/local/sbin/ghostnode-backup ] || die "Backup nicht eingerichtet."; /usr/local/sbin/ghostnode-backup && ok "Backup: $(ls -t /var/backups/ghostnode/ghostnode-*.tar | head -1)" ;;
    *) die "Unbekannter Befehl: $CMD (install, update, certs, secrets, check, backup)" ;;
esac
