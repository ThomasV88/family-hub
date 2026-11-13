#!/usr/bin/env bash
# Post-deploy smoke test against a live stack.
# Usage: bash scripts/smoke-server.sh <domain> <staging|production>
set -euo pipefail

DOMAIN="${1:?usage: smoke-server.sh <domain> <staging|production>}"
STACK="${2:?usage: smoke-server.sh <domain> <staging|production>}"
case "$STACK" in staging|production) ;; *) echo "env must be staging or production" >&2; exit 2 ;; esac
BASE="https://$DOMAIN"

TMP=$(mktemp -d)
trap 'rm -rf "$TMP"' EXIT
fail() { echo "FAIL: $*" >&2; exit 1; }

# 1. Home page over HTTPS. The first request is retried: right after a deploy the stack may still warm up.
code=000
for attempt in 1 2 3 4 5 6 7 8; do
  code=$(curl -sS --max-time 20 -D "$TMP/h" -o "$TMP/b" -w '%{http_code}' "$BASE/" 2>/dev/null) || code=000
  [ "$code" = 200 ] && break
  sleep 4
done
[ "$code" = 200 ] || fail "GET / returned HTTP $code"
grep -qi '^strict-transport-security:' "$TMP/h" || fail "no Strict-Transport-Security header"
grep -q 'data-page' "$TMP/b" || fail "no data-page in body (Inertia not rendering)"

# 1b. A static asset referenced by the HTML must be served (catches app/nginx images built from different assets)
asset=$(grep -oE '/build/assets/[A-Za-z0-9._-]+\.(js|css)' "$TMP/b" | head -n1 || true)
[ -n "$asset" ] || fail "no /build/assets reference found in the home page"
code=$(curl -sS -o /dev/null -w '%{http_code}' "$BASE$asset")
[ "$code" = 200 ] || fail "$asset referenced by the page returned HTTP $code"

# 2. noindex on staging only
if [ "$STACK" = staging ]; then
  grep -i '^x-robots-tag:' "$TMP/h" | grep -qi noindex || fail "staging must send X-Robots-Tag noindex"
else
  if grep -qi '^x-robots-tag:' "$TMP/h"; then fail "production must not send X-Robots-Tag"; fi
fi

# 3. 404 without a stack trace
rnd="does-not-exist-$RANDOM$RANDOM"
code=$(curl -sS -o "$TMP/n" -w '%{http_code}' "$BASE/$rnd")
[ "$code" = 404 ] || fail "GET /$rnd returned HTTP $code (expected 404)"
if grep -Eq 'Stack trace|vendor/laravel|Whoops' "$TMP/n"; then fail "404 page leaks a stack trace"; fi

# 4. Redirects must stay on https. /icons is a directory, so nginx answers GET /icons with a real 301
#    whose Location is built from the scheme the request arrived with.
code=$(curl -sS -D "$TMP/r" -o /dev/null -w '%{http_code}' "$BASE/icons")
[ "$code" = 301 ] || fail "GET /icons returned HTTP $code (expected a 301 redirect)"
loc=$(tr -d '\r' < "$TMP/r" | sed -n 's/^[Ll]ocation: //p' | head -n1)
case "$loc" in https://*) ;; *) fail "GET /icons redirects to a non-https URL: '$loc'" ;; esac

# 5. Service worker (arrives with the PWA plan): 200 must carry no-cache, 404 is acceptable until then
code=$(curl -sS -D "$TMP/sw" -o /dev/null -w '%{http_code}' "$BASE/sw.js")
case "$code" in
  200) grep -i '^cache-control:' "$TMP/sw" | grep -qi 'no-cache' || fail "/sw.js must send Cache-Control: no-cache" ;;
  404) ;;
  *) fail "/sw.js returned HTTP $code" ;;
esac

echo "SMOKE-SERVER OK"
