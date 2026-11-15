#!/usr/bin/env bash
# Builds the nginx image and validates the rendered server config with `nginx -t`.
# The official entrypoint renders /etc/nginx/templates with envsubst before nginx starts.
set -euo pipefail
cd "$(dirname "$0")/.."

TMP=$(mktemp -d)
trap 'rm -rf "$TMP"' EXIT
DOMAIN=example.test

# The nginx image copies public/ from the app image (single asset build). The config check does not need the
# real assets, so a stub app image with an empty public/ keeps this check fast.
printf 'FROM busybox\nRUN mkdir -p /var/www/html/public\n' | docker build -q -t fh-app-stub - >/dev/null
docker build -q -f docker/nginx/Dockerfile --build-arg APP_IMAGE=fh-app-stub -t fh-nginx-check . >/dev/null

mkdir -p "$TMP/live/$DOMAIN"
openssl req -x509 -newkey rsa:2048 -nodes -days 1 -subj "/CN=$DOMAIN" \
  -keyout "$TMP/live/$DOMAIN/privkey.pem" -out "$TMP/live/$DOMAIN/fullchain.pem" 2>/dev/null

# --add-host app: nginx resolves the php-fpm upstream name at config load
docker run --rm --add-host app:127.0.0.1 \
  -e APP_DOMAIN="$DOMAIN" -e NGINX_PROXY_PROTOCOL=proxy_protocol -e STACK_ENV=staging \
  -v "$TMP/live:/etc/letsencrypt/live:ro" \
  fh-nginx-check nginx -t

# Body limits: the medical upload URL is the only one at 12m, and no location raises the limit beyond that.
SNIP=docker/nginx/snippets/laravel-locations.conf
[ "$(grep -c 'client_max_body_size 12m' "$SNIP")" = 1 ] || { echo "FAIL: expected exactly one 12m limit" >&2; exit 1; }
grep -B1 'client_max_body_size 12m' "$SNIP" | grep -q '/health/people/\[0-9a-f-\]{36}/documents\$' \
  || { echo "FAIL: the 12m limit is not on the medical upload location" >&2; exit 1; }
while read -r v; do
  n="${v%m}"
  [ "$n" -le 12 ] || { echo "FAIL: body limit $v exceeds 12m" >&2; exit 1; }
done < <(grep -rhoE 'client_max_body_size [0-9]+m' docker/nginx | awk '{print $2}')

echo "CHECK-NGINX OK"
