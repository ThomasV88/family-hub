#!/usr/bin/env bash
# Dry run of server-provision.sh in a temp directory. Prints PROVISION-DRYRUN OK on success.
set -euo pipefail
cd "$(dirname "$0")/.."

T="$(mktemp -d)"
trap 'rm -rf "$T"' EXIT
fail() { echo "FAIL: $*" >&2; exit 1; }

for env in staging production; do
  if [ "$env" = staging ]; then slug=family-hub-staging; dom=family-hub-stage.example.test; else slug=family-hub; dom=family-hub.example.test; fi
  D="$T/opt/$slug"
  mkdir -p "$D/secrets"; : > "$D/secrets/cloudflare.ini"; echo placeholder > "$D/secrets/cloudflare.ini"

  # Missing cloudflare.ini is refused
  M="$T/missing-$env"
  if bash scripts/server-provision.sh "$env" "$dom" --root "$M" >/dev/null 2>&1; then fail "must refuse without cloudflare.ini"; fi

  bash scripts/server-provision.sh "$env" "$dom" --root "$T" --cert-email ops@example.test --install-cron >/dev/null
  [ "$(stat -c %a "$D/secrets/db_app_password")" = 600 ] || fail "db_app_password mode"
  [ "$(stat -c %a "$D/secrets")" = 700 ] || fail "secrets dir mode"
  [ "$(stat -c %a "$D/app.env")" = 600 ] || fail "app.env mode"
  ! grep -qi password "$D/.env" || fail ".env holds a password"
  grep -q "APP_ENV=$env" "$D/app.env" || fail "APP_ENV"
  grep -q "^APP_KEY=base64:.\{20,\}" "$D/app.env" || fail "APP_KEY"
  grep -q "^DB_PASSWORD=$(cat "$D/secrets/db_app_password")\$" "$D/app.env" || fail "app.env DB_PASSWORD != db_app_password"
  grep -q "^DB_PASSWORD=$(cat "$D/secrets/db_owner_password")\$" "$D/migrate.env" || fail "migrate.env DB_PASSWORD != db_owner_password"
  grep -q "^DB_USERNAME=fh_owner" "$D/migrate.env" || fail "migrate.env user"
  grep -q "^STAGING_RESEED=$([ "$env" = staging ] && echo 1 || echo 0)\$" "$D/.env" || fail "STAGING_RESEED"

  # Every key of the example files appears in the generated files
  check_keys() {  # <example> <generated>
    while IFS= read -r k; do
      grep -q "^$k=" "$2" || fail "key $k from $1 missing in $2"
    done < <(sed -n 's/^\([A-Z_]*\)=.*/\1/p' "$1")
  }
  check_keys ops/env/compose.env.example "$D/.env"
  check_keys ops/env/app.env.example "$D/app.env"
  check_keys ops/env/migrate.env.example "$D/migrate.env"

  # Idempotent: a second run changes nothing
  before=$(cd "$D" && find . -type f -not -name cron.preview -print0 | sort -z | xargs -0 sha256sum | sha256sum)
  bash scripts/server-provision.sh "$env" "$dom" --root "$T" --cert-email ops@example.test --install-cron >/dev/null
  after=$(cd "$D" && find . -type f -not -name cron.preview -print0 | sort -z | xargs -0 sha256sum | sha256sum)
  [ "$before" = "$after" ] || fail "second run changed files"
done

# The script never prints generated values
out=$(bash scripts/server-provision.sh staging family-hub-stage.example.test --root "$T" 2>&1)
secret=$(cat "$T/opt/family-hub-staging/secrets/db_app_password")
case "$out" in *"$secret"*) fail "output contains a secret" ;; esac

echo "PROVISION-DRYRUN OK"
