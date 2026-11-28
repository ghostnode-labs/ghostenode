# Update: Mauerwerk-Raster (Masonry) + volle Breite auf allen Seiten – Notizen für den Koordinator

## Was (DE, kurz)
- **Zentral für alle Seiten** über `inc/core/sitenav.php` (`a11y_fix`, läuft für jede HTML-Seite inkl. Landing-Page): am Ende von `<body>` werden `assets/css/masonry.css` und `assets/js/masonry.js` (mit `?v=filemtime`) eingehängt – keine Seite muss einzeln angepasst werden.
- **Mauerwerk-Raster** (`assets/js/masonry.js`, eigene Umsetzung ohne Abhängigkeit): 4-px-Zeilen + `grid-row-end: span N` je Karte, ResizeObserver/MutationObserver, Reihenfolge bleibt (nur bei Karten über mehrere Spalten dürfen spätere Karten die Lücke darüber füllen), einspaltig unverändert.
  Raster: `.grid, .grid2, .grid3, .roles, .tpl-info, .sv-grid, .cards, .bd-grid, .bd-goals, .bd-2col, .cn-grid2, .cn-calcs, .cn-packs, .dc-cols, .dc-members, .fh-grid, .com-grid, .gloss, .paws, .toys, .ld-feat, .ld-proj, .ld-news, .ld-links, .nw-grid, .nxp-wishes, .nxp-devs, .vx-inst, .vx-cols, .vx-u-cols, .vx-groups, .vx-fgrid, .nc-grid, .sh-grid, .ns-cols, .cnt-grid, .ps-grid, .dl-grid, .rm-items` + automatisch jedes mehrspaltige Grid mit `.panel/.card/.nc-card/.nw-card/.fh-card`-Kindern.
  **Dashboard-Widgets (`.wg-grid`) ausgenommen**: haben schon ihr eigenes Mauerwerk (widgets.js) mit gespeicherter Reihenfolge und Drag & Drop – unverändert. Ebenso ausgenommen: Kalender (`.mc-grid`, Monats-/Wochenraster), Seitengerüste Inhalt + Seitenleiste (`.shell`, `.cal-layout`, `.fd-app`, `*-layout` …), Kennzahl-/Knopf-Kacheln (`*-kpis`, `.ld-stats`, `.rm-stats`, `.quick-btns`), Formular-Raster (`.f2`, `.ps-form`, `.cl-form`, `.bd-addgrid` …).
- **Volle Breite** (`masonry.css`, nach den Seiten-Styles): `#main` (Inhaltsbereich jeder Seite, inkl. Admin `.main`), `.wrapper`, `.ap-wrap`, `.cal-wrapper`, Landing-Abschnitte, Formulare in Marktplatz/Themes/Seiten → `max-width: none`. Feste 2/3-Spalten-Raster mit nur einer Karte: Karte über die volle Breite (z. B. Admin → System „Registrierung“).
  Bewusst schmal (Dialoge/Anmelde-Kästen): Modals (`.modal-box`, `.pm-box`, `.cmd-box`), Anmelde-/Freigabe-Kasten `oauth.php` (`.oa-box`), Login-/Altersgate (`.gate`, `.ns-gate`); Fließtext-Einleitungen der Landing-Page (`.ld-head`, 760 px) und Artikeltext im Feed (`.fd-content`) behalten die Zeilenlänge.

## English changelog text
- **Website: masonry layout and full width on every page** – card grids no longer leave empty gaps under shorter cards, and all pages use the full width (dialogs stay compact). Dashboard widgets keep their own saved order and drag & drop.

## Geänderte Dateien
- `inc/core/sitenav.php` (Einbindung in `a11y_fix`), `assets/js/masonry.js` (neu), `assets/css/masonry.css` (neu). Keine DB-/Schema-Änderung (SQLite/MySQL nicht betroffen). Tests: `php tools/tests/site9_test.php` 55/55 (SQLite; lokales MySQL lief nicht – Änderung ist reine Ausgabe/CSS/JS).

## Geprüfte Seiten (Playwright, `php -S 127.0.0.1:8099`, Wegwerf-Datenordner + Testkonto admin, VRChat-Mock für Web-Nexus)
255 Ansichten × 1366 / 1920 / 420 px: index, dashboard, 1linkseite, 2VRchat, 3hueter-Zentrale, Roadmap, api, botdash, budget, calendar, changelog, con, countdown, d2nexus, dev, discord, downloads, events, fed, feed, furhub, furry, linkcheck, market, me, nexusacct, nexuswarn, nsfw, oauth, poster, search, status, themes, uebersetzen, wiki, alle 19 Admin-Reiter (overview, account, audit, database, invites, nx_accounts, nx_cadm, nx_chat, nx_comm, nx_lic, nx_warn, pages, posters, roles, security, server, sessions, system, users) und alle Web-Nexus-Reiter (account, avatars, calendar, client, clubs:browse/cal, community:feed/following/mod/profile/saved, erp, favs, feed, friends, gallery, global, groups, moderation, mynx:devices/heart/week/wish, news, notifs, pulse, search, shop, stats, wiki, worlds).

| Ansicht | Raster | vorher (px je Breite) | nachher |
|---|---|---|---|
| Roadmap.php | div.rm-stats | 420:33 | 420:33 |
| Roadmap.php | ul.rm-items | 1366:44 1920:44 | ok |
| admin.php?tab=pages | div.tpl-info | 1366:25 1920:40 420:45 | ok |
| admin.php?tab=roles | div.roles | 1366:25 1920:25 | ok |
| admin.php?tab=server | div.sv-grid | 1366:125 1920:239 | 1920:100 |
| admin.php?tab=system | div.grid2 | 1366:1653 1920:1339 | ok |
| budget.php | div.bd-addgrid | 420:25 | 420:25 |
| budget.php | div.bd-kpis | 420:44 | 420:44 |
| con.php | div.cn-kpis | 420:27 | 420:27 |
| d2nexus.php | div.feat | – | 1366:30 1920:30 |
| dashboard.php | div.mc-grid | 1366:32 1920:32 420:32 | 1920:32 420:32 |
| downloads.php | div.dl-grid | 1366:93 1920:93 | ok |
| furhub.php | div.fh-grid | 1366:39 1920:39 | ok (nur über Mehrspalten-Karte 130) |
| furhub.php | div.quick-btns | 1366:26 1920:26 420:26 | 1366:26 420:26 |
| index.php | div.ld-feat | 1366:35 1920:35 | ok |
| index.php | div.ld-links | 1366:27 1920:27 | ok |
| index.php | div.ld-proj | 1366:30 1920:30 | ok |
| index.php | section.ld-stats | 420:33 | 420:33 |
| nexus#account | div.vx-cols | 1366:243 1920:243 | ok |
| nexus#stats | div.vx-cols | 1366:108 1920:115 | ok |

Karten-Lücken in Karten-Rastern vorher 49 → nachher 0 außer: Admin → Server bei 1920 px (100 px über der zweispaltigen Prozess-Karte, keine spätere Karte passt hinein) und Furry Hub (Rest über der breiten Schnellstart-Karte). Übrige Messwerte nachher = bewusst nicht angefasste Raster (Kalender `.mc-grid`, Kennzahlen am Handy, Knopf-Kacheln `.quick-btns`, Formular `.bd-addgrid`, Funktions-Kacheln `.feat` in d2nexus).
Breite (Inhalt / verfügbare Breite bei 1920 px) vorher < 95 %: index 63 %, 3hueter-Zentrale 56 %, Roadmap 56 %, botdash 44 %, budget 63 %, changelog 50 %, con 64 %, countdown 51 %, d2nexus 61 %, dev 59 %, discord 70 %, downloads 50 %, events 50 %, fed 59 %, furhub 66 %, linkcheck 51 %, market 59 %, me 50 %, nsfw 80 %, poster 68 %, search 44 %, status 48 %, themes 59 %, uebersetzen 59 %, wiki 51 %, alle Admin-Reiter 78 %, alle Web-Nexus-Reiter 80 %, api (.ap-wrap 57–81 %).
Nachher: 0 von 248 Ansichten unter 95 % außer oauth.php (Anmelde-/Freigabe-Dialog, gewollt schmal).
Screenshots: /tmp/claude-0/-home-user/31e02857-c6ba-52f5-92db-f58542b7414f/scratchpad/masonry/shots/gn-before-1366-admin.php_tab_system.png ↔ gn-after-1366-admin_system.png, gn-before-1920-downloads.php.png ↔ gn-after-1920-downloads.png, gn-after-1920-admin.php_tab_server.png u. a.

## Offene Punkte
- MySQL-Lauf von site9_test nicht möglich (kein lokaler MariaDB-Dienst); keine DB-Änderung.
- Abstände zwischen *getrennten* Rastern auf derselben Seite (z. B. Admin → System: mehrere `.grid2` untereinander) bleiben – jedes Raster für sich ist lückenlos.
