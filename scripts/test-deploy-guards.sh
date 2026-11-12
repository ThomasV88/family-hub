#!/usr/bin/env bash
# Tests the preflight guards of deploy.sh. deploy.sh is sourced with DEPLOY_SH_SOURCE_ONLY=1 so only
# the functions are defined; each guard runs in a subshell inside a fixture directory.
set -uo pipefail
DEPLOY="$(cd "$(dirname "$0")" && pwd)/deploy.sh"
[ -f "$DEPLOY" ] || { echo "FAIL: $DEPLOY does not exist" >&2; exit 1; }

FIX_ROOT=$(mktemp -d)
trap 'rm -rf "$FIX_ROOT"' EXIT
PASS=0
FAILS=0

# make_fixture <dir-name> <project> <stack_env> <reseed> <app_env>
make_fixture() {
  local d="$FIX_ROOT/$1"
  mkdir -p "$d/secrets" "$d/postgres-init"
  cat > "$d/.env" <<ENV
COMPOSE_PROJECT_NAME=$2
EDGE_ALIAS=fh-test-nginx
APP_DOMAIN=test.example
IMAGE_PREFIX=ghcr.io/example/family-hub
STACK_ENV=$3
STAGING_RESEED=$4
ENV
  printf 'APP_ENV=%s\nDB_USERNAME=fh_app\n' "$5" > "$d/app.env"
  echo 'DB_USERNAME=fh_owner' > "$d/migrate.env"
  for f in compose.server.yaml backup.sh postgres-init/01-roles.sh; do echo '#' > "$d/$f"; done
  for s in db_root_password db_owner_password db_app_password restic_password cloudflare.ini; do
    echo x > "$d/secrets/$s"; chmod 600 "$d/secrets/$s"
  done
  chmod 600 "$d/.env" "$d/app.env" "$d/migrate.env"
  echo "$d"
}

# expect <pass|fail> <description> <dir> <image_tag> <guard function>
expect() {
  local want="$1" desc="$2" dir="$3" tag="$4" fn="$5" rc
  (
    cd "$dir" || exit 99
    export IMAGE_TAG="$tag"
    export DEPLOY_SH_SOURCE_ONLY=1
    # shellcheck disable=SC1090
    source "$DEPLOY"
    set +e
    "$fn" >/dev/null 2>&1
  )
  rc=$?
  if { [ "$want" = pass ] && [ "$rc" = 0 ]; } || { [ "$want" = fail ] && [ "$rc" != 0 ] && [ "$rc" != 99 ]; }; then
    PASS=$((PASS + 1)); echo "ok   $desc"
  else
    FAILS=$((FAILS + 1)); echo "FAIL $desc (wanted $want, rc=$rc)" >&2
  fi
}

TAG=sha-abcdef1

d=$(make_fixture ok family-hub-staging staging 0 staging)
expect pass "valid staging inputs" "$d" "$TAG" guard_inputs
expect pass "valid secrets" "$d" "$TAG" guard_secrets
expect pass "clean .env" "$d" "$TAG" guard_env_has_no_passwords
expect pass "no reseed requested" "$d" "$TAG" guard_reseed

d=$(make_fixture pw family-hub-staging staging 0 staging)
echo 'DB_PASSWORD=x' >> "$d/.env"
expect fail "password in .env" "$d" "$TAG" guard_env_has_no_passwords
d=$(make_fixture key family-hub-staging staging 0 staging)
echo 'APP_KEY=base64:abc' >> "$d/.env"
expect fail "APP_KEY in .env" "$d" "$TAG" guard_env_has_no_passwords

d=$(make_fixture rs1 family-hub-staging production 1 staging)
expect fail "STACK_ENV=production with reseed" "$d" "$TAG" guard_reseed
d=$(make_fixture rs2 family-hub production 1 staging)
expect fail "production project with reseed (STACK_ENV says staging)" "$d" "$TAG" guard_reseed
d=$(make_fixture rs3 family-hub-staging staging 1 production)
expect fail "APP_ENV=production with reseed" "$d" "$TAG" guard_reseed
d=$(make_fixture rs4 family-hub-staging staging 1 staging)
expect pass "staging project with reseed" "$d" "$TAG" guard_reseed
d=$(make_fixture rs5 family-hub-staging staging yes staging)
expect fail "invalid STAGING_RESEED value" "$d" "$TAG" guard_reseed
# directory /opt/family-hub cannot be created here; the directory rule is covered by the name check above

d=$(make_fixture tag family-hub-staging staging 0 staging)
expect fail "missing IMAGE_TAG" "$d" "" guard_inputs
expect fail "malformed IMAGE_TAG" "$d" "latest" guard_inputs
expect fail "injected IMAGE_TAG" "$d" 'sha-abcdef1;rm -rf /' guard_inputs
d=$(make_fixture miss family-hub-staging staging 0 staging)
rm "$d/backup.sh"
expect fail "missing backup.sh" "$d" "$TAG" guard_inputs

d=$(make_fixture mode family-hub-staging staging 0 staging)
chmod 644 "$d/secrets/db_app_password"
expect fail "secret with mode 0644" "$d" "$TAG" guard_secrets
d=$(make_fixture mode2 family-hub-staging staging 0 staging)
chmod 640 "$d/secrets/restic_password"
expect fail "secret with mode 0640" "$d" "$TAG" guard_secrets

echo "cases passed: $PASS, failed: $FAILS"
[ "$FAILS" = 0 ] && [ "$PASS" -ge 7 ] || exit 1
echo "GUARDS OK"
