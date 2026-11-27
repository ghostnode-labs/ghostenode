# GhostNode: neuer Root-Server mit Übernahme der alten MySQL

**Neuer Server:** `45.93.249.81` · **Domain:** `ghostnode-labs.dev`

Ein Befehl holt alles von GitHub:
- die Webseite (`ghostenode`)
- den Discord-Bot (`node_discord`)
- die HypeRate-Weiterleitung (aus `vrc_nexus`)

Danach richtet er alles ein. Aus Plesk wird **nur die alte MySQL-Datenbank** übernommen, direkt über das Netz. Die alte MySQL dient danach als Backup.

---

## 1. Plesk: Fernzugriff auf die alte Datenbank erlauben

1. Plesk → **Websites & Domains** → **Datenbanken**
2. Bei deiner Datenbank: **Benutzerverwaltung** → den Benutzer bearbeiten
3. **Remote-Verbindungen zulassen von:** die IP `45.93.249.81` (oder „Beliebiger Host“) → speichern
4. Notieren:
   - **Datenbankserver** (Adresse, z. B. `45.81.232.18` oder ein Name wie `db…`)
   - **Datenbankname**
   - **Benutzer**
   - **Passwort**

## 2. Auf dem neuen Server (als root)

```
ssh root@45.93.249.81
```

```
curl -fsSL https://raw.githubusercontent.com/ghostnode-labs/ghostenode/main/server/install.sh | bash
```

### Fragen des Installers

| Frage | Eingabe |
|---|---|
| Rolle | **Enter** (`alles`) |
| Haupt-Domain | **Enter** (`ghostnode-labs.dev`) |
| E-Mail für Let's Encrypt | deine E-Mail |
| Verzeichnis der Webseite | **Enter** |
| Datenbank | **Enter** (`mariadb`) |
| **Alte MySQL – Adresse** | der Datenbankserver aus Schritt 1 |
| … Port | **Enter** (`3306`) |
| … Name / Benutzer | aus Schritt 1 |
| Alte MySQL danach als Backup nutzen? | **Enter** (`j`) |
| Automatische Updates | **Enter** (`1`) |
| Adressen hr / discord / turn / voice, Zeitzone | jeweils **Enter** |
| HypeRate-Key, Bot-Token, Application-ID | eintragen (oder Enter = später mit `… secrets`) |
| OAuth2-Client-Secret (Bot) | **Enter** (nicht nötig) |
| Discord-Client-ID/-Secret Webseite | eintragen (siehe unten) oder Enter |
| SMTP, Webhook | optional, Enter |
| **Passwort der alten MySQL** | das Passwort aus Schritt 1 |
| Mumble-Passwort | optional, Enter |

Danach läuft alles von allein:
- Webseite, nginx als Reverse Proxy und MariaDB einrichten
- die alte Datenbank als `.sql.gz` sichern und in die neue übernehmen
- Bot, HypeRate, TURN, Mumble, Firewall, Backup, Cron-Dienst, Wächter, Auto-Update und Server-Agent einrichten

> Klappt die Verbindung zur alten MySQL nicht, läuft die Einrichtung trotzdem durch. Fernzugriff in Plesk prüfen, dann: `bash /root/ghostenode/server/ghostnode-root.sh olddb`

## 3. HTTPS und Prüfung

```
bash /root/ghostenode/server/ghostnode-root.sh certs
bash /root/ghostenode/server/ghostnode-root.sh check
```

## 4. Im Browser

- `https://ghostnode-labs.dev/admin.php`: anmelden wie bisher, die Benutzer kommen aus der alten Datenbank
- Admin → **🖥 Server**: das Server-Dashboard mit Dienste-Status, Neustart-Knöpfen, Backup, Update, Zertifikat und Protokoll
  - außerdem: SSH-Schlüssel verwalten, Passwort-Login abschalten, gesperrte IPs (fail2ban) freigeben, Firewall-Status, Protokolle je Dienst, System-Updates und Server-Neustart
- Weil keine Dateien aus Plesk übernommen werden, im Admin **neu eintragen**:
  - „Mit Discord anmelden“ → Client-Secret (oder beim Installer als Discord-Client-ID/-Secret angeben)
  - VRC Nexus → Dienst-Konto neu verbinden
- `https://ghostnode-labs.dev/botdash.php` → „Bot-Dashboard öffnen“. Der Schlüssel ist schon automatisch gesetzt.

## 5. Plesk aufräumen

- Node.js-Apps für `discord.` und `hr.` in Plesk **deaktivieren**. Sonst laufen zwei Bots mit demselben Token.
- Die alte Datenbank **nicht löschen**: Sie ist jetzt dein Backup-Ziel und wird jede Nacht um 03:17 aktualisiert.

---

## Später

| Was | Befehl |
|---|---|
| Alles aktualisieren (passiert auch jede Nacht um 04:41) | `curl -fsSL https://raw.githubusercontent.com/ghostnode-labs/ghostenode/main/server/install.sh \| bash` |
| Prüfen | `bash /root/ghostenode/server/ghostnode-root.sh check` |
| Geplante Aufgaben ansehen | `bash /root/ghostenode/server/ghostnode-root.sh crons` |
| Schlüssel ändern | `bash /root/ghostenode/server/ghostnode-root.sh secrets` |
| Alte MySQL erneut holen / Zugang ändern | `… olddb --force` / `… olddb --neu` |

Oder einfach im Admin-Panel unter **🖥 Server**.

## Wenn etwas hakt

| Problem | Lösung |
|---|---|
| `dpkg was interrupted` / `parsing file …/updates/…` | erledigt der Installer selbst; sonst `find /var/lib/dpkg/updates -type f -size 0 -delete; dpkg --configure -a` |
| `Permission denied` | vorher `sudo -i` |
| „Zu viele Fehlversuche“ / Passwort vergessen / 2FA-Handy weg | per SSH: `bash /root/ghostenode/server/ghostnode-root.sh login entsperren` · `… login passwort BENUTZER` · `… login 2fa-aus BENUTZER` · `… login liste` |
| Alte MySQL nicht erreichbar | Fernzugriff in Plesk, richtige Adresse (Datenbankserver), Port 3306 beim alten Anbieter offen? |
| Zertifikat schlägt fehl | DNS prüfen (alles auf `45.93.249.81`), dann `… certs` erneut |
