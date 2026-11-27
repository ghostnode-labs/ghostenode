# GhostNode selbst hosten

Dieses Paket ist die komplette GhostNode-Webseite (Dashboard, VRC-Nexus-Web, Clubs, Marktplatz, Status-Seite …) **ohne** persönliche Daten.

## Voraussetzungen
- PHP **8.1 oder neuer** mit `pdo_sqlite` (oder `pdo_mysql`), `curl`, `mbstring`, `openssl`, `sodium`, `zip`, `gd` (für WebP-Bilder)
- Apache (mit `.htaccess`) – z. B. Plesk, cPanel, eigener Server. Bei nginx die Regeln aus `.htaccess` übertragen (Ordner `data/`, `inc/`, `tools/` sperren!)
- HTTPS (Let's Encrypt)

## Installation
1. ZIP entpacken und den Inhalt von `ghostnode/` in das Web-Verzeichnis kopieren.
2. Schreibrechte für den Webserver auf `data/` und `uploads/`.
3. Seite im Browser öffnen → `admin.php` → erstes Konto anlegen (wird Besitzer-Konto).
4. Datenbank: Standard ist **SQLite** (nichts einzurichten). MySQL/MariaDB: Admin → Datenbank.
5. Aufgaben: Admin → System → Aufgaben. Am besten echten Cron einrichten: `*/15 * * * * php /pfad/cron.php`.

## Danach einrichten (alles optional)
- **Discord-Anmeldung / Bot**: Admin → Discord (Client-ID & Secret aus dem Discord-Entwicklerportal – nur dort eintragen, nie in Dateien).
- **Status-Seite**: läuft automatisch; eigene Dienste über die Einstellung `status_targets`, Meldungen über Status-Webhook / E-Mail (Admin → System).
- **Backups**: Aufgabe „Automatisches Backup“ einschalten; Wiederherstellen unter Admin → System (nur Besitzer-Konto).
- **Plugin- & Themes-Marktplatz**: der Signaturschlüssel wird beim ersten Aufruf erzeugt (`data/market/sign.key`) – mitsichern!

## Wichtig
- Ordner `data/` enthält Datenbank, Schlüssel und Einstellungen – **nie** öffentlich machen, regelmäßig sichern (`data/keys` getrennt aufbewahren).
- Updates: neues Paket entpacken und **ohne** `data/` und `uploads/` drüberkopieren.
- VRC Nexus (App) zeigt standardmäßig auf ghostnode-labs.dev – für eine eigene Instanz in der App unter Einstellungen → Dashboard die eigene Adresse eintragen.
