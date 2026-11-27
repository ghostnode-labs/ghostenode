# GhostNode auf dem eigenen Root-Server

`ghostnode-root.sh` richtet einen Server (Debian 12, Ubuntu 22.04 oder 24.04) für GhostNode und VRC Nexus ein, hält ihn aktuell und prüft ihn.
Er verträgt sich mit **Pterodactyl** (Panel + Wings) auf demselben Server.

## Rollen

| Rolle | Was hier läuft | Was woanders bleibt |
|---|---|---|
| `dienste` (Standard) | HypeRate-Weiterleitung, Discord-Bot, TURN für Anrufe, Mumble, Firewall, Backup | Webseite, MySQL, FTP, Mail und Aufgabenplaner bleiben auf **Plesk** |
| `alles` | zusätzlich die Webseite (Apache + PHP-FPM, MariaDB, Poster-FTPS, Mail, Aufgabenplaner) | – |

Die Rolle fragt das Script beim `install` ab. Sie steht danach in `/etc/ghostnode/setup.conf`.

## Bausteine

| Teil | Was läuft | Adresse |
|---|---|---|
| `hr` | HypeRate-Weiterleitung (Node.js, ohne Pakete) | `https://hr.<domain>` |
| `bot` | GhostNode-Discord-Bot mit Web-Dashboard | `https://discord.<domain>` |
| `turn` | TURN/STUN (coturn) für Anrufe im Nexus-Chat. Die Zugangsdaten erzeugt die Webseite je Konto. | `turn.<domain>:3478 / 5349` |
| `voice` | Mumble-Sprachserver | `voice.<domain>:64738` |
| `tls` | Let's-Encrypt-Zertifikat für alle Namen mit automatischer Verlängerung (gilt auch für coturn, Mumble und FTPS) | – |
| `fw` | Firewall (ufw), fail2ban, automatische Sicherheitsupdates | – |
| `web`, `db`, `ftp`, `mail`, `cron` | nur Rolle `alles`: Webseite, MariaDB, Poster-FTPS, Mailversand, Aufgabenplaner alle 5 min | `https://<domain>` |

Das Server-Backup läuft täglich um 03:17 und wird 14 Tage aufbewahrt (`/var/backups/ghostnode`).

## Vorher: DNS

Lege A-Einträge auf die IP des Servers an: `hr.<domain>`, `discord.<domain>`, `turn.<domain>`, `voice.<domain>`.
Bei Rolle `alles` kommen `<domain>` und `www.<domain>` dazu.
Bei Rolle `dienste` zeigen `<domain>`/`www` weiter auf Plesk.
Stehen die Einträge noch nicht, läuft trotzdem alles. Die Zertifikate holst du später mit `… certs`.

## Einrichten

```bash
# als root auf dem Server – holt Git, das Repo (oder aktualisiert es) und startet die Einrichtung
curl -fsSL https://raw.githubusercontent.com/ghostnode-labs/ghostenode/main/server/install.sh | bash
```
Derselbe Befehl später noch einmal führt ein Update aus.

Das Script fragt nach Rolle, Domain, E-Mail für Let's Encrypt, Adressen und Schlüsseln.
**Alle Schlüssel sind optional.** Lässt du einen leer, bleibt die Funktion aus, bis du sie mit `… secrets` nachträgst.

| Schlüssel | Wofür | Woher |
|---|---|---|
| HypeRate-API-Key | Puls über HypeRate | HypeRate-Entwicklerzugang |
| Discord-Bot-Token, Application-ID | Bot | Discord-Entwicklerportal → Bot |
| OAuth2-Client-Secret (Bot) | Anmeldung am Bot-Dashboard; als Redirect `https://discord.<domain>/callback` eintragen | Discord-Portal → OAuth2 |
| Discord-Client-ID/-Secret (Webseite, nur `alles`) | „Mit Discord anmelden“; geht auch später im Admin | Discord-Portal (eigene App) |
| SMTP-Server, Benutzer, Passwort (nur `alles`) | E-Mails, z. B. bei Status-Störungen | dein Mail-Anbieter |

Bei Rolle `alles` öffnest du danach `https://<domain>/admin.php` und legst das **Besitzer-Konto** an.

## Mit Pterodactyl

Das Script erkennt Panel und Wings (`/etc/pterodactyl/config.yml`) und richtet sich danach:

- **nginx bleibt vorne.** Das Script legt eigene nginx-Seiten an und rührt die Panel-Seite nicht an. Schlägt `nginx -t` fehl, nimmt es die eigene Seite sofort wieder zurück. Bei Rolle `alles` läuft Apache nur intern auf `127.0.0.1:18080`.
- **PHP und MySQL des Panels bleiben unverändert.** Eine vorhandene MySQL/MariaDB wird mitbenutzt, mit eigener Datenbank und eigenem Benutzer.
- **Die Firewall wird nie zurückgesetzt.** Die Wings-Ports (API und SFTP) werden freigegeben. Lauschen schon unbekannte öffentliche Ports, schaltet das Script ufw nicht selbst ein, sondern sagt Bescheid.
- **Weiterleitung und Bot laufen als Eggs** (Betriebsart `egg`, Standard mit Pterodactyl). Das Script macht nur nginx + HTTPS davor.

### Eggs importieren

Admin → Nests → *Import Egg*:

| Egg | Datei | Zuweisung (Allocation) |
|---|---|---|
| GhostNode Discord-Bot | [`node_discord/pterodactyl/egg-ghostnode-discord-bot.json`](https://github.com/ghostnode-labs/node_discord/tree/HEAD/pterodactyl) | `127.0.0.1:18092` |
| GhostNode HypeRate-Weiterleitung | [`vrc_nexus/apps/hyperate-relay/egg-ghostnode-hyperate-relay.json`](https://github.com/ghostnode-labs/vrc_nexus/tree/HEAD/apps/hyperate-relay) | `127.0.0.1:18091` |
| Mumble | im Panel mitgeliefertes Egg „Mumble Server“ (Nest „Voice Servers“) | `<Server-IP>:64738` |

Lege die Zuweisungen für Bot und Weiterleitung auf `127.0.0.1` an (Admin → Nodes → Allocation). Dann sind sie nur über nginx/HTTPS erreichbar. Beim `install` fragt das Script nach genau diesen Zuweisungen.
Die Schlüssel (Bot-Token, HypeRate-Key …) trägst du im Panel als Variablen des jeweiligen Servers ein, nicht im Script.
Mit `AUTO_UPDATE = 1` holt sich der Server beim Neustart die neueste Version aus Git.

Mumble verbindet direkt, ohne nginx. Docker-Ports umgehen ufw, die Freigabe macht also Pterodactyl.

Ohne Pterodactyl (oder mit Betriebsart `systemd`) laufen Weiterleitung und Bot als eigene Dienste (`ghostnode-hr`, `ghostnode-bot`). Mumble läuft dann als `mumble-server`.

## Rolle „dienste“: Anrufe für die Plesk-Webseite

Liegt die Webseite auf Plesk, schreibt das Script den TURN-Zugang nach `/etc/ghostnode/turn-fuer-plesk.json` (nur root).
Lade diese Datei in Plesk mit dem Dateimanager als `data/keys/turn.json` in die Webseite hoch und setze die Rechte auf 600. Danach laufen die Anrufe im Nexus-Chat über diesen Server.

## Später

```bash
bash /root/ghostenode/server/ghostnode-root.sh update    # aktualisieren, Dienste neu starten, alles prüfen
bash /root/ghostenode/server/ghostnode-root.sh check     # Dienste, Seiten, Ports, Zertifikat, Firewall prüfen
bash /root/ghostenode/server/ghostnode-root.sh certs     # Zertifikate holen/erweitern (nach DNS-Umstellung)
bash /root/ghostenode/server/ghostnode-root.sh secrets   # Schlüssel ändern und übernehmen
bash /root/ghostenode/server/ghostnode-root.sh backup    # Backup jetzt (→ /var/backups/ghostnode)
```

Optionen: `--yes` (keine Rückfragen), `--no-tls`, `--only=hr,bot`, `--skip=voice,turn`.

## Wo liegt was

| Pfad | Inhalt |
|---|---|
| `/etc/ghostnode/` | `setup.conf` (Einstellungen), `secrets.env` (Schlüssel, nur root), `bot.env`, `hr.env`, `web.env`, `turn-fuer-plesk.json` |
| `/opt/ghostnode/hyperate-relay`, `/opt/ghostnode/discord-bot` | Weiterleitung und Bot im Betrieb `systemd` (mit Eggs liegen sie in Pterodactyl) |
| `/var/www/ghostnode` | nur Rolle `alles`: Webseite (Git-Checkout); `data/` = Datenbank-Zugang, Schlüssel, Uploads, nie öffentlich |
| `/root/ghostnode-zugaenge.txt` | erzeugte Zugänge (Datenbank, Poster-FTPS, Mumble-SuperUser), nur root |
| `/var/backups/ghostnode` | tägliche Backups (Datenbank, `data/`, Bot-Daten, Mumble, Einstellungen) |

Logs: `journalctl -u ghostnode-bot -f`, `journalctl -u ghostnode-hr -f` (mit Eggs: Konsole im Panel), `/var/log/nginx/`, `/var/log/apache2/ghostnode-error.log`.

## Umzug von Plesk auf den Root-Server (alles)

Auf dem neuen Server laufen danach Webseite, MySQL/MariaDB, HypeRate, Bot, TURN und Mumble. nginx steht als Reverse Proxy vorne und übernimmt HTTPS.

**1. In Plesk zwei Dateien holen**
- Datenbank: Websites & Domains → Datenbanken → deine Datenbank → **Exportieren** (Dump, ergibt eine `.zip`)
- Dateien: Dateien → `httpdocs` → `data` und `uploads` anhaken → **Zu Archiv hinzufügen** (zip) → herunterladen

Beide Dateien per SFTP/WinSCP nach `/root/` auf den neuen Server laden.

**2. Auf dem neuen Server (als root)**
```bash
curl -fsSL https://raw.githubusercontent.com/ghostnode-labs/ghostenode/main/server/install.sh | bash -s -- migrate --db=/root/DATENBANK.zip --files=/root/DATEIEN.zip
```
Das Script richtet die Webseite ein (Rolle `alles`), sichert den bisherigen Stand und spielt die Datenbank ein. Dabei werden `DEFINER`-Angaben von Plesk entfernt und das Tabellen-Präfix wird übernommen. Danach übernimmt es `data/` mit den Schlüsseln, JSON-Daten und Bildern sowie `uploads/`. Schlägt der Import fehl, wird der vorherige Stand automatisch zurückgespielt.

Statt der Export-Datei geht auch die direkte Verbindung zur Plesk-MySQL. Dafür muss in Plesk beim Datenbank-Benutzer der Fernzugriff erlaubt sein:
```bash
bash /root/ghostenode/server/ghostnode-root.sh migrate --from=PLESK-HOST --src-db=NAME --src-user=BENUTZER --files=/root/DATEIEN.zip
```

**3. Umschalten**
1. Testen: in der `hosts`-Datei deines PCs `<Server-IP> <domain> www.<domain>` eintragen und die Seite öffnen.
2. DNS: A-Einträge für `<domain>`, `www`, `hr`, `discord`, `turn`, `voice` auf die neue IP setzen; CNAMEs auf Plesk löschen.
3. `bash /root/ghostenode/server/ghostnode-root.sh certs` holt die Zertifikate.
4. Plesk erst abschalten, wenn alles läuft. Die Sicherung vom Stand vor dem Umzug liegt unter `/var/backups/ghostnode/vor-umzug-…`.

## Getestet

Getestet auf Ubuntu 24.04 mit Testdomain, ohne Let's Encrypt:

- **Rolle `alles`, ohne Pterodactyl:**
  - alle Seiten und die gesperrten Ordner (`data/`, `inc/`, `.git/`, `tools/`, `server/`)
  - API mit Schlüssel-Kopfzeile, MariaDB, Aufgabenplaner
  - Weiterleitung und Bot über den Proxy
  - TURN-Anmeldung mit den Zugangsdaten der Webseite (private Netze gesperrt)
  - Poster-FTPS: ohne TLS abgelehnt, nur der Eingangsordner sichtbar
  - Mail-Konfiguration, TLS für TURN/FTPS, Backup, Update
- **Mit Pterodactyl (nachgestellt):**
  - Panel-nginx auf 80/443, Wings-Ports, Apache dahinter auf 127.0.0.1
- **Rolle `dienste` mit Eggs:**
  - Bot- und Weiterleitungs-Egg mit ihren Install-Scripts aufgesetzt und mit ihrem Startbefehl gestartet
  - nginx/HTTPS davor, `/health` beider Dienste, TURN-Datei für Plesk
  - `check` ohne Fehler

Nicht getestet:

- das Holen echter Let's-Encrypt-Zertifikate (braucht öffentliche DNS-Einträge)
- der native Mumble-Dienst ohne Pterodactyl
- echter Mailversand
