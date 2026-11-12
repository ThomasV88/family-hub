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

echo "SMOKE-LOCAL OK"
