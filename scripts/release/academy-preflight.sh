#!/usr/bin/env bash
# Read-only production preflight: no writes, migrations, scheduler or Telegram sends.
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$ROOT"
PHP_BIN="${PHP84:-/opt/php/8.4/bin/php}"
if [[ ! -x "$PHP_BIN" ]]; then
  PHP_BIN="$(command -v php || true)"
fi
if [[ -z "$PHP_BIN" || ! -x "$PHP_BIN" ]]; then
  echo "STOP: PHP CLI executable was not found." >&2
  exit 1
fi
if [[ ! -f .env ]]; then
  echo "STOP: Laravel .env is missing; do not create or replace production secrets." >&2
  exit 1
fi
if [[ ! -f public/build/manifest.json ]]; then
  echo "STOP: public/build/manifest.json is missing." >&2
  exit 1
fi
echo "=== Current production checkout ==="
git rev-parse --verify HEAD
git branch --show-current
echo "=== Tracked local edits (must be empty) ==="
EDITED="$(git status --porcelain --untracked-files=no)"
if [[ -n "$EDITED" ]]; then
  printf '%s\n' "$EDITED"
  echo "STOP: Tracked edits exist. Preserve and review them before updating." >&2
  exit 1
fi
echo "Clean tracked Git checkout."
echo "=== PHP ==="
"$PHP_BIN" -r 'echo PHP_VERSION, PHP_EOL;'
echo "=== DB drivers and queue only (no credentials) ==="
"$PHP_BIN" artisan tinker --execute='echo "primary=".config("database.default")." / analytics=".config("database.connections.analytics.driver")." / queue=".config("queue.default").PHP_EOL;'
echo "=== Migration ledger: STOP if there are unexpected Pending entries ==="
"$PHP_BIN" artisan migrate:status
echo "=== Scheduler ==="
"$PHP_BIN" artisan schedule:list
echo "=== Disk ==="
df -h .
echo "Read-only preflight complete. This does NOT confirm database/upload backups."
