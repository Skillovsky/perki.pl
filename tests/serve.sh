#!/usr/bin/env bash
# Lokalny serwer testowy: prawdziwa wtyczka perki-lekcje-jasny na stubie WordPressa.
# Uzycie: tests/serve.sh [port]   (domyslnie 8787). Konczy sie Ctrl+C.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
PORT="${1:-8787}"
DOCROOT="${PERKI_DOCROOT:-${TMPDIR:-/tmp}/perki-docroot}"
export PERKI_STUB_STATE="${PERKI_STUB_STATE:-${TMPDIR:-/tmp}/perki-stub-state}"
export PERKI_PLUGIN_DIR="${PERKI_PLUGIN_DIR:-$ROOT/wp-plugins/perki-lekcje-jasny}"
mkdir -p "$DOCROOT/wp-content/plugins" "$PERKI_STUB_STATE"
ln -sfn "$PERKI_PLUGIN_DIR" "$DOCROOT/wp-content/plugins/perki-lekcje-jasny"
exec php -S "127.0.0.1:$PORT" -t "$DOCROOT" "$ROOT/tests/wp-stub/router.php"
