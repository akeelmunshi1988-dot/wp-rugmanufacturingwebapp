#!/bin/sh
# Quote-engine parity check: runs the ORIGINAL Python engine and the PORTED
# PHP engine over the same cases.json matrix and diffs the results.
#
#   ./run.sh
#
# Requires: the docker compose stack up (docker compose up -d && ./setup.sh),
# and the original app's venv at ../../../RugManufactureCustomApp/backend/venv
# (override with BACKEND=/path/to/backend).
set -e
cd "$(dirname "$0")"

DOCKER="${DOCKER:-docker}"
command -v "$DOCKER" >/dev/null 2>&1 || DOCKER=/Applications/Docker.app/Contents/Resources/bin/docker
BACKEND="${BACKEND:-$(cd ../../../RugManufactureCustomApp/backend && pwd)}"
PY="$BACKEND/venv/bin/python"
[ -x "$PY" ] || PY=python3

echo "1/3  Python reference engine ..."
BACKEND="$BACKEND" "$PY" run_reference.py

echo "2/3  PHP ported engine (in the wordpress container) ..."
CID=$(cd ../.. && "$DOCKER" compose ps -q wordpress)
[ -n "$CID" ] || { echo "wordpress container not running — 'docker compose up -d' first" >&2; exit 1; }
"$DOCKER" cp cases.json   "$CID:/tmp/cases.json"
"$DOCKER" cp run_php.php  "$CID:/tmp/run_php.php"
"$DOCKER" exec "$CID" php /tmp/run_php.php /tmp/cases.json /tmp/php_out.json
"$DOCKER" cp "$CID:/tmp/php_out.json" ./php_out.json

echo "3/3  diff ..."
"$PY" compare.py 2>/dev/null || python3 compare.py
