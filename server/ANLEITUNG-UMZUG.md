# GhostNode: Umzug von Plesk auf den neuen Root-Server

**Neuer Server:** `45.93.249.81` · **Domain:** `ghostnode-labs.dev`

> ⚠ **Wichtig:** Dein DNS zeigt schon auf den neuen Server. Die Webseite ist offline, bis Schritt 4 fertig ist. Am besten alles in einem Rutsch machen; das dauert etwa 30–45 Minuten.

Ein einziger Befehl holt alles von GitHub (Webseite `ghostenode`, Bot `node_discord`, HypeRate aus `vrc_nexus`) und richtet es ein. Nach dem Umzug läuft alles auf dem neuen Server:
- die Webseite
- die MySQL-Datenbank (MariaDB)
- der Discord-Bot
- die HypeRate-Weiterleitung
- TURN für Anrufe
- Mumble
- Firewall und tägliches Backup
- ein Cron-Dienst: Aufgaben der Webseite alle 5 min, ein Wächter, der ausgefallene Dienste neu startet, nächtliches Auto-Update von GitHub

**nginx** steht als Reverse Proxy vorne und übernimmt HTTPS. Pterodactyl-Eggs gibt es nicht mehr.

---

## Was du brauchst

- den Zugang zu **Plesk** (alter Server)
- einen **SSH-Zugang** zum neuen Server als `root`: Benutzer `root` und das Passwort bzw. den Schlüssel deines Anbieters
- **WinSCP** (Windows) oder FileZilla zum Hochladen
- optional: den **Discord-Bot-Token** und die **Application-ID** (Discord-Entwicklerportal → deine Bot-App) sowie deinen **HypeRate-API-Key**

---

## Schritt 1 – Plesk: Datenbank exportieren

1. Plesk öffnen → **Websites & Domains** → `ghostnode-labs.dev` → **Datenbanken**
2. Bei deiner Datenbank auf **Exportieren** (bzw. „Dump exportieren“) klicken
3. Die `.zip`-Datei herunterladen, z. B. `ghostnode_2026-11-27.zip`

## Schritt 2 – Plesk: Dateien packen

1. Plesk → **Dateien** → Ordner **`httpdocs`** öffnen
2. Die Ordner **`data`** und **`uploads`** anhaken
3. Oben auf **Zu Archiv hinzufügen** klicken → Name z. B. `dateien.zip`
4. Die neue `dateien.zip` anhaken → **Herunterladen**

> In `data` stecken unter anderem die Schlüssel für verschlüsselte Einstellungen. Ohne sie müssten Discord-Login und andere Geheimnisse neu eingetragen werden. Also unbedingt mitnehmen.

## Schritt 3 – Hochladen auf den neuen Server

1. **WinSCP** starten → neue Sitzung:
   - Protokoll **SFTP**
   - Host `45.93.249.81`
   - Port `22`
   - Benutzer `root`
2. Rechts in den Ordner **`/root`** wechseln
3. Beide Zip-Dateien aus Schritt 1 und 2 hineinziehen

## Schritt 4 – Einrichten und umziehen (ein Befehl)

1. Per SSH auf den Server, z. B. in der Windows-Eingabeaufforderung oder PowerShell:
   ```
   ssh root@45.93.249.81
   ```
2. Kurz prüfen, ob die Dateien da sind:
   ```
   ls /root
   ```
3. Den folgenden Befehl einfügen. Die **zwei Dateinamen** ersetzt du durch deine aus `ls`:
   ```
   curl -fsSL https://raw.githubusercontent.com/ghostnode-labs/ghostenode/main/server/install.sh | bash -s -- migrate --db=/root/ghostnode_2026-11-27.zip --files=/root/dateien.zip
   ```
   Fehlt `curl`, vorher `apt-get update && apt-get install -y curl` ausführen.

### Fragen des Installers

| Frage | Was eingeben |
|---|---|
| Rolle | wird beim Umzug automatisch `alles` (ohne Umzug: **Enter** = `alles`) |
| Haupt-Domain der Webseite | **Enter** (`ghostnode-labs.dev`) |
| E-Mail für Let's Encrypt | deine E-Mail-Adresse |
| Verzeichnis der Webseite | **Enter** |
| Adressen hr / discord / turn / voice | jeweils **Enter** |
| Zeitzone | **Enter** (`Europe/Berlin`) |
| HypeRate-API-Key | deinen Key, oder Enter (später nachtragen) |
| Discord-Bot-Token | den Token aus dem Discord-Portal → Bot |
| Application-ID | aus dem Discord-Portal → General Information |
| Server-ID / Release-Kanal | optional, Enter |
| OAuth2-Client-Secret (Bot) | **Enter**. Wird nicht mehr gebraucht, die Anmeldung läuft über die Webseite. |
| Discord-Client-ID/-Secret (Webseite) | **Enter**. Ist schon in deiner Datenbank und kommt mit dem Umzug mit. |
| Status-Webhook, SMTP | optional, Enter |

Danach läuft alles von allein:
- Webseite, nginx und MariaDB einrichten
- Sicherung anlegen
- Datenbank einspielen
- `data` und `uploads` übernehmen
- Bot, HypeRate, TURN, Mumble, Firewall, Backup und Cron-Dienst einrichten
- den Schlüssel fürs Bot-Dashboard automatisch auf Webseite und Bot setzen

Am Ende siehst du **„Webseite antwortet (200)“**.

> **Ohne Umzug** (frischer Server, leere Webseite) reicht:
> ```
> curl -fsSL https://raw.githubusercontent.com/ghostnode-labs/ghostenode/main/server/install.sh | bash
> ```

> Geht beim Einspielen etwas schief, stellt das Script den vorherigen Stand automatisch wieder her und bricht ab. Dann einfach die rote Meldung an Claude schicken.

## Schritt 5 – HTTPS-Zertifikate holen

```
bash /root/ghostenode/server/ghostnode-root.sh certs
```

Das holt Let's-Encrypt-Zertifikate für alle Namen: Webseite, www, discord, hr, turn und voice.

## Schritt 6 – Prüfen

```
bash /root/ghostenode/server/ghostnode-root.sh check
```

Alles sollte **✔** zeigen. Danach im Browser testen:

- `https://ghostnode-labs.dev`: Startseite, **anmelden**, Admin öffnen
- `https://ghostnode-labs.dev/botdash.php`: Discord verknüpft? Dann **„Bot-Dashboard öffnen“**. Den Schlüssel dafür hat das Script schon automatisch auf beiden Seiten eingetragen.
- `https://hr.ghostnode-labs.dev/health`: zeigt `"ok"`
- In der **VRC-Nexus-App**: Nexus-Konto verbinden, Chat und Community testen

## Schritt 7 – Alten Plesk-Server aufräumen

Erst wenn alles läuft:

1. Plesk → `discord.ghostnode-labs.dev` → **Node.js** → Anwendung **deaktivieren**. Sonst laufen zwei Bots mit demselben Token.
2. Plesk → `hr.ghostnode-labs.dev` → Node.js → ebenfalls **deaktivieren**.
3. Die Plesk-Webseite noch ein paar Tage stehen lassen, als Rückfallebene. Danach kündigen bzw. löschen.

---

## Später

| Was | Befehl |
|---|---|
| Alles aktualisieren | `curl -fsSL https://raw.githubusercontent.com/ghostnode-labs/ghostenode/main/server/install.sh \| bash` |
| Prüfen | `bash /root/ghostenode/server/ghostnode-root.sh check` |
| Schlüssel ändern (Bot-Token, HypeRate …) | `bash /root/ghostenode/server/ghostnode-root.sh secrets` |
| Backup jetzt | `bash /root/ghostenode/server/ghostnode-root.sh backup` |
| Geplante Aufgaben ansehen (Cron, Wächter, Backup, Update) | `bash /root/ghostenode/server/ghostnode-root.sh crons` |

**Wo liegt was:**
- `/root/ghostnode-zugaenge.txt`: erzeugte Passwörter (Datenbank, Poster-FTPS, Mumble-SuperUser)
- `/var/backups/ghostnode/`: tägliche Backups und die Sicherung „vor-umzug-…“
- `/var/log/ghostnode-cron.log`: Protokoll aller geplanten Aufgaben
- Logs:
  - `journalctl -u ghostnode-bot -f` (Bot)
  - `journalctl -u ghostnode-hr -f` (HypeRate)
  - `/var/log/apache2/ghostnode-error.log` (Webseite)

## Wenn etwas hakt

| Problem | Lösung |
|---|---|
| `Permission denied` | vorher `sudo -i` eingeben |
| `already exists and is not an empty directory` | einfach den `install.sh`-Befehl nehmen, der erledigt das selbst |
| Zertifikat schlägt fehl | DNS prüfen (alle Namen → `45.93.249.81`), Port 80 und 443 beim Anbieter offen? Dann `… certs` erneut |
| Webseite zeigt 502 | `systemctl restart apache2 php*-fpm`, danach `… check` |
| Bot antwortet nicht | `journalctl -u ghostnode-bot -n 50`. Fehlt der Token, trägst du ihn mit `… secrets` nach. |
| Zurück zu Plesk | im DNS `@` und `www` wieder auf `45.81.232.18` setzen |
