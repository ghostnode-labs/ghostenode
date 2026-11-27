#!/bin/sh
# 8.1: Selbst-hosten-Paket bauen – die Webseite ohne persönliche Daten, Schlüssel und Entwicklungsdateien.
# Aufruf: sh tools/selfhost-pack.sh [ziel.zip]   (aus dem Hauptordner der Webseite)
set -e
ROOT=$(cd "$(dirname "$0")/.." && pwd)
OUT=${1:-ghostnode-selfhost.zip}
case "$OUT" in /*) ;; *) OUT="$ROOT/$OUT" ;; esac
TMP=$(mktemp -d)
mkdir -p "$TMP/ghostnode"
cd "$ROOT"
# alles außer: Git, CI, privaten Daten, Uploads, Desktop-Client-Quellen, Werkzeugen, Cache/Backups
tar --exclude=./.git --exclude=./.github --exclude=./data --exclude=./uploads --exclude=./clients --exclude=./tools \
    --exclude='*.zip' --exclude='*.tmp' --exclude='__pycache__' -cf - . | (cd "$TMP/ghostnode" && tar xf -)
# leerer, gesperrter Datenordner (wird beim ersten Aufruf befüllt)
mkdir -p "$TMP/ghostnode/data" "$TMP/ghostnode/uploads"
printf '' > "$TMP/ghostnode/data/index.html"
printf 'Require all denied\nDeny from all\n' > "$TMP/ghostnode/data/.htaccess"
printf '' > "$TMP/ghostnode/uploads/index.html"
cp "$ROOT/tools/SELBST-HOSTEN.md" "$TMP/ghostnode/SELBST-HOSTEN.md"
date -u '+%Y-%m-%d %H:%M UTC' > "$TMP/ghostnode/VERSION.txt"
git -C "$ROOT" rev-parse --short HEAD >> "$TMP/ghostnode/VERSION.txt" 2>/dev/null || true
# Sicherheitsnetz: keine Geheimnisse im Paket
if grep -rIl --exclude=SELBST-HOSTEN.md -E 'whsec_[0-9a-f]{20,}|gnx_[0-9a-f]{40}|gnk_[A-Za-z0-9]{20,}' "$TMP/ghostnode" >/dev/null 2>&1; then echo "Abbruch: Schlüssel im Paket gefunden" >&2; exit 1; fi
cd "$TMP"
rm -f "$OUT.part"
zip -qr9 "$OUT.part" ghostnode
mv "$OUT.part" "$OUT"
rm -rf "$TMP"
echo "✔ $OUT ($(du -h "$OUT" | cut -f1))"
