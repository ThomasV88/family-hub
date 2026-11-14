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
  [ "$(stat -c %a "$D/ai-worker.env")" = 600 ] || fail "ai-worker.env mode"
  grep -q "^DB_PASSWORD=$(cat "$D/secrets/db_app_password")\$" "$D/ai-worker.env" || fail "ai-worker.env DB_PASSWORD != db_app_password"
  grep -q "^DB_USERNAME=fh_app\$" "$D/ai-worker.env" || fail "ai-worker.env user"
  grep -q "^APP_ENV=$env\$" "$D/ai-worker.env" || fail "ai-worker.env APP_ENV"
  ! grep -Eq '^(APP_KEY|MAIL_|GOOGLE_|VAPID_|AWS_|STRIPE_|GH_TOKEN|GITHUB_TOKEN|COPILOT_GITHUB_TOKEN)' "$D/ai-worker.env" || fail "ai-worker.env holds an app secret or token"
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
  check_keys ops/env/ai-worker.env.example "$D/ai-worker.env"

  # An existing ai-worker.env is never overwritten (an empty placeholder from deploy.sh is filled)
  echo "AI_CODEX_MODEL=keep-me" > "$D/ai-worker.env"
  bash scripts/server-provision.sh "$env" "$dom" --root "$T" --cert-email ops@example.test >/dev/null
  grep -qx "AI_CODEX_MODEL=keep-me" "$D/ai-worker.env" || fail "existing ai-worker.env was overwritten"
  [ "$(wc -l < "$D/ai-worker.env")" = 1 ] || fail "existing ai-worker.env was changed"
  : > "$D/ai-worker.env"
  bash scripts/server-provision.sh "$env" "$dom" --root "$T" --cert-email ops@example.test >/dev/null
  grep -q "^DB_USERNAME=fh_app" "$D/ai-worker.env" || fail "empty ai-worker.env placeholder was not filled"

  # The cron block carries audit:prune with an explicit compose file, IMAGE_TAG and the stack directory
  prune=$(grep 'audit:prune' "$D/logs/cron.preview" || true)
  [ "$(printf '%s\n' "$prune" | grep -c .)" = 1 ] || fail "cron block needs exactly one audit:prune line"
  case "$prune" in *"cd /opt/$slug && "*) ;; *) fail "audit:prune cron line has no cd to the stack dir" ;; esac
  case "$prune" in *"-f compose.server.yaml --profile migrate run --rm --no-deps -T migrate php artisan audit:prune"*) ;; *) fail "audit:prune cron line is not the explicit migrate run" ;; esac
  case "$prune" in *'IMAGE_TAG="$(cat .last-good-tag)"'*) ;; *) fail "audit:prune cron line lacks IMAGE_TAG" ;; esac
  case "$prune" in *">> logs/cron.log 2>&1") ;; *) fail "audit:prune cron line does not log to logs/cron.log" ;; esac

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
