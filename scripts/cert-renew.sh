#!/usr/bin/env bash
# Let's Encrypt certificate for the stack domain (DNS-01 through Cloudflare).
#   cert-renew.sh issue   issue the certificate if none exists yet
#   cert-renew.sh renew   renew (cron) and reload nginx when the certificate changed (or cannot be compared)
#   cert-renew.sh exists  exit 0 when a certificate for the domain exists
# letsencrypt/live is root-owned (certbot runs as root), so presence and hash are read inside the certbot container.
# Run from the stack directory (or any cwd: the script changes into its own directory).
set -euo pipefail
cd "$(dirname "$0")"

MODE="${1:-}"
env_get() { sed -n "s/^$1=//p" .env | head -n1 | tr -d "'\"\r"; }
APP_DOMAIN=$(env_get APP_DOMAIN)
CERT_EMAIL=$(env_get CERT_EMAIL)
[ -n "$APP_DOMAIN" ] || { echo "APP_DOMAIN missing in .env" >&2; exit 1; }

# compose.server.yaml requires IMAGE_TAG, which is not stored in .env
if [ -z "${IMAGE_TAG:-}" ]; then
  [ -s .last-good-tag ] || { echo "IMAGE_TAG not set and no .last-good-tag" >&2; exit 1; }
  IMAGE_TAG=$(cat .last-good-tag)
fi
export IMAGE_TAG

dc() { docker compose -f compose.server.yaml "$@"; }

# Prints the sha256 of the live certificate (empty when absent). Runs as root inside the certbot image.
cert_hash() {
  dc --profile certbot run --rm -T --no-deps --entrypoint sh certbot \
    -c 'sha256sum "/etc/letsencrypt/live/$0/fullchain.pem" 2>/dev/null | cut -d" " -f1' "$APP_DOMAIN" || true
}

case "$MODE" in
  issue)
    [ -n "$CERT_EMAIL" ] || { echo "CERT_EMAIL missing in .env" >&2; exit 1; }
    dc --profile certbot run --rm certbot certonly --dns-cloudflare \
      --dns-cloudflare-credentials /cloudflare.ini \
      --dns-cloudflare-propagation-seconds 30 \
      -d "$APP_DOMAIN" --non-interactive --agree-tos -m "$CERT_EMAIL" --keep-until-expiring
    ;;
  renew)
    before=$(cert_hash)
    dc --profile certbot run --rm certbot renew --quiet
    after=$(cert_hash)
    # An empty hash means the comparison is not trustworthy: reload anyway (a reload is harmless).
    if [ -z "$before" ] || [ -z "$after" ] || [ "$before" != "$after" ]; then
      echo "Certificate changed, reloading nginx"
      dc exec -T nginx nginx -s reload
    else
      echo "Certificate unchanged"
    fi
    ;;
  exists)
    [ -n "$(cert_hash)" ]
    ;;
  *)
    echo "usage: $0 issue|renew|exists" >&2
    exit 2
    ;;
esac
