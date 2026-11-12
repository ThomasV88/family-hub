#!/usr/bin/env bash
# Encrypted Postgres backup: pg_dump streams straight into a restic repository (no plaintext file).
# Usage: backup.sh [nightly|pre-migrate|manual]   (run from the stack directory)
# Env (from .env or the environment): COMPOSE_FILE, STACK_ENV, BACKUP_RESTIC_REPOSITORY,
#   BACKUP_COPY_REPOSITORY, RESTIC_PASSWORD_FILE, BACKUP_COPY_PASSWORD_FILE, DB_NAME, DB_DUMP_USER
set -euo pipefail

KIND="${1:-nightly}"
case "$KIND" in nightly|pre-migrate|manual) ;; *) echo "usage: $0 [nightly|pre-migrate|manual]" >&2; exit 2 ;; esac

# Read only the named keys from .env (never source the whole file); real environment wins.
load_env() {
  [ -f .env ] || return 0
  local key line val
  for key in COMPOSE_FILE STACK_ENV BACKUP_RESTIC_REPOSITORY BACKUP_COPY_REPOSITORY RESTIC_PASSWORD_FILE \
             BACKUP_COPY_PASSWORD_FILE DB_NAME DB_DUMP_USER; do
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
LOG_FILE="${BACKUP_LOG_FILE:-./logs/backup.log}"
mkdir -p "$(dirname "$LOG_FILE")"
log() { printf '%s backup[%s/%s] %s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$STACK_ENV" "$KIND" "$*" >> "$LOG_FILE"; }
die() { log "FAILED: $*"; echo "backup: $*" >&2; exit 1; }

[ -n "${BACKUP_RESTIC_REPOSITORY:-}" ] || die "BACKUP_RESTIC_REPOSITORY not set"
[ -r "$RESTIC_PASSWORD_FILE" ] || die "password file not readable"
if [ -n "${BACKUP_COPY_REPOSITORY:-}" ]; then
  [ -r "${BACKUP_COPY_PASSWORD_FILE:-}" ] || die "BACKUP_COPY_PASSWORD_FILE not readable"
fi

R=(restic -r "$BACKUP_RESTIC_REPOSITORY" --password-file "$RESTIC_PASSWORD_FILE")

log "start db=$DB_NAME"
# pg_dump runs inside the postgres container so client and server majors match.
# restic can finish a snapshot of a truncated stream before pg_dump reports failure, so a failed
# pipeline forgets the snapshot it just created (no half-dump is ever kept).
OUT="$(mktemp)"
trap 'rm -f "$OUT"' EXIT
set +e
docker compose -f "$COMPOSE_FILE" exec -T postgres \
  pg_dump -U "$DB_DUMP_USER" -d "$DB_NAME" -Fc --no-owner --no-privileges \
  | "${R[@]}" backup --stdin --stdin-filename "${DB_NAME}.dump" --tag "$STACK_ENV" --tag "$KIND" --json > "$OUT"
RC=("${PIPESTATUS[@]}")
set -e
SNAP="$(grep -o '"snapshot_id":"[0-9a-f]*"' "$OUT" | tail -n 1 | cut -d'"' -f4 || true)"
if [ "${RC[0]}" -ne 0 ] || [ "${RC[1]}" -ne 0 ]; then
  [ -n "$SNAP" ] && "${R[@]}" forget --prune --quiet "$SNAP" >/dev/null 2>&1 || true
  die "dump or snapshot failed (pg_dump=${RC[0]} restic=${RC[1]}); partial snapshot removed"
fi
[ -n "$SNAP" ] || die "restic reported no snapshot id"

if [ "$KIND" = "nightly" ]; then
  case "$STACK_ENV" in
    production) KEEP=(--keep-daily 14 --keep-weekly 8 --keep-monthly 6) ;;
    *)          KEEP=(--keep-daily 7) ;;
  esac
  "${R[@]}" forget --tag nightly "${KEEP[@]}" --prune --quiet || die "retention (nightly) failed"
fi
if [ "$KIND" = "pre-migrate" ]; then
  "${R[@]}" forget --tag pre-migrate --keep-last 10 --prune --quiet || die "retention (pre-migrate) failed"
fi

if [ -n "${BACKUP_COPY_REPOSITORY:-}" ]; then
  restic -r "$BACKUP_COPY_REPOSITORY" --password-file "$BACKUP_COPY_PASSWORD_FILE" \
    copy --from-repo "$BACKUP_RESTIC_REPOSITORY" --from-password-file "$RESTIC_PASSWORD_FILE" latest --quiet \
    || die "copy to off-site repository failed"
  log "copied to off-site repository"
fi

log "OK"
echo "backup OK ($KIND)"
