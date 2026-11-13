#!/usr/bin/env bash
# Restore drill: restore the newest snapshot into a scratch database in the SAME stack, compare it
# with the live database, drop the scratch database.
# RULE: never restore into another stack's database; a production backup is never restored into staging.
# Usage: restore-drill.sh [snapshot|latest]   (run from the stack directory)
# Optional env: DRILL_DB, DRILL_TAG (default nightly; pre-migrate snapshots are not drilled by default), DRILL_CHECK_TABLE (must have >= 1 row), DRILL_ADMIN_USER (default postgres)
set -euo pipefail

load_env() {
  [ -f .env ] || return 0
  local key line val
  for key in COMPOSE_FILE STACK_ENV BACKUP_RESTIC_REPOSITORY RESTIC_PASSWORD_FILE DB_NAME DB_DUMP_USER; do
    [ -n "${!key:-}" ] && continue
    line="$(grep -E "^${key}=" .env | tail -n 1 || true)"
    [ -z "$line" ] && continue
    val="${line#*=}"; val="${val%\"}"; val="${val#\"}"; val="${val%\'}"; val="${val#\'}"
    export "$key=$val"
  done
}
load_env

COMPOSE_FILE="${COMPOSE_FILE:-compose.server.yaml}"
STACK_ENV="${STACK_ENV:-production}"
RESTIC_PASSWORD_FILE="${RESTIC_PASSWORD_FILE:-./secrets/restic_password}"
DB_NAME="${DB_NAME:-family_hub}"
DB_DUMP_USER="${DB_DUMP_USER:-fh_owner}"
DRILL_ADMIN_USER="${DRILL_ADMIN_USER:-postgres}"   # needs CREATEDB; fh_owner does not have it
DRILL_DB="${DRILL_DB:-${DB_NAME}_restore_test}"
SNAPSHOT="${1:-latest}"
LOG_FILE="${BACKUP_LOG_FILE:-./logs/backup.log}"
mkdir -p "$(dirname "$LOG_FILE")"
log() { printf '%s restore-drill[%s] %s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$STACK_ENV" "$*" >> "$LOG_FILE"; }
die() { log "FAILED: $*"; echo "restore-drill: $*" >&2; exit 1; }

[[ "$DRILL_DB" =~ ^[a-z_][a-z0-9_]*_restore_test$ ]] || die "DRILL_DB must match ^[a-z_][a-z0-9_]*_restore_test\$"
[ "$DRILL_DB" != "$DB_NAME" ] || die "refusing to use the live database '$DB_NAME' as drill target"
[ -n "${BACKUP_RESTIC_REPOSITORY:-}" ] || die "BACKUP_RESTIC_REPOSITORY not set"
[ -r "$RESTIC_PASSWORD_FILE" ] || die "password file not readable"

R=(restic -r "$BACKUP_RESTIC_REPOSITORY" --password-file "$RESTIC_PASSWORD_FILE")
PG=(docker compose -f "$COMPOSE_FILE" exec -T postgres)
q() { "${PG[@]}" psql -U "$DRILL_ADMIN_USER" -d "$1" -v ON_ERROR_STOP=1 -qtAc "$2"; }

drop_scratch() { q postgres "drop database if exists \"$DRILL_DB\" with (force)" >/dev/null 2>&1 || true; }
trap drop_scratch EXIT

DRILL_TAG="${DRILL_TAG:-nightly}"
SNAP_ID="$("${R[@]}" snapshots --json --latest 1 --tag "$DRILL_TAG" 2>/dev/null \
  | python3 -c 'import json,sys; s=json.load(sys.stdin); print(max(s, key=lambda x: x["time"])["short_id"] if s else "")')" || true
[ -n "$SNAP_ID" ] || die "no snapshot found"
[ "$SNAPSHOT" = "latest" ] || SNAP_ID="$SNAPSHOT"

log "start snapshot=$SNAP_ID scratch=$DRILL_DB"
drop_scratch
q postgres "create database \"$DRILL_DB\" owner \"$DB_DUMP_USER\"" >/dev/null || die "cannot create scratch database"

"${R[@]}" dump "$SNAP_ID" "/${DB_NAME}.dump" \
  | "${PG[@]}" pg_restore -U "$DB_DUMP_USER" -d "$DRILL_DB" --no-owner --no-privileges --exit-on-error \
  || die "restore failed"

COUNT_SQL="select count(*) from information_schema.tables where table_schema='public'"
LIVE_TABLES="$(q "$DB_NAME" "$COUNT_SQL")"
DRILL_TABLES="$(q "$DRILL_DB" "$COUNT_SQL")"
# A migration may have run after the snapshot, so restored may have fewer tables than live, never more.
[ "$DRILL_TABLES" -le "$LIVE_TABLES" ] || die "restored tables ($DRILL_TABLES) exceed live ($LIVE_TABLES)"
[ "$DRILL_TABLES" -ge 1 ] || die "restored database has no tables"

ROWS=0
has_migrations() { [ "$(q "$1" "select count(*) from information_schema.tables where table_schema='public' and table_name='migrations'")" = "1" ]; }
if has_migrations "$DB_NAME"; then
  LIVE_ROWS="$(q "$DB_NAME" 'select count(*) from migrations')"
  has_migrations "$DRILL_DB" || die "migrations table missing in restored database"
  ROWS="$(q "$DRILL_DB" 'select count(*) from migrations')"
  # A migration may have run after the snapshot, so restored may be lower than live, never higher.
  [ "$ROWS" -le "$LIVE_ROWS" ] || die "restored migrations ($ROWS) exceed live ($LIVE_ROWS)"
  [ "$ROWS" -ge 1 ] || die "restored migrations table is empty"
fi
if [ -n "${DRILL_CHECK_TABLE:-}" ]; then
  [[ "$DRILL_CHECK_TABLE" =~ ^[a-z_][a-z0-9_]*$ ]] || die "invalid DRILL_CHECK_TABLE"
  CHK="$(q "$DRILL_DB" "select count(*) from \"$DRILL_CHECK_TABLE\"")"
  [ "$CHK" -ge 1 ] || die "check table $DRILL_CHECK_TABLE is empty after restore"
fi

MSG="RESTORE-DRILL OK tables=$DRILL_TABLES rows_checked=$ROWS snapshot=$SNAP_ID"
log "$MSG"
echo "$MSG"
