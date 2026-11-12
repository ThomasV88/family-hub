#!/usr/bin/env bash
# Builds the nginx image and validates the rendered server config with `nginx -t`.
# The official entrypoint renders /etc/nginx/templates with envsubst before nginx starts.
set -euo pipefail
cd "$(dirname "$0")/.."

TMP=$(mktemp -d)
trap 'rm -rf "$TMP"' EXIT
DOMAIN=example.test

docker build -q -f docker/nginx/Dockerfile -t fh-nginx-check . >/dev/null

mkdir -p "$TMP/live/$DOMAIN"
openssl req -x509 -newkey rsa:2048 -nodes -days 1 -subj "/CN=$DOMAIN" \
  -keyout "$TMP/live/$DOMAIN/privkey.pem" -out "$TMP/live/$DOMAIN/fullchain.pem" 2>/dev/null

# --add-host app: nginx resolves the php-fpm upstream name at config load
docker run --rm --add-host app:127.0.0.1 \
  -e APP_DOMAIN="$DOMAIN" -e NGINX_PROXY_PROTOCOL=proxy_protocol -e STACK_ENV=staging \
  -v "$TMP/live:/etc/letsencrypt/live:ro" \
  fh-nginx-check nginx -t

echo "CHECK-NGINX OK"
