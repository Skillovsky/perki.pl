#!/usr/bin/env bash
# Buduje instalowalny zip wtyczki perki-lekcje-jasny do wp-admin > Wtyczki > Dodaj > Wyslij wtyczke.
# Wynik: dist/perki-lekcje-jasny-<VERSION>.zip (katalog dist/ jest w .gitignore).
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
SLUG=perki-lekcje-jasny
SRC="$ROOT/wp-plugins/$SLUG"
VER="$(sed -n "s/.*const VERSION *= *'\([^']*\)'.*/\1/p" "$SRC/$SLUG.php")"
mkdir -p "$ROOT/dist"
OUT="$ROOT/dist/$SLUG-$VER.zip"
rm -f "$OUT"
( cd "$ROOT/wp-plugins" && zip -qr -X "$OUT" "$SLUG" -x "$SLUG/_design-brief.json" )
echo "$OUT"
unzip -l "$OUT" | tail -1
