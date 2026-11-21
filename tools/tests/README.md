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
