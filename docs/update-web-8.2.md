# Web-Nexus – Notizen zum Update „VRC Nexus 8.2“ (Webseite ghostnode-labs.dev)

Branch `upd/web`. Version, README und Changelog sind **nicht** angefasst (macht der Koordinator).

## Neue Funktionen (Web-Nexus, `nexus.php`)

Neue Menügruppe **💠 Mein Nexus** (unter „Spiel und Mehr“):

1. **🔋 Geräte & Uhr** – Uhr, Tracker, Controller, Headset, Pulsmesser mit Akkustand, Lade-Blitz und „getrennt“, Warnung unter 20 %; dazu die PCs mit Desktop-Client (online/zuletzt gesehen) und die mit dem Nexus-Konto angemeldeten App-Geräte (einzeln abmelden). Spielzeuge erscheinen nur mit 18+-Freigabe.
2. **💓 Puls-Verlauf** – Diagramm 24 h / 7 Tage / 30 Tage (Spanne min–max + Durchschnitt), aktueller Puls, Ruhepuls (ca.), Ø, Höchst-/Tiefstwert, Zeit in Zonen (ruhig/leicht/aktiv/hoch), CSV-Export. Aufbewahrung 30 Tage, höchstens ein Wert je 20 s, nur für die Person selbst.
3. **📆 Wochenrückblick** – je Kalenderwoche (vor/zurück bis 52 Wochen): Spielzeit, Welten, Leute getroffen und davon zum ersten Mal, Screenshots, Avatar-Wechsel, zugesagte Club-Events, Community-Beiträge, Puls Ø/max, aktivster Tag, Top-5-Welten nach Zeit; Vergleich zur Vorwoche (▲/▼).
4. **🗺 Welt-Wunschliste** – „will ich besuchen“ mit Priorität (irgendwann/gern/unbedingt), Schlagwort, Notiz; Filter offen/besucht und nach Schlagwort; 🎲 Zufallswelt (höhere Priorität öfter); wird automatisch abgehakt, sobald der Desktop-Client einen Besuch meldet; Knopf „🗺 Wunschliste“ in jeder Welt-Ansicht.
5. **🏅 Profil-Abzeichen & Vollständigkeit** – im Community-Profil automatisch berechnete Abzeichen (Neu dabei / x Jahre dabei, Erzähler:in / Vielschreiber:in, Hilfsbereit, Beliebt / Bekannt, Club-Gründer:in, Event-Gänger:in – nur SFW-Daten); im eigenen Profil „Profil zu x % vollständig“ mit fehlenden Angaben.
6. **🔔 Push-Erinnerungen** – neu in den Push-Einstellungen: 🏠 zugesagte Club-Events (gleiche Vorlaufzeit wie Gruppen-Events, 18+-Clubs nur mit Freigabe) und 🔋 schwacher Akku (je Gerät einmal, bis es wieder geladen wurde).

## 🔞 NSFW-Funktionen (nur Verified 18+ mit NSFW an, ❤️ 18+ / ERP → 🎮 Spielzeug)

1. **Spielzeug-Fernbedienung im Browser** – eigene Spielzeuge über VRC Nexus (Desktop): Stärke-Regler (Lebenszeichen alle 5 s, Stopp beim Verlassen/Ausblenden der Seite), Muster mit Stärke/Dauer/Tempo, Überraschung, Verbindungstest.
2. **Partner-Links** – nur für Verbindungen (beidseitig angenommene 18+-Anfrage) und nur für diese eine Person: Ablaufzeit (5 min – 12 h), Intensitäts-Obergrenze (5–100 %, gilt für Regler und Muster; Muster-Dauer nie über die Restzeit), erlaubte Muster, Überraschung erst ab 90 %, Pausieren/Fortsetzen (stoppt), Beenden durch beide Seiten (stoppt), Link kopieren (`nexus.php?toylink=<id>`).
3. **Consent vor dem ersten Befehl** – Grenzen und Safeword der Besitzerin/des Besitzers stehen am Link; das Gegenüber muss sie bestätigen, bevor es steuern kann. Verliert eine Seite die 18+-Freigabe, ist Steuern sofort gesperrt.
4. **🛑 Not-Stopp & Protokoll** – roter Not-Stopp unten immer sichtbar (auch Esc / Umschalt+P), funktioniert auch bei pausierten/abgelaufenen/beendeten Links und wird nie gebremst. Protokoll je Link nur für die beiden Beteiligten (fremde Links: „nicht gefunden“), löschen nur durch die Besitzerin/den Besitzer, automatische Löschung nach 30 Tagen.

Sicherheit: jede `nxp_toy_*`-Anfrage prüft serverseitig Verified 18+ + NSFW (`nc_need_nsfw`), bei Links zusätzlich das Besitzer-Konto; Befehlsbremse 90/min je Konto (außer Stopp). Die App prüft wie immer selbst (Freigabeliste, eigene Höchststärke, Verified 18+ des Absenders, Nonce, 60-s-Gültigkeit) – nichts davon wird umgangen; Befehle tragen die echte VRChat-ID des Absenders.

## Changelog (English, for README / CHANGELOG)

### New in 8.2 – Web Nexus
- **My Nexus** menu in Web Nexus:
  - **Devices & watch** – watch, trackers, controllers and headset with battery level and charging state (reported by the desktop app), low-battery warning, desktop-client PCs and signed-in Nexus devices (sign out individually).
  - **Heart-rate history** – 24 h / 7 days / 30 days chart (min–max band and average), current and resting estimate, zones, CSV export; kept for 30 days and visible only to you.
  - **Weekly recap** – playtime, worlds, people met (and first-time meetings), screenshots, avatar changes, club events, posts, heart rate, busiest day and top worlds, compared with the previous week.
  - **World wishlist** – plan worlds with priority, tag and note, random pick, automatically ticked off once the desktop app reports a visit; “Wishlist” button in every world view.
- **Profile badges & completeness** in the community profile.
- **Push reminders** for club events you RSVP’d to and for low battery on your devices.
- 🔞 ***

## NSFW-Changelog (nur für CHANGELOG-NSFW)

### 8.2 – Web Nexus
- Toy control in the browser (Web Nexus → 18+ / ERP → Toys): own toys via VRC Nexus desktop with slider, patterns, surprise and connection test.
- Partner links: bound to one connection, expiry (5 min – 12 h), intensity cap, allowed patterns, surprise only from 90 %, pause/resume and end by either side (both stop the toy).
- Consent first: the partner must confirm the owner’s limits and safeword before the first command; control is blocked as soon as either side loses Verified 18+ / NSFW.
- Emergency stop always visible (also Esc / Shift+P), never rate-limited, works on paused/expired/ended links; stop on leaving the page.
- Per-link log visible only to the two people involved, deletable by the owner, auto-deleted after 30 days.

## Geänderte / neue Dateien

Neu:
- `inc/modules/nexusplus.php` – alle neuen Funktionen (Aktionen `nxp_*`)
- `inc/modules/toybox.php` – gemeinsamer Briefkasten-Speicher für `toyrelay.php` und Web-Nexus
- `assets/js/nexus-plus.js` – Mein Nexus (Geräte, Puls, Wochenrückblick, Wunschliste) + Abzeichen
- `assets/js/nexus-toyweb.js` – 🔞 Spielzeug im Browser, Partner-Links, Not-Stopp, Protokoll
- `docs/update-web-8.2.md` – diese Datei

Geändert:
- `nexus.php` – Menügruppe „💠 Mein Nexus“, Bereich `mynx`, Verteiler `nxp_*`, `?heart_csv=1`, Push-Optionen, neue Skripte
- `client.php` – `state` nimmt `heart`, `heart_src`, `devices[]`; neu `POST client.php?a=heart` (gepufferte Pulswerte)
- `toyrelay.php` – nutzt `inc/modules/toybox.php` (Verhalten unverändert)
- `inc/modules/vrchat.php` – Migration 20
- `inc/modules/nexus_client.php` – Puls/Geräte im Client-Zustand, Puls in den Verlauf
- `inc/modules/nexus_push.php` – Optionen `clubs`, `battery`
- `assets/js/nexus.js` – Reiter-Hook `NexusPlus`, Knopf „🗺 Wunschliste“ in der Welt-Ansicht, Push-Optionen
- `assets/js/nexus-web.js` – 18+-Unterpunkt „🎮 Spielzeug“, `?toylink=`, Platzhalter für Abzeichen im Profil
- `assets/css/nexus-web.css` – Stile `nxp-*`

Nicht angefasst: `dashboard.php`, `inc/core/sitenav.php`, `inc/core/widgets.php`, `widgets.php`, gemeinsame Seiten-CSS.

## Migrationen

Modul `vrchat` (`vrc_migrate`, SQLite-Datei `vrcx_*`, MySQL-Präfix `nx_`), **user_version 19 → 20**:
- `web_heart (user_id, ts, bpm)` – Puls-Verlauf
- `web_wish (id, user_id, world_id, name, image, author, note, prio, tag, created, visited)` – Welt-Wunschliste, UNIQUE (user_id, world_id)
- `toy_links (id, owner, partner, cap, patterns, surprise, limits, safeword, status, accepted, created, expires, ended, last_used, last_lv)` + Indizes
- `toy_log (id, link_id, owner, actor, ts, cmd, detail)` + Indizes
- `toy_rate (vrc_id, minute, n)` – Befehlsbremse

Keine neuen Pakete. Keine neuen Rechte, keine Einstellungen nötig.

## Schnittstelle für die App (Desktop) – bitte im App-Paket umsetzen

- `POST client.php?a=state` zusätzlich: `heart` (bpm, 25–250), `heart_src` (z. B. „HypeRate“, „Pulsoid“, „Uhr“), `devices: [{kind: watch|phone|tracker|controller|headset|heart|toy|other, name, battery (0–100|null), charging, connected, via}]` (max. 24).
- Optional `POST client.php?a=heart {"samples":[{ts, bpm}, …]}` für gepufferte Werte (z. B. nach Verbindungsabbruch).
- Spielzeug: keine App-Änderung nötig – Web-Befehle kommen im bestehenden Format v1 über den Briefkasten (`toyrelay.php`). Prozentwerte des Reglers sind wie bisher Anteile der in der App eingestellten Höchststärke.

## Tests / Probeläufe

- `php -l` für alle geänderten PHP-Dateien, `node --check` für alle geänderten JS-Dateien: ohne Fehler.
- Probelauf mit Mock-Daten (59 Prüfungen: Puls, Geräte, Push-Zusätze, Wochenrückblick, Wunschliste, Abzeichen, Partner-Links inkl. Obergrenze/Muster/Consent/Pause/Ablauf/Not-Stopp/Bremse/Protokoll-Rechte): **SQLite 59/59, MySQL (MariaDB) 59/59**.
- Ende-zu-Ende über `php -S`: `toyrelay.php` poll/send/fremder Briefkasten wie vorher; Web-Befehl landet im Briefkasten und wird abgeholt.
- App-Gegenprobe (Mock, `backend/toyremote.js`): Web-Befehl wird angenommen (Stärke auf App-Höchststärke skaliert), Absender ohne Freigabeliste wird abgelehnt.
- Oberfläche mit Playwright (lokal, VRChat-Aufrufe blockiert): alle vier Mein-Nexus-Bereiche, Profil-Abzeichen, Partner-Link-Aufruf, Consent, Regler, Not-Stopp – keine JS-Fehler.

## Offene Punkte

- Geräte/Puls erscheinen erst, wenn die Desktop-App die neuen Felder schickt (siehe Schnittstelle oben).
- Partner-Links: das Gegenüber braucht Zugang zu Web-Nexus (Webseiten-Konto mit „VRC Nexus“-Recht und verbundenes VRChat) und muss in der App der Besitzerin/des Besitzers auf der Freigabeliste stehen. Ist die App per Live-Dienst verbunden, leert sie den Briefkasten nur alle 20 s – Web-Befehle können dann bis zu 20 s brauchen (Verbesserung: Live-Dienst per `/notify` anstoßen; braucht eine kleine Erweiterung von `apps/nexus-live`).
- Der Not-Stopp-Knopf erscheint, sobald „🎮 Spielzeug“ einmal geöffnet wurde, und bleibt bis zum Neuladen auf allen Reitern sichtbar.
- Neue UI-Texte sind deutsch; die automatische Seiten-Übersetzung (site-dict) kennt sie noch nicht.
