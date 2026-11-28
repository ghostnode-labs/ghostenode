# Update: VIP-Listen für VRChat-Welten (VIP-Tafel, Ko-fi, Gist) – Notizen für den Koordinator

## Funktionen (DE, kurz)
- **Admin → ⭐ VIP-Listen** (Recht `vip.manage`, neu; standardmäßig nur Administratoren; Herkunftsprüfung wie alle Admin-Reiter über `guard_post`):
  beliebig viele Listen (je Welt z. B. „VIP“ und „Ko-fi-Unterstützer“) mit Kurzadresse, Welt, Standardgruppe; Einträge hinzufügen/bearbeiten/entfernen,
  Gruppe, interne Notiz, optionales Ablaufdatum, „wartet auf Prüfung“ → „Freigeben“. Je Liste Adressen zum Kopieren (Gist, direkt, nur Namen) und „Text kopieren“.
  Masonry-Raster (`.grid2` mit `.panel`), Eintragstabelle über die volle Breite, keine max-width.
- **Öffentlicher Text-Endpunkt** `vip.php?l=<slug>`: `GRUPPE|Anzeigename` je Zeile (+ zwei `#`-Kopfzeilen), `&fmt=names` = nur Namen (eine Zeile je Name, z. B. für die `Url` einer Keypad-Rolle),
  `&g=VIP` = nur eine Gruppe. Nur Anzeigename + Gruppe aktiver Einträge (freigegeben, nicht abgelaufen) – keine Notizen, keine Quelle, kein Ablaufdatum.
  `Cache-Control: public, max-age=60`, ETag/304, Rate-Limit 60/min je IP (429), 404/405.
- **Ko-fi-Webhook** `kofi.php`: POST mit Formularfeld `data` (JSON), `verification_token` per `hash_equals` gegen `GN_KOFI_TOKEN` (Server-Secret) bzw. die im Admin
  verschlüsselt (AES-256-GCM, `crypto_encrypt('vip')`) gespeicherte Einstellung – nie im Code. Ohne Token 503, falsches Token 401 (+ Sicherheits-Protokoll), sonst immer 200
  (auch Duplikat/ignorierte Art, damit Ko-fi nicht endlos wiederholt). Name: `vrc: Name` / `VRChat = Name` in der Nachricht, sonst `from_name`.
  Abo (`type` Subscription / `is_subscription_payment`): jede Zahlung verlängert um X Tage (Standard 35) ab dem späteren von heute/bisherigem Ablauf;
  Einzel-Unterstützung: dauerhaft oder X Tage (einstellbar). Arten wählbar (Donation, Subscription, Shop Order, Commission). „Erst prüfen“-Modus;
  private Unterstützungen (`is_public` = false) warten immer auf Prüfung. Duplikate über SHA-256 der `message_id` (Tabelle `vip_kofi_seen`, 180 Tage).
  Gespeichert werden nur Anzeigename, Gruppe, Notiz „Ko-fi Abo · Stufe · Datum“ – **keine E-Mail, kein Betrag, keine Nachricht, keine Transaktions-ID, kein Ko-fi-Name, wenn ein VRChat-Name angegeben ist**.
  Bei einem Fehler nach der Duplikat-Sperre wird die Sperre zurückgenommen, damit Ko-fi erneut zustellen kann.
- **Gist-Spiegel**: schreibt je Liste `<slug>.txt` und `<slug>.names.txt` in ein geheimes GitHub-Gist (legt es beim ersten Mal an: `POST /gists`, danach `PATCH /gists/{id}`,
  Dateien gelöschter Listen werden entfernt). Token nur `GN_GIST_TOKEN` (Server-Secret) oder verschlüsselt im Admin. Bei jeder Änderung (gedrosselt: höchstens 1× pro Minute)
  und per Aufgabenplaner (neue Aufgabe „VIP-Listen → Gist“, alle 15 min; Hash-Vergleich → schreibt nur bei Änderung, auch wenn Einträge ablaufen).
  Raw-Adresse `https://gist.githubusercontent.com/<user>/<id>/raw/<datei>` im Admin zum Kopieren. Ohne Token: Hinweis + „Text kopieren“ fürs Gist von Hand.
- **Root-Skript**: `GN_KOFI_TOKEN` und `GN_GIST_TOKEN` in `SECRET_VARS`, Abfrage bei `install` (optional), `ghostnode secrets`, PHP-FPM-Pool-Umgebung und `web.env` (Cron) – `ghostnode update` übernimmt sie.
- **Unity/VRChat** (App-Repo `apps/unity-tools`): UdonSharp-Tafel `NexusVipBoard`, Editor-Generator für das Prefab, `GhostNode-VIP-Board.unitypackage` als Release-Anhang – siehe unten.

## Einrichtung Schritt für Schritt (für die Besitzerin)
1. **Webseite aktualisieren** (`ghostnode update` bzw. in Plesk „Pull ausführen“). Danach gibt es in der Admin-Zentrale unter „Inhalte“ den Reiter **⭐ VIP-Listen**.
2. **Listen anlegen**: z. B. „VIP“ (Kurzadresse `vip`, Gruppe `VIP`) und „Ko-fi-Unterstützer“ (Kurzadresse `kofi`, Gruppe `KOFI`, Haken „neue Ko-fi-Unterstützer landen in dieser Liste“).
   Einträge mit dem **VRChat-Anzeigenamen** hinzufügen (Groß/Klein egal), optional Ablaufdatum und Notiz.
   Tipp: Wenn VIP und Ko-fi auf derselben Tafel stehen sollen, reicht auch **eine** Liste mit beiden Gruppen (`VIP|…` und `KOFI|…`); die Tafel trennt die Bereiche nach Gruppe.
3. **Ko-fi-Webhook**: auf ko-fi.com → *Settings → API* (bzw. *More → API → Webhooks*):
   - „Webhook URL“ = die Adresse aus Admin → VIP-Listen → „💖 Ko-fi-Webhook“ (z. B. `https://ghostnode-labs.dev/kofi.php`) → speichern.
   - Den dort angezeigten **Verification Token** auf dem Server eintragen: `ghostnode secrets` → `GN_KOFI_TOKEN` (empfohlen) – oder im Admin im Feld „Verification Token“ (wird verschlüsselt gespeichert).
   - In Ko-fi „Send Test“ drücken → im Admin erscheint ein Eintrag (Testname von Ko-fi; danach wieder löschen).
   - Unterstützer sollen in die Ko-fi-Nachricht `vrc: IhrVRChatName` schreiben, sonst wird der Ko-fi-Name genommen. Private Unterstützungen warten auf „Freigeben“.
4. **Gist-Token** (damit die Welt die vertrauenswürdige Gist-Adresse nutzen kann):
   - github.com → *Settings → Developer settings → Personal access tokens → Fine-grained tokens* → neues Token, **nur** Account-Recht „Gists: Read and write“ (oder klassisches Token nur mit Haken „gist“).
   - Auf dem Server `ghostnode secrets` → `GN_GIST_TOKEN` (empfohlen) – oder im Admin unter „🐙 Gist-Spiegel“.
   - Im Admin „Gist-Spiegel an“ anhaken → speichern → „🔄 Gist jetzt abgleichen“. Danach stehen bei jeder Liste die Gist-Adressen mit „⧉ Kopieren“.
   - Ohne Token: auf gist.github.com ein (geheimes) Gist anlegen, Dateiname z. B. `vip.txt`, Inhalt mit „⧉ Text kopieren“ aus dem Admin einfügen, oben „Raw“ öffnen und die Adresse **ohne** die lange Commit-Kennung nehmen
     (`https://gist.githubusercontent.com/<name>/<gist-id>/raw/vip.txt`). Nach Änderungen den Text erneut einfügen.
5. **Unity** (Unity 2022.3.22f1, VRChat Worlds SDK über die Creator Companion, TextMeshPro):
   - `GhostNode-VIP-Board.unitypackage` vom VRC-Nexus-Release laden → *Assets → Import Package → Custom Package…* → alles importieren.
   - *Tools → GhostNode → VIP-Tafel erstellen*: Überschrift „VIP“, Zahl der Namensfelder/Spalten, ggf. Ko-fi-Bereich, **URL 1** = Gist-Adresse (aus dem Admin), **URL 2** = direkte Adresse `…/vip.php?l=vip` (Rückfall, nur mit „Untrusted URLs“).
     „Prefab erstellen + in Szene einfügen“. Beim ersten Mal legt das Werkzeug die UdonSharp-Programmdatei an – falls Unity dann kompiliert, den Menüpunkt einfach noch einmal ausführen.
   - Die bestehende Tafel aus dem Screenshot weiterverwenden geht auch: `NexusVipBoard` auf ein Objekt legen und die vorhandenen TextMeshPro-Felder „Placeholder“ in `mainFields` ziehen.
6. **Security Keypad (ValenVRC, „ValenSecurityPad“) verbinden** – Doku des Pads: docs.valenvrc.com → Security Keypad:
   - **Empfohlen:** an der Rolle „VIP“ des Pads (KeyPadRol) ins Feld **`Url`** die Nur-Namen-Adresse eintragen: Gist-Datei `<slug>.names.txt` (aus dem Admin, „Nur Namen“).
     Am Keypad muss `EncryptRemoteStrings` dafür **aus** sein. Das Pad meldet die Spieler dann selbst an (mit seinem Spoof-Schutz).
   - **Alternativ (Brücke):** an der Tafel `bridgeTargets` = das UdonBehaviour der VIP-Rolle, `bridgeVariable` = `UserList`, `bridgeGroups` = `VIP`. Ob das Pad die Liste nach dem Start neu prüft, ist nicht dokumentiert → im Play-Modus/ClientSim testen.
   - Eigene Effekte (Tür, Licht): `hideForVip` / `showForVip` oder Event `OnLocalPlayerIsVip` an eigene Skripte.
   - Hinweis: Alles läuft lokal je Spieler und erkennt über den Anzeigenamen (kann sich ändern). Für Admin/Staff keine Fern-Listen nutzen (rät auch die Pad-Doku).

## English changelog text
- **Website: VIP lists for VRChat worlds** – new admin section *VIP-Listen* (permission `vip.manage`): any number of lists per world with groups, notes and optional expiry;
  public plain-text endpoint `vip.php?l=<list>` (`GROUP|Name`, or names only with `&fmt=names`; cached, rate-limited, no personal data besides display names);
  **Ko-fi webhook** (`kofi.php`, verification token from a server secret or encrypted admin setting) adds new supporters automatically, renews subscriptions,
  detects duplicate deliveries, optional review mode, never stores e-mail, amount or message; optional **GitHub Gist mirror** so worlds can load the list from a trusted VRChat domain.
- **Unity: GhostNode VIP board** – `GhostNode-VIP-Board.unitypackage` (attached to the release): UdonSharp board that loads the list via VRChat String Loading (Gist first, website as fallback),
  pages through any number of TextMeshPro fields, separate Ko-fi area, `OnLocalPlayerIsVip` event and a configurable bridge to other systems (e.g. the VIP role of a keypad);
  menu *Tools ▸ GhostNode ▸ VIP-Tafel erstellen* generates the neon prefab in your project.

(NSFW-Text: keiner.)

## Geänderte Dateien (Webseite, ghostnode-labs/ghostenode)
- neu: `inc/modules/vip.php` (Logik, Schema „vip_schema“ Migration 1: `vip_lists`, `vip_entries`, `vip_kofi_seen` in der Site-Datenbank), `inc/admin/vip_tab.php`, `vip.php`, `kofi.php`,
  `tools/tests/vip_test.php`, `docs/update-vip.md`
- geändert: `admin.php` (Reiter, Aktionen mit `vip.manage`, Menügruppe „Inhalte“), `inc/core/site.php` (Recht `vip.manage`), `inc/core/tasks.php` (Aufgabe `vipgist`),
  `inc/core/pages.php` (`vip.php`/`kofi.php` in `PAGE_IGNORE`), `inc/core/sitenav.php` (kein Menü in den Endpunkten), `server/ghostnode-root.sh` (Secrets `GN_KOFI_TOKEN`, `GN_GIST_TOKEN`), `tools/tests/README.md`
- Keine neuen Bibliotheken (nur PHP-Bordmittel: PDO, cURL, OpenSSL). Externe Dienste: Ko-fi-Webhook (eingehend), GitHub-REST-API `api.github.com/gists` (API-Version 2022-11-28).

## App-Repo (ghostnode-labs/vrc_nexus)
- neu: `apps/unity-tools/World/NexusVipBoard.cs`, `apps/unity-tools/World/Editor/NexusVipBoardBuilder.cs`, `apps/unity-tools/World/VipBoard-README.md`, `apps/unity-tools/World/vip-example.txt`,
  `apps/unity-tools/tools/pack-unitypackage.js`, `apps/unity-tools/tools/test-unitypackage.js`, `apps/unity-tools/.gitignore` (`dist/`)
- geändert: `apps/unity-tools/README.md` (Abschnitt „VIP board“), `.github/workflows/vrc-nexus-app.yml` (Job `unity-package`: bauen, prüfen, Artefakt + Release-Anhang; Pfad `apps/unity-tools/**` löst den Workflow aus)

## Tests
- `php tools/tests/vip_test.php` 56/56 (SQLite), `php my.php tools/tests/vip_test.php` 56/56 (MariaDB, auch zweiter Lauf auf derselben DB).
- Bestehende Tests weiter grün: site9 55/55 (SQLite + MySQL), sdadult 19/19, nexuslic 29/29, nexuschat 21/21 (je SQLite + MySQL).
- Ansicht geprüft (Playwright, 1920 und 420 px): Admin → VIP-Listen, Raster lückenlos, Inhalt volle Breite.
- Unity-Teil: `node apps/unity-tools/tools/test-unitypackage.js` 9/9; C#-Syntax/Typen mit `mcs` gegen eigene Minimal-Stubs geprüft (nur Wegwerf-Prüfung, nicht im Repo), Listen-Logik (Parsen, Gruppen, Blättern, Rückfall-URL, lokaler Spieler, Brücke) mit Mono gegen Stubs ausgeführt – kein echtes Unity.

## Offene Punkte / nur in Unity bzw. VRChat prüfbar
- Kompilieren in Unity 2022.3.22f1 mit dem aktuellen Worlds SDK (UdonSharp-Compiler: alle verwendeten APIs sollten freigegeben sein – `string.ToLowerInvariant`, `IndexOf`, `Substring`, `UdonBehaviour.SetProgramVariable/GetProgramVariable`, `TextMeshProUGUI`, `UnityEngine.UI.Text`).
- Generator: Programmdatei-Anlage, Prefab-Speichern, Neon-Glow-Material (TextMeshPro/Distance Field), Aussehen im Vergleich zum Screenshot.
- String Loading vom Gist in einem Test-Build, Rückfall auf die direkte URL, Blättern, `OnLocalPlayerIsVip` in ClientSim.
- Security Keypad: welches Listenformat die `Url` der Rolle erwartet (Doku nennt es nicht; wir liefern einen Namen je Zeile) und ob die Brücke (`UserList`) nach dem Start noch wirkt.
- Ko-fi: echte Testzustellung („Send Test“) nach dem Eintragen; die offizielle Ko-fi-Hilfeseite blockt automatische Abrufe (403) – Felder abgeglichen mit der öffentlichen Beispiel-Nutzlast (verification_token, message_id, type, is_public, from_name, message, is_subscription_payment, tier_name …).
- Changelog/README/Version: macht der Koordinator. Der Unity-Workflow-Pfad löst bei reinen Unity-Änderungen auch den App-Build (gleiche Version = Dateien ersetzt) aus.
