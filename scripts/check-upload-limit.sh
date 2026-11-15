#!/usr/bin/env bash
# Dev-stack smoke for the scoped nginx body limits of the wish image upload (plan 04-02 / 04-07) and the medical
# document upload (plan 07-10: 12m on /health/people/<uuid>/documents).
# Usage: bash scripts/check-upload-limit.sh [compose-project]   (run from the repo root, default fh0407)
# A well-formed 2 MiB multipart body must reach PHP on the upload route (any status except 413 and 404;
# 419 from the CSRF check is expected and proves the pinned SCRIPT_NAME matched the route), while the same
# body on /login is refused by nginx with 413. `compose run` does not publish port 8080, so a running dev
# stack is not disturbed. Response bodies are never printed.
set -euo pipefail

P="${1:-fh0407}"
NAME="${P}-nginx-smoke"
cleanup() { docker rm -f "$NAME" >/dev/null 2>&1 || true; }
trap cleanup EXIT
cleanup

docker compose -p "$P" run --rm -e DB_USERNAME=fh_owner -e DB_PASSWORD=fh_owner_dev app php artisan migrate --force >/dev/null
docker compose -p "$P" run -d --name "$NAME" nginx >/dev/null

# Wait until nginx answers (any HTTP status line counts).
ready=0
for _ in $(seq 1 30); do
  if docker exec "$NAME" sh -c 'wget -S -q -O /dev/null http://127.0.0.1/login 2>&1 | grep -q "HTTP/"'; then
    ready=1
    break
  fi
  sleep 1
done
if [ "$ready" != "1" ]; then
  echo "FAIL: nginx smoke container did not answer" >&2
  exit 1
fi

out=$(docker exec -i "$NAME" sh -s <<'INNER'
set -eu
B=fhsmokeboundary7d1
F=/tmp/body.bin
{
  printf -- "--%s\r\nContent-Disposition: form-data; name=\"image\"; filename=\"x.jpg\"\r\nContent-Type: image/jpeg\r\n\r\n" "$B"
  head -c 2097152 /dev/urandom
  printf "\r\n--%s--\r\n" "$B"
} > "$F"
# Raw HTTP over busybox nc: busybox wget does not send the request the way a browser does and masks the nginx status.
post() {
  len=$(wc -c < "$F")
  { printf 'POST %s HTTP/1.1\r\nHost: localhost\r\nContent-Type: multipart/form-data; boundary=%s\r\nContent-Length: %s\r\nConnection: close\r\n\r\n' "$1" "$B" "$len"; cat "$F"; sleep 5; } \
    | nc -w 10 127.0.0.1 80 2>/dev/null | sed -n 's/^HTTP\/[0-9.]* \([0-9][0-9][0-9]\).*/\1/p' | head -n 1
}
echo "upload=$(post /wishlist/wishes/1/image)"
echo "other=$(post /login)"
# 11 MiB body: above the 2m default, below the 12m scoped limit, so it must reach PHP on the medical route.
rm -f "$F"
{
  printf -- "--%s\r\nContent-Disposition: form-data; name=\"file\"; filename=\"x.pdf\"\r\nContent-Type: application/pdf\r\n\r\n" "$B"
  head -c 11534336 /dev/urandom
  printf "\r\n--%s--\r\n" "$B"
} > "$F"
echo "medical=$(post /health/people/00000000-0000-4000-8000-000000000000/documents)"
echo "medicalother=$(post /login)"
rm -f "$F"
INNER
)

upload=$(printf '%s\n' "$out" | sed -n 's/^upload=//p')
other=$(printf '%s\n' "$out" | sed -n 's/^other=//p')

if [ -z "$upload" ] || [ "$upload" = "413" ] || [ "$upload" = "404" ]; then
  echo "FAIL: upload route answered '${upload:-none}' (must reach PHP, not 413 or 404)" >&2
  exit 1
fi
medical=$(printf '%s\n' "$out" | sed -n 's/^medical=//p')
medicalother=$(printf '%s\n' "$out" | sed -n 's/^medicalother=//p')

if [ -z "$medical" ] || [ "$medical" = "413" ] || [ "$medical" = "404" ]; then
  echo "FAIL: medical upload route answered '${medical:-none}' (must reach PHP, not 413 or 404)" >&2
  exit 1
fi
if [ "$medicalother" != "413" ]; then
  echo "FAIL: 11 MiB body on /login answered '${medicalother:-none}' (expected 413)" >&2
  exit 1
fi
if [ "$other" != "413" ]; then
  echo "FAIL: other route answered '${other:-none}' (expected 413)" >&2
  exit 1
fi
echo "UPLOAD-LIMIT OK upload=$upload other=$other medical=$medical medicalother=$medicalother"
