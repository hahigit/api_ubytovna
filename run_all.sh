#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")"
ROOT="$(pwd)"
PHP_BIN="${PHP_BIN:-php}"
mkdir -p logs

command -v node >/dev/null || { echo "Chybí Node.js (>= 20)." >&2; exit 1; }
command -v "$PHP_BIN" >/dev/null || { echo "Chybí PHP (>= 8.2). Nastavte PHP_BIN=/cesta/k/php." >&2; exit 1; }

if [[ -f .pids ]]; then
  echo "Služby už zřejmě běží (soubor .pids). Nejdřív spusťte ./stop_all.sh." >&2
  exit 1
fi

[[ -d rezervace-ubytovani/node_modules ]] || (cd rezervace-ubytovani && npm install --no-audit --no-fund)
[[ -d mocks/atrakce-mock/node_modules ]] || (cd mocks/atrakce-mock && npm install --no-audit --no-fund)
if [[ ! -d planovac-vyletu/vendor ]]; then
  (cd planovac-vyletu && ${COMPOSER_BIN:-composer} install --no-interaction)
fi

start() {
  local name="$1" dir="$2"; shift 2
  (cd "$ROOT/$dir" && exec env "$@" >"$ROOT/logs/$name.log" 2>&1) &
  echo "$! $name" >> .pids
  echo "▶ $name (PID $!)"
}

start ubytovani-a rezervace-ubytovani PORT=8001 SEED_FILE=seeds/seed_a.json DATA_FILE=data/db_a.json PROVIDER_NAME="Ubytování A" node src/server.js
start ubytovani-b rezervace-ubytovani PORT=8002 SEED_FILE=seeds/seed_b.json DATA_FILE=data/db_b.json PROVIDER_NAME="Ubytování B" node src/server.js
start atrakce-a   mocks/atrakce-mock  PORT=8101 DATA_FILE=data/attractions_a.json node server.js
start atrakce-b   mocks/atrakce-mock  PORT=8102 DATA_FILE=data/attractions_b.json node server.js
start planovac    planovac-vyletu     PHP_CLI_SERVER_WORKERS=4 "$PHP_BIN" -S 0.0.0.0:8000 -t public public/index.php

echo -n "Čekám na služby"
for port in 8001 8002 8101 8102 8000; do
  for _ in $(seq 1 50); do
    if curl -fs -o /dev/null "http://localhost:$port/"; then break; fi
    sleep 0.2
  done
  if ! curl -fs -o /dev/null "http://localhost:$port/"; then
    echo; echo "Služba na portu $port nenaběhla, viz logs/." >&2; exit 1
  fi
  echo -n " $port✓"
done
echo
echo "Hotovo. Plánovač: http://localhost:8000/  ·  Swagger ubytování: http://localhost:8001/docs"
