# Eigene Server-Skripte

Alles, was hier liegt, spielt `ghostnode scriptload` auf den Root-Server (auch bei jedem `ghostnode update` und dem nächtlichen Auto-Update).

- Jede Datei wird nach `/opt/ghostnode/scripts/` kopiert und ist danach als Befehl **`gn-NAME`** aufrufbar (`aufraeumen.sh` → `gn-aufraeumen`), oder mit `ghostnode script aufraeumen`.
- Erste Zeile muss `#!/bin/bash` (bzw. `#!/usr/bin/env python3` …) sein. `.sh` wird vorher mit `bash -n`, `.py` mit `py_compile` geprüft – fehlerhafte Skripte werden **nicht** installiert.
- Dateien, die mit `_` anfangen, sind ausgeschaltet (z. B. `_beispiel.sh`). `README*`, `*.md`, `*.txt` werden übersprungen.
- Gelöschte Skripte werden beim nächsten `scriptload` auch auf dem Server entfernt.
- Skripte laufen als **root** – keine Passwörter/Schlüssel hineinschreiben (die stehen in `/etc/ghostnode/`).

## Steuerzeilen (optional, als Kommentar im Skript)

| Zeile | Wirkung |
|---|---|
| `# gn-desc: Kurzbeschreibung` | wird bei `ghostnode scripts` angezeigt |
| `# gn-cron: */30 * * * *` | läuft automatisch nach Zeitplan (Cron-Format oder `@daily`, `@hourly`, `@reboot` …) |
| `# gn-run: once` | läuft einmal direkt nach dem Einspielen (erneut, wenn sich die Datei ändert) |
| `# gn-run: always` | läuft bei jedem `scriptload` |

Ausgaben von Cron/once/always landen in `/var/log/ghostnode-scripts.log`.

## Befehle

```
ghostnode scriptload          # Git holen, Skripte einspielen
ghostnode scripts             # installierte Skripte anzeigen
ghostnode script NAME [arg]   # eines ausführen (oder direkt: gn-NAME)
```
