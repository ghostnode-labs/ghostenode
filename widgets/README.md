# Widgets (Plug & Play)

Jeder Ordner hier ist ein Widget für die Startseite. Das Dashboard findet sie automatisch –
einfach einen Ordner hineinkopieren, und das Widget erscheint unter **Startseite → ✎ Bearbeiten → ＋ Widget**.

```
widgets/
├── _vorlage/        ← Vorlage für eigene Widgets (wird ignoriert, weil sie mit _ beginnt)
│   ├── widget.php   ← Pflicht: Name, Icon, Einstellungen, render()
│   ├── widget.css   ← optional, wird automatisch geladen
│   └── widget.js    ← optional, wird automatisch geladen
├── clock/           ← Uhr & Datum
├── weather/         ← Wetter (Open-Meteo, ohne API-Schlüssel)
├── notes/           ← Schnellnotiz
├── todo/            ← Aufgaben
├── countdowns/      ← Countdowns
├── calendar/        ← Mini-Kalender & nächste Termine
├── budget/          ← Budget-Kurzübersicht (Recht budget.access)
├── vrchat/          ← VRChat-Freunde online (Recht vrcx.access)
├── feed/            ← Neueste Feed-Meldungen
├── quicklinks/      ← Schnellzugriff / Lesezeichen
├── vrclive/         ← VRChat live über den Nexus-Desktop-Client (Welt, Spieler, Chatbox, OSC-Schnellknöpfe)
│   — neu in 9.0 —
├── releases/        ← Release-Status VRC Nexus, D2 Nexus, Discord-Bot
├── services/        ← Dienst-Status (Daten der Status-Seite)
├── dlstats/         ← Download-Statistik VRC Nexus
├── market/          ← Marktplatz-Neuheiten (Plugins & Themes)
├── notify/          ← Benachrichtigungen aus „Mein Bereich“
├── notebook/        ← Notizbuch (privat je Konto)
├── bookmarks/       ← Lesezeichen mit Ordnern + Browser-Import (privat je Konto)
├── snippets/        ← Textbausteine zum Kopieren (privat je Konto)
├── habits/          ← Gewohnheiten mit Wochenraster und Serie (privat je Konto)
├── accountcheck/    ← Konto-Check (Sicherheits-Punktzahl)
├── adminq/          ← Admin-Postfach (Recht admin.access)
├── online/          ← Wer ist online (Recht users.view)
├── auditlive/       ← Letzte Aktivitäten aus dem Protokoll (Recht audit.view)
└── sunmoon/         ← Sonne & Mond (ohne fremden Dienst)
```

**Eigenes Widget:** `_vorlage` kopieren, Ordner umbenennen (z. B. `mein-widget`), `widget.php` anpassen.
Alle Möglichkeiten sind in `_vorlage/widget.php` kommentiert. Ein Widget entfernen = Ordner löschen.

Ein Fehler in einem Widget legt nie die Startseite lahm – er wird nur im betroffenen Widget angezeigt.

**Seit 9.0:** `$w->user_state()` / `$w->save_user_state()` speichern Inhalte **je Konto** (Tabelle `user_kv`) – so bleibt z. B. ein Notizbuch
privat, auch wenn das Widget in der gemeinsamen Anordnung steht. Mit `'group' => 'personal'` (overview, plan, personal, projects, community,
system, fun) landet ein Widget in einer Gruppe der Filter-Leiste. Jede Person kann Widgets unter **🎛 Anpassen** für sich sortieren und ausblenden.
