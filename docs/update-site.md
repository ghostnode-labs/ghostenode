# Webseiten-Update (Dashboard 2.0 + 21 Funktionen + Prozessanzeige) – Notizen für den Koordinator

Branch `upd/site` (Worktree `/home/user/wt-gn-site`). Version, README, CHANGELOG und changelog.json wurden **nicht** angefasst.
Keine neuen Fremdpakete – nur PHP-Bordmittel und eigenes JavaScript.

## 1. Dashboard neu gestaltet (dashboard.php, inc/core/widgets.php, widgets.php, CSS/JS)

- Volle Breite (bis 1880 px), eigener Look: dunkle Kopfzeile mit Verlauf und Cyan/Gold-Linie.
- **Kopfzeile:** Avatar, Begrüßung nach Tageszeit mit Namen, Datum, KW, Live-Uhr, Rolle; Status-Chips (Online/Wartung, Seiten, Links,
  Dienst-Status aus status.php, Termine der nächsten 24 Std.); Websuche/lokale Suche integriert; Knöpfe Anpassen, Bearbeiten, Baukasten,
  Befehlspalette, NSFW-Schalter, Admin, Abmelden.
- **Kachelraster:** 6/5/4/3/2/1 Spalten je nach Breite (Handy 1 Spalte, 16 px Rand, kein horizontales Scrollen), Mauerwerk-Layout wie bisher.
- **Gruppen-Filter** (Übersicht, Planung, Persönlich, Projekte, Community, System, Freizeit) – Auswahl wird gemerkt.
- **🎛 Anpassen (jede angemeldete Person):** Widgets per Griff ziehen (oder Pfeiltasten), mit 👁 ausblenden, unter „Ausgeblendet“ wieder
  einblenden; Einklappen wird ebenfalls im Konto gespeichert (alle Geräte). Ausgeblendete Widgets werden erst beim Einblenden geladen.
  Gilt zusätzlich zur gemeinsamen Anordnung (widgets.manage) und zum „Baukasten“ (eigenes Dashboard) – beides bleibt unverändert.
- Alle bisherigen Widgets, Seiten-Kacheln, Bearbeiten-Modus, Angeheftet/Zuletzt, Kompakt-Ansicht, Suche bleiben erhalten.

## 2. Neue Funktionen (21)

Widgets (Ordner `widgets/…`):
1. **Release-Status** – neueste Version von VRC Nexus, D2 Nexus und Discord-Bot mit Alter, Downloads, „neu“-Markierung (GitHub-Releases, 30 min Cache).
2. **Dienst-Status** – Ampel aller Dienste mit Antwortzeit, 24-h-Verfügbarkeit und Verlaufsbalken (Daten der Status-Seite; ohne Prüfung nur lokale Dienste).
3. **Download-Statistik** – VRC-Nexus-Downloads gesamt, neueste Version nach Gerät, Vergleich der letzten Versionen.
4. **Marktplatz-Neuheiten** – zuletzt freigegebene Plugins und Themes (mit Farbmustern).
5. **Benachrichtigungen** – die Liste aus „Mein Bereich“ mit Zähler „neu“ und „✓ Alles gelesen“.
6. **Notizbuch (privat)** – mehrere Notizen je Konto, Formatierung, Anheften, Suche.
7. **Lesezeichen (privat)** – Ordner, Filter, Import des Browser-Lesezeichen-Exports (Chrome/Firefox/Edge, nur http/https).
8. **Textbausteine** – Texte mit einem Klick kopieren (privat).
9. **Gewohnheiten** – tägliche Ziele, 7-Tage-Raster, Serie und Bestwert (privat).
10. **Konto-Check** – Sicherheits-Punktzahl (2FA, Passwort-Alter, Geräte, Discord, Anzeigename, Ablauf) mit Links zum Beheben.
11. **Admin-Postfach** – offene Prüfungen im Marktplatz, neue Konten, Sicherheitsereignisse, IP-Sperren, Backup-Alter, Wartung, Dienste, kaputte Links (je nach Recht).
12. **Wer ist online** – aktive Konten der letzten Minuten (Recht users.view).
13. **Letzte Aktivitäten** – Protokoll live, filterbar nach Stufe (Recht audit.view).
14. **Sonne & Mond** – Sonnenauf-/untergang, Tageslänge, Mondphase, nächster Voll-/Neumond (rein rechnerisch).

Seiten & Werkzeuge:
15. **Befehlspalette (Strg+K)** auf dem Dashboard – Seiten, Verwaltungsbereiche (nach Recht), Widgets, Aktionen; unscharfe Suche; „Überall suchen“.
16. **Dashboard-Ansicht exportieren / importieren / zurücksetzen** (JSON; beim Import immer neue Widget-IDs, nie fremde Inhalte).
17. **Downloads-Seite: „Weitere Projekte“** – D2 Nexus und Discord-Bot mit Version, Datum, Dateien, Größe und SHA-256.
18. **Changelog: „Neu seit deinem letzten Besuch“** – Markierung NEU, Hinweisleiste, Filter „Nur neue“ (Konto: serverseitig, Gäste: Browser).
19. **Globale Suche erweitert** – findet Links aus allen Link-Hubs, Verwaltungsbereiche (nur mit Recht) und Widgets.
20. **Benachrichtigungen erweitert** – neue Versionen von D2 Nexus und Discord-Bot sowie Störungen/Wiederherstellung der Dienste (7 Tage).
21. **Vorankündigung geplanter Wartung** – Banner für alle X Stunden vor „Ab“ des Wartungsfensters (Admin → System, Standard 24 h, 0 = aus).

Zusatzauftrag:
22. **Prozessanzeige (htop-artig)** in Admin → 🖥 Server neben „Geplante Aufgaben“: CPU je Kern, Load 1/5/15, RAM/Swap, Laufzeit,
    Prozesse/Threads; Tabelle PID, Benutzer, CPU %, RAM %, RSS, Laufzeit, Befehl – sortierbar, Filter, Top 30, alle 3 s, Pause.
    Live aus /proc (CPU-% aus Differenz zum letzten Abruf je Admin, Datei `data/cache/procs_prev_<id>.json`); Rückfall auf den
    Schnappschuss `procs` des Root-Agents (status.json) mit „Stand: …“. Nur Anzeige, kein Beenden. Passwörter/Token in Befehlszeilen
    werden maskiert. Endpunkt `admin.php?sv_procs=1` (GET, nur system.manage, gleiche Herkunft, nicht in „Ansicht als Rolle“).
    Mobil: Tabelle scrollt im Kasten, die Seite nicht (dabei auch einen alten Überlauf im Server-Tab behoben: lange Auftragszeilen).
    `server/ghostnode-root.sh`: Agent schreibt zusätzlich `procs` (Top 40 nach CPU, 1-s-Messung) – Root-Server braucht dafür einmal `ghostnode-root.sh update`.

## Englischer Changelog-Text

### New in website 9.0 – Dashboard 2.0
- **Redesigned dashboard:** full-width layout with a new header (greeting, date, live clock, role, status chips for services and upcoming events, integrated search).
- **Personal layout:** every signed-in user can reorder, hide and collapse widgets; stored in the account and synced across devices. Export, import and reset included.
- **Widget groups:** filter bar (Overview, Planning, Personal, Projects, Community, System, Fun).
- **Command palette (Ctrl+K):** jump to pages, admin areas and widgets with fuzzy search.
- **14 new widgets:** release status (VRC Nexus, D2 Nexus, Discord bot), service status, download statistics, marketplace news, notifications,
  private notebook, private bookmarks with browser import, text snippets, habit tracker, account security check, admin inbox, who's online,
  recent activity, sun & moon.
- **Downloads:** new "More projects" section for D2 Nexus and the Discord bot with SHA-256 checksums.
- **Changelog:** versions released since your last visit are highlighted, with a "new only" filter.
- **Search:** now also finds links from all link hubs, admin areas and widgets.
- **Notifications:** new D2 Nexus and Discord bot releases plus service outages and recoveries.
- **Maintenance notice:** visitors see a banner ahead of scheduled maintenance (configurable lead time).
- **Admin → Server:** live process viewer in the style of htop (per-core CPU, load, memory, swap, sortable and filterable process list; read-only).

(NSFW-Text: keiner – das Update betrifft keine NSFW-Funktionen.)

## Geänderte / neue Dateien

Geändert: `dashboard.php`, `widgets.php`, `inc/core/widgets.php`, `inc/core/site.php`, `inc/core/security.php`, `admin.php`,
`inc/admin/server_tab.php`, `inc/modules/me.php`, `downloads.php`, `changelog.php`, `search.php`, `market.php` (Anker `#mk-review`),
`assets/js/widgets.js`, `assets/css/widgets.css`, `server/ghostnode-root.sh`, `widgets/README.md`, `tools/tests/README.md`.

Neu: `assets/css/dashboard.css`, `assets/js/dash-ux.js`, `assets/js/sv-procs.js`, `inc/modules/releases.php`, `inc/modules/procs.php`,
`tools/tests/site9_test.php`, `widgets/{releases,services,dlstats,market,notify,notebook,bookmarks,snippets,habits,accountcheck,adminq,online,auditlive,sunmoon}/`,
`docs/update-site.md`.

## Migrationen

- `inc/core/site.php`: `SITE_SCHEMA_VERSION` 2 → **3**: Tabelle **`user_kv`** (user_id → users ON DELETE CASCADE, pkey, value, updated; PK user_id+pkey).
  Schlüssel: `dash` (Dashboard-Ansicht), `ws:<widget-uid>` (private Widget-Inhalte), `cl_seen` (Changelog zuletzt gesehen).
  Name bewusst nicht `user_prefs` – die Tabelle gibt es schon (Mein Bereich, me_schema). Läuft auf SQLite und MySQL (über die Db-Übersetzung).
- Neue Einstellung `maintenance_notice_h` (settings-Tabelle, Standard 24).
- Datenexport (Mein Bereich → Datenschutz) enthält jetzt auch `dashboard` (alle user_kv-Einträge).

## Tests

- `php tools/tests/site9_test.php` → **55/55 ok** (SQLite) und über `my.php` gegen lokales MariaDB → **55/55 ok**.
  Enthält Mock-/proc (Prozess mit Klammern/Leerzeichen im Namen, CPU-%-Differenz, Kerne, RAM, Laufzeit, Maskierung, Sortierung, Filter,
  Rückfall auf Agent-Schnappschuss), Release-Parser mit Mock-GitHub, Lesezeichen-Import, Serien, Konto-Check, Mondphase, Download-Summen,
  Dashboard-Ansicht, user_kv, Wartungs-Vorankündigung.
- Browser-Ende-zu-Ende (Playwright, lokal, nur Testkonten) auf SQLite **und** MySQL: alle 14 Widgets hinzufügen und rendern, Aktionen
  (Notizbuch, Lesezeichen inkl. javascript:-Abwehr und Import, Gewohnheiten, Textbausteine, Benachrichtigungen), persönliche Reihenfolge/
  Ausblenden/Einklappen/Gruppe, Lazy-Load, Export/Import, Befehlspalette, zweites Konto sieht keine privaten Inhalte und kein Admin-Widget,
  Gast darf nichts speichern, Prozess-Endpunkt (Admin ok, Mitglied 403, Gast 403). Skript: Scratchpad `site/e2e.js` (nicht im Repo).
- Zusätzlich `php -l` aller geänderten Dateien, `node --check` aller Skripte, Agent-Python geparst und `procs_snapshot()` lokal ausgeführt.

## Screenshots (nur zur Kontrolle, Scratchpad)

- Vorher: `/tmp/claude-0/-home-user/31e02857-c6ba-52f5-92db-f58542b7414f/scratchpad/site/shots/before-desktop.png`, `…/before-mobile.png`
- Nachher: `…/shots/after-desktop.png` (1600 px), `…/shots/after-desktop-1280.png`, `…/shots/after-mobile.png`, `…/shots/after-mobile-top.png`
- Server-Tab mit Prozessanzeige: `…/shots/server-desktop.png`, `…/shots/server-mobile.png`

## Offene Punkte / Hinweise

- Neue Widgets erscheinen nicht automatisch in der gemeinsamen Anordnung – Admin fügt sie per „＋ Widget“ hinzu (bewusst, damit nichts ungefragt Netz-Abrufe macht).
- Release-Status/Downloads/Benachrichtigungen fragen GitHub (30 min Cache, 8 s Zeitlimit, alter Stand bei Ausfall).
- Prozessanzeige auf Plesk: /proc meist durch open_basedir gesperrt → Hinweis; auf dem Root-Server live bzw. Agent-Schnappschuss nach `ghostnode-root.sh update`.
- Ein paar neue UI-Texte haben noch keine EN-Übersetzung in `assets/i18n/site.tsv` (die Seite übersetzt bekannte Wörter automatisch).
