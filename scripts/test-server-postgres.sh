#!/usr/bin/env bash
# Boots the postgres service of compose.server.yaml on an empty data directory with throwaway secrets
# and checks that first initialisation (docker/postgres/init/01-roles.sh) succeeds and creates the
# expected roles and database. Needs docker; starts only the postgres service.
set -euo pipefail
cd "$(dirname "$0")/.."
ROOT=$PWD

TMP=$(mktemp -d)
PROJECT="${FH_PGTEST_PROJECT:-fh-pgtest-$$}"
cleanup() {
  docker compose -f "$ROOT/compose.server.yaml" --project-directory "$TMP" --env-file "$TMP/.env" \
    down -v --remove-orphans >/dev/null 2>&1 || true
  rm -rf "$TMP"
}
trap cleanup EXIT

cp ops/env/compose.env.example "$TMP/.env"
cp ops/env/app.env.example "$TMP/app.env"
cp ops/env/migrate.env.example "$TMP/migrate.env"
{ echo "IMAGE_TAG=check"; echo "COMPOSE_PROJECT_NAME=$PROJECT"; } >> "$TMP/.env"
mkdir -p "$TMP/secrets" "$TMP/postgres-init" "$TMP/letsencrypt"
cp docker/postgres/init/01-roles.sh "$TMP/postgres-init/"
for s in db_root_password db_owner_password db_app_password restic_password cloudflare.ini; do
  echo "throwaway-$s-$$" > "$TMP/secrets/$s"
done

dc() { docker compose -f "$ROOT/compose.server.yaml" --project-directory "$TMP" --env-file "$TMP/.env" "$@"; }

dc up -d --wait --wait-timeout 120 postgres || { dc logs postgres >&2; echo "FAIL: postgres did not become healthy" >&2; exit 1; }

q() { dc exec -T postgres psql -U postgres -d postgres -tAc "$1" | tr -d '[:space:]'; }
[ "$(q "select pg_get_userbyid(datdba) from pg_database where datname='family_hub'")" = "fh_owner" ] \
  || { echo "FAIL: family_hub missing or not owned by fh_owner" >&2; exit 1; }
[ "$(q "select count(*) from pg_roles where rolname in ('fh_owner','fh_app')")" = "2" ] \
  || { echo "FAIL: roles missing" >&2; exit 1; }

echo "TEST-SERVER-POSTGRES OK"
