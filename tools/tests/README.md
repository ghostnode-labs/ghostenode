# Tests für die Nexus-Schnittstellen (nexusacct.php, nexuscomm.php)

Nur mit Testdaten – kein echtes VRChat-Konto. Ablauf (Node 22 + PHP 8 mit sodium und pdo_sqlite):

```bash
# 1. VRChat-Mock aus dem App-Repo (ghostnode-labs/vrc_nexus): apps/vrc-nexus/tests/ui/mock-vrchat.js
node mock-vrchat.js 8792 &
# 2. Testkopie der Webseite einrichten (Dienst-Konto zeigt auf den Mock) – NIE auf dem echten Server ausführen
php tools/tests/nexus_test_setup.php
# 3. Test-Schlüssel erzeugen und Server mit zusätzlichem Test-Schlüssel starten
node tools/tests/nexus_test_keys.js > /tmp/nexus-keys.json
NEXUS_WARN_TEST_PUBKEY=$(node -e "console.log(require('/tmp/nexus-keys.json').pub)") php -S 127.0.0.1:8902 &
# 4. Test (erwartet keys.json im angegebenen Ordner)
cp /tmp/nexus-keys.json /tmp/keys.json && node tools/tests/nexus_community_test.js /tmp
```

## VRChat-Poster (poster.php, p.php)

Nur in einer **Testkopie** der Webseite ausführen (legt Testrollen/-benutzer an und schreibt `assets/poster-test-t3.png`). Braucht PHP 8 mit GD, pdo_sqlite und cURL sowie Node 22 mit Playwright.

```bash
php tools/tests/poster_test_setup.php
PHP_CLI_SERVER_WORKERS=4 PS_ALLOW_LOCAL=1 PS_FTP_NOW=1 php -S 127.0.0.1:8910 &
node tools/tests/poster_test.js        # optional: SHOT_DIR=/ordner für Screenshots
```

`PS_ALLOW_LOCAL=1` erlaubt Bildlinks auf 127.0.0.1 (nur Test), `PS_FTP_NOW=1` übernimmt FTP-Dateien ohne 20-s-Wartezeit. Geprüft werden u. a. Rechte (Gast, ohne Recht, Ansehen, Hochladen, Bearbeiten, Verwalten, Freigaben je Slot und je Bild), Upload mit Duplikat-Erkennung, Poster-URL inkl. Zielgröße, URL-Änderung, Bildlink, FTP-Eingang, Token-Schnittstelle, Rotation/Zeitplan und Schutz gegen fremde Herkunft.

## Webseite 9.0 (Dashboard, neue Widgets, Prozessanzeige, Releases)

Reine PHP-Tests mit Mocks (Mock-`/proc`, Mock-GitHub), legen einen Wegwerf-Datenordner an – kein Netz, keine echten Konten:

```bash
php tools/tests/site9_test.php                  # SQLite
php my.php tools/tests/site9_test.php           # gleiche Tests gegen ein lokales Test-MySQL ($GLOBALS['__db_cfg'] in my.php setzen)
```

## Nexus-Privat-Chat (nexuschat.php, Protokoll-Aushandlung nexus-v1 / signal-v1)

Reine PHP-Tests mit Testschlüsseln (keine echten Konten); starten kurz einen eingebauten PHP-Server auf einem freien Port:

```bash
php tools/tests/nexuschat_test.php              # SQLite in einem Wegwerf-Ordner
php my.php tools/tests/nexuschat_test.php       # gleiche Tests gegen ein lokales Test-MySQL ($GLOBALS['__db_cfg'] in my.php setzen)
```

Geprüft werden Migration 21 (neue Spalten/Tabellen), alte Clients (ohne `protos`) unverändert, Bindung der libsignal-Identität an `ik_sig`,
Einmal-EC- und Einmal-Kyber-Schlüssel (verbraucht, danach Kyber-Last-Resort), Zähler zum Nachlegen, Rückfall bei fehlerhaftem signal-v1-Teil und Entfernen.

## Nexus-Lizenzen / Tags (nexuslic.php, 8.4: automatische Zustellung über `a=mine`)

Reine PHP-Tests mit einem Wegwerf-Ed25519-Testschlüssel (keine echten Konten, nie der Besitzer-Schlüssel); starten kurz einen eingebauten PHP-Server auf einem freien Port:

```bash
php tools/tests/nexuslic_test.php               # SQLite in einem Wegwerf-Ordner
php my.php tools/tests/nexuslic_test.php        # gleiche Tests gegen ein lokales Test-MySQL ($GLOBALS['__db_cfg'] in my.php setzen)
```

Geprüft werden Migration 2 (Spalte `token` in `lic_keys`), dass beim Abgleich nur echt unterschriebene, passende Schlüssel ohne Moderator-Rolle gespeichert werden,
dass `mine` nur Schlüssel der eigenen VRChat-ID liefert (fremde ID bekommt nichts, auch nicht bei falsch abgelegten Einträgen), 401 bei ungültigem/unbekanntem/gesperrtem Konto-Token,
Entziehen, `sync` nur für die Besitzerin, Profil-Tags ohne Besitzer-Abzeichen aus Schlüsseln und das Rate-Limit.

## Zweites Stream-Deck-Plugin (nexusdeck.php, inc/modules/sdadult.php)

Reine PHP-Tests mit Testkonten (kein echtes VRChat-Konto); starten kurz einen eingebauten PHP-Server, die Anmeldung läuft über einen Haken im Wegwerf-Router:

```bash
php tools/tests/sdadult_test.php                # SQLite in einem Wegwerf-Ordner
php my.php tools/tests/sdadult_test.php         # gleiche Tests gegen ein lokales Test-MySQL ($GLOBALS['__db_cfg'] in my.php setzen)
```

Geprüft werden: Paket aus der Quelle (ZipArchive, eigener ZIP-Schreiber, vorgebautes Paket) ist ein gültiges `.streamDeckPlugin` (ein `*.sdPlugin`-Ordner, Manifest, Bilder in beiden Größen, Neo-Layout),
Sperre der Quelle per `.htaccess`, kein Link auf öffentlichen Seiten, 403 ohne Anmeldung / ohne Nexus-Web-Recht / ohne VRChat / ohne Verified 18+ / mit NSFW aus, 200 + gültiges Paket mit Freigabe,
Zähler ohne Benutzer/IP und das Rate-Limit (429). Die Quelle kommt aus dem App-Repo: `node apps/vrc-nexus/streamdeck-adult/tools/sync-web.js <Pfad zu dieser Arbeitskopie>`.
