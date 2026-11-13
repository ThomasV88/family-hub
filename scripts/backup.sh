#!/usr/bin/env bash
# Encrypted backup: pg_dump and a tar of the wish-images uploads volume stream straight into a restic
# repository (no plaintext file). Two snapshots per nightly/manual run:
#   database: tags <STACK_ENV> + <kind>                              path /<DB_NAME>.dump
#   uploads:  tags <STACK_ENV> + uploads + uploads-<kind>            path /uploads.tar
# The uploads snapshot never carries the bare kind tag, so `--tag nightly|manual` still means "database".
# pre-migrate runs skip uploads. The off-site copy receives the explicit snapshot ids of this run.
# Usage: backup.sh [nightly|pre-migrate|manual]   (run from the stack directory)
# Env (from .env or the environment): COMPOSE_FILE, STACK_ENV, BACKUP_RESTIC_REPOSITORY,
#   BACKUP_COPY_REPOSITORY, RESTIC_PASSWORD_FILE, BACKUP_COPY_PASSWORD_FILE, DB_NAME, DB_DUMP_USER,
#   BACKUP_LOG_FILE (default ./logs/backup.log), UPLOADS_PATH (directory inside the app container, default /var/www/html/storage/app/private/wish-images)
set -euo pipefail

KIND="${1:-nightly}"
case "$KIND" in nightly|pre-migrate|manual) ;; *) echo "usage: $0 [nightly|pre-migrate|manual]" >&2; exit 2 ;; esac

# Read only the named keys from .env (never source the whole file); real environment wins.
load_env() {
  [ -f .env ] || return 0
  local key line val
  for key in COMPOSE_FILE STACK_ENV BACKUP_RESTIC_REPOSITORY BACKUP_COPY_REPOSITORY RESTIC_PASSWORD_FILE \
             BACKUP_COPY_PASSWORD_FILE DB_NAME DB_DUMP_USER UPLOADS_PATH BACKUP_LOG_FILE; do
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
DB_PATH="/${DB_NAME}.dump"
UPLOADS_PATH="${UPLOADS_PATH:-/var/www/html/storage/app/private/wish-images}"
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

# Uploads: tar from a one-off app container (works when app is not running) straight into restic.
USNAP=""
UFAIL=""
if [ "$KIND" = "pre-migrate" ]; then
  log "uploads skipped (pre-migrate)"
else
  UOUT="$(mktemp)"
  trap 'rm -f "$OUT" "$UOUT"' EXIT
  set +e
  docker compose -f "$COMPOSE_FILE" run --rm --no-deps -T --entrypoint tar app -C "$UPLOADS_PATH" -cf - . \
    | "${R[@]}" backup --stdin --stdin-filename uploads.tar --tag "$STACK_ENV" --tag uploads --tag "uploads-$KIND" --json > "$UOUT"
  URC=("${PIPESTATUS[@]}")
  set -e
  USNAP="$(grep -o '"snapshot_id":"[0-9a-f]*"' "$UOUT" | tail -n 1 | cut -d'"' -f4 || true)"
  if [ "${URC[0]}" -ne 0 ] || [ "${URC[1]}" -ne 0 ] || [ -z "$USNAP" ]; then
    [ -n "$USNAP" ] && "${R[@]}" forget --prune --quiet "$USNAP" >/dev/null 2>&1 || true
    UFAIL="uploads tar or snapshot failed (tar=${URC[0]} restic=${URC[1]})"
    USNAP=""
  fi
fi

if [ "$KIND" = "nightly" ]; then
  case "$STACK_ENV" in
    production) KEEP=(--keep-daily 14 --keep-weekly 8 --keep-monthly 6) ;;
    *)          KEEP=(--keep-daily 7) ;;
  esac
  "${R[@]}" forget --tag nightly --path "$DB_PATH" "${KEEP[@]}" --prune --quiet || die "retention (nightly) failed"
  "${R[@]}" forget --tag uploads-nightly --path /uploads.tar "${KEEP[@]}" --prune --quiet || die "retention (uploads-nightly) failed"
fi
if [ "$KIND" = "pre-migrate" ]; then
  "${R[@]}" forget --tag pre-migrate --path "$DB_PATH" --keep-last 10 --prune --quiet || die "retention (pre-migrate) failed"
fi

if [ -n "${BACKUP_COPY_REPOSITORY:-}" ]; then
  COPY_IDS=("$SNAP")
  [ -n "$USNAP" ] && COPY_IDS+=("$USNAP")
  restic -r "$BACKUP_COPY_REPOSITORY" --password-file "$BACKUP_COPY_PASSWORD_FILE" \
    copy --from-repo "$BACKUP_RESTIC_REPOSITORY" --from-password-file "$RESTIC_PASSWORD_FILE" --quiet "${COPY_IDS[@]}" \
    || die "copy to off-site repository failed"
  U_SHORT="none"; [ -z "$USNAP" ] || U_SHORT="${USNAP:0:8}"
  log "copied db=${SNAP:0:8} uploads=$U_SHORT"
fi

[ -z "$UFAIL" ] || die "$UFAIL"
log "OK"
echo "backup OK ($KIND)"
