#!/usr/bin/env bash
cd "$(dirname "$0")"
[[ -f .pids ]] || { echo "Žádné běžící služby (.pids neexistuje)."; exit 0; }
while read -r pid name; do
  if kill "$pid" 2>/dev/null; then echo "■ $name (PID $pid)"; fi
done < .pids
rm -f .pids
