# GhostNode auf dem eigenen Root-Server

`ghostnode-root.sh` richtet einen frischen Server (Debian 12, Ubuntu 22.04 oder 24.04) mit **allem** ein, was GhostNode und VRC Nexus brauchen – und hält ihn danach aktuell.

| Teil | Was läuft | Adresse |
|---|---|---|
| `web` | Webseite (Apache + PHP-FPM als eigener Benutzer `ghostnode`, Git-Checkout) – Dashboard, VRC Nexus Web, Clubs, Marktplatz, Status, API, OAuth, Föderation, Toy-Relay, Übersetzen … | `https://<domain>` |
| `db` | MariaDB mit eigener Datenbank und Zufallspasswort (oder SQLite) | nur lokal |
| `hr` | HypeRate-Weiterleitung (Node.js, ohne Pakete) | `https://hr.<domain>` |
| `bot` | GhostNode-Discord-Bot mit Web-Dashboard | `https://discord.<domain>` |
| `turn` | TURN/STUN (coturn) für Anrufe im Nexus-Chat – Zugangsdaten erzeugt die Webseite je Konto | `turn.<domain>:3478 / 5349` |
| `ftp` | FTPS-Eingang für VRChat-Poster (nur dieser Ordner, nur verschlüsselt) | `<domain>:21` |
| `mail` | Mailversand über deinen SMTP-Anbieter (Status-Meldungen) | – |
| `cron` | Aufgabenplaner der Webseite alle 5 min, Server-Backup täglich 03:17 (14 Tage) | – |
| `tls` | Let's-Encrypt-Zertifikat für alle Namen, automatische Verlängerung (auch für coturn und FTPS) | – |
| `fw` | Firewall (ufw), fail2ban, automatische Sicherheitsupdates | – |

## Vorher: DNS

A-Einträge (und ggf. AAAA) auf die IP des Servers: `<domain>`, `www.<domain>`, `hr.<domain>`, `discord.<domain>`, `turn.<domain>`.
Stehen sie noch nicht, läuft alles trotzdem – Zertifikate holst du später mit `… certs`.

## Einrichten

```bash
# als root auf dem neuen Server
apt-get update && apt-get install -y git
git clone https://github.com/ghostnode-labs/ghostenode.git /root/ghostenode
bash /root/ghostenode/server/ghostnode-root.sh install
```

Das Script fragt nach Domain, E-Mail (für Let's Encrypt), Datenbank (Standard: MariaDB) und den Schlüsseln.
**Alle Schlüssel sind optional** – leer lassen heißt: diese Funktion bleibt aus, bis du sie mit `… secrets` nachträgst.

| Schlüssel | Wofür | Woher |
|---|---|---|
| HypeRate-API-Key | Puls über HypeRate | HypeRate-Entwicklerzugang |
| Discord-Bot-Token, Application-ID | Bot | Discord-Entwicklerportal → Bot |
| OAuth2-Client-Secret (Bot) | Anmeldung am Bot-Dashboard; Redirect `https://discord.<domain>/callback` eintragen | Discord-Portal → OAuth2 |
| Discord-Client-ID/-Secret (Webseite) | „Mit Discord anmelden“ – geht auch später im Admin | Discord-Portal (eigene App) |
| SMTP-Server, Benutzer, Passwort | E-Mails (Status-Störungen) | dein Mail-Anbieter |

Danach: `https://<domain>/admin.php` öffnen und das **Besitzer-Konto** anlegen.

## Später

```bash
bash /root/ghostenode/server/ghostnode-root.sh update    # Webseite + Weiterleitung + Bot aktualisieren, Dienste neu starten, alles prüfen
bash /root/ghostenode/server/ghostnode-root.sh check     # Dienste, Seiten, gesperrte Ordner, Datenbank, Zertifikat prüfen
bash /root/ghostenode/server/ghostnode-root.sh certs     # Zertifikate holen/erweitern (nach DNS-Umstellung)
bash /root/ghostenode/server/ghostnode-root.sh secrets   # Schlüssel ändern und übernehmen
bash /root/ghostenode/server/ghostnode-root.sh backup    # Backup jetzt (→ /var/backups/ghostnode)
```

Optionen: `--yes` (keine Rückfragen), `--no-tls`, `--only=web,bot`, `--skip=ftp,turn`.

## Wo liegt was

| Pfad | Inhalt |
|---|---|
| `/var/www/ghostnode` | Webseite (Git-Checkout); `data/` = Datenbank-Zugang, Schlüssel, Uploads – nie öffentlich |
| `/opt/ghostnode/hyperate-relay`, `/opt/ghostnode/discord-bot` | Weiterleitung und Bot (Dienste `ghostnode-hr`, `ghostnode-bot`) |
| `/etc/ghostnode/` | `setup.conf` (Einstellungen), `secrets.env` (Schlüssel, nur root), `bot.env`, `hr.env`, `web.env` |
| `/root/ghostnode-zugaenge.txt` | erzeugte Zugänge (Datenbank, Poster-FTPS) – nur root |
| `/var/backups/ghostnode` | tägliche Backups (Datenbank-Dump, `data/`, Bot-Daten, Einstellungen) |

Logs: `journalctl -u ghostnode-bot -f`, `journalctl -u ghostnode-hr -f`, `/var/log/apache2/ghostnode-error.log`.

## Umzug von Plesk

1. Auf dem alten Server: Admin → System → Backup erstellen und herunterladen.
2. Neuen Server mit diesem Script einrichten, Besitzer-Konto anlegen.
3. Admin → System → **Backup-Datei hochladen und wiederherstellen**.
4. Bot-Daten: Ordner `data/` des Bots nach `/opt/ghostnode/discord-bot/data/` kopieren, `chown -R ghostnode: …`, `systemctl restart ghostnode-bot`.
5. DNS umstellen, dann `… certs`.

## Getestet

Komplett durchgelaufen auf Ubuntu 24.04 (Testdomain, ohne Let's Encrypt): alle Seiten, gesperrte Ordner (`data/`, `inc/`, `.git/`, `tools/`, `server/`), API mit Schlüssel-Kopfzeile, MariaDB, Aufgabenplaner, HypeRate-Weiterleitung über den Proxy, Bot-Dashboard, TURN-Anmeldung mit den von der Webseite erzeugten Zugangsdaten (Weiterleitung in private Netze gesperrt), Poster-Upload per FTPS (ohne TLS abgelehnt, nur Eingangsordner sichtbar), Mail-Konfiguration, TLS für TURN und FTPS, Backup, Update.
Nicht im Test: das Holen echter Let's-Encrypt-Zertifikate (braucht öffentliche DNS-Einträge) – das übernimmt certbot mit dem Apache-Plugin.
