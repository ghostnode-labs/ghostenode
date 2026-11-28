# GhostNode website – working rules

- Answer the owner in **German**; decide sensibly yourself instead of asking ("wähle immer die logische Anforderung").
- PHP without framework; every change must work on **SQLite and MySQL/MariaDB** (test both). Schema changes only via the existing migrations.
- No secrets in code, Git or chat (server env / `/etc/ghostnode`). Never ask for passwords. Never publish the owner's e-mail address.
- Root server script: `server/ghostnode-root.sh` (command `ghostnode`); `ghostnode update` must install/update everything.
- Open libraries, public APIs and similar are generally allowed (owner's OK, 2026-11-28) – pin versions, check the license.
- **Layout: always use a masonry grid** for cards/widgets of different heights (owner, 2026-11-28) – no empty gaps; check all pages before pushing.
- **Layout: always use the full width between the sidebars** (no max-width containers leaving empty space); exception: popups/dialogs – but profile popups also use the full width (owner, 2026-11-28).
- Never weaken age verification (18+), owner lock or NSFW separation. UI texts German.
- Commit messages neutral. No PRs unless asked.
