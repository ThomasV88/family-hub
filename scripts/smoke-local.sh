#!/usr/bin/env bash
# End-to-end local smoke test through nginx (dev stack).
set -euo pipefail

BASE_URL="${BASE_URL:-http://localhost:8080}"

code=$(curl -s -o /tmp/fh-smoke-body -w '%{http_code}' "$BASE_URL/")
if [ "$code" != "200" ]; then
  echo "FAIL: GET / returned HTTP $code" >&2
  exit 1
fi
grep -q 'data-page' /tmp/fh-smoke-body || { echo "FAIL: no data-page in body" >&2; exit 1; }
grep -q 'Home' /tmp/fh-smoke-body || { echo "FAIL: Home component not in body" >&2; exit 1; }
rm -f /tmp/fh-smoke-body

running=$(docker compose ps --status running --services | sort | tr '\n' ' ')
expected="app nginx postgres scheduler worker "
if [ "$running" != "$expected" ]; then
  echo "FAIL: running services '$running' != '$expected'" >&2
  exit 1
fi

# Proves scheduler -> database queue -> worker -> Postgres.
count=0
for _ in $(seq 1 45); do
  count=$(docker compose exec -T postgres psql -U fh_owner -d family_hub -tAc "select count(*) from system_heartbeats where source='schedule'" 2>/dev/null | tr -d '[:space:]' || true)
  if [ "${count:-0}" -ge 1 ] 2>/dev/null; then break; fi
  sleep 2
done
if ! [ "${count:-0}" -ge 1 ] 2>/dev/null; then
  echo "FAIL: no scheduled heartbeat row after 90s" >&2
  exit 1
fi

echo "SMOKE-LOCAL OK"
