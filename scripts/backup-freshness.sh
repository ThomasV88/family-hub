#!/usr/bin/env bash
# Exits 1 when the newest nightly snapshot is older than max_hours (default 26). Pre-migrate and manual
# snapshots do not count. Every failure prints a BACKUP STALE line to stderr (cron.log) and, when
# BACKUP_ALERT_COMMAND is set (env or .env), runs that command with the message as its only argument.
# Usage: backup-freshness.sh [max_hours=26]
set -euo pipefail

MAX="${1:-26}"
for key in STACK_ENV BACKUP_RESTIC_REPOSITORY RESTIC_PASSWORD_FILE BACKUP_ALERT_COMMAND BACKUP_COPY_REPOSITORY; do
  if [ -z "${!key:-}" ] && [ -f .env ]; then
    line="$(grep -E "^${key}=" .env | tail -n 1 || true)"
    [ -n "$line" ] && { val="${line#*=}"; val="${val%\"}"; val="${val#\"}"; export "$key=$val"; }
  fi
done
RESTIC_PASSWORD_FILE="${RESTIC_PASSWORD_FILE:-./secrets/restic_password}"
LOG_FILE="${BACKUP_LOG_FILE:-./logs/backup.log}"
mkdir -p "$(dirname "$LOG_FILE")"
[ -n "${BACKUP_RESTIC_REPOSITORY:-}" ] || { echo "BACKUP_RESTIC_REPOSITORY not set" >&2; exit 1; }

if [ "${STACK_ENV:-}" = production ] && [ -z "${BACKUP_COPY_REPOSITORY:-}" ]; then
  echo "WARNING: BACKUP_COPY_REPOSITORY is empty: backups exist on this VPS only (Phase 1 decision: no off-site copy)" >&2
fi

# Visible failure: stderr line, log entry and the optional notification hook, then non-zero exit.
stale() {
  local msg="BACKUP STALE: $1"
  echo "$msg" >&2
  printf '%s freshness[%s] %s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "${STACK_ENV:-}" "$msg" >> "$LOG_FILE"
  if [ -n "${BACKUP_ALERT_COMMAND:-}" ]; then
    "$BACKUP_ALERT_COMMAND" "$msg [${STACK_ENV:-}]" || echo "alert command failed" >&2
  fi
  exit 1
}

JSON="$(restic -r "$BACKUP_RESTIC_REPOSITORY" --password-file "$RESTIC_PASSWORD_FILE" snapshots --json --tag nightly --latest 1)" \
  || stale "repository unreadable"

AGE="$(printf '%s' "$JSON" | python3 -c '
import json, sys
from datetime import datetime, timezone
s = json.load(sys.stdin)
if not s:
    print("none"); sys.exit()
t = max(s, key=lambda x: x["time"])["time"]
t = t[:19] + "+00:00" if t.endswith("Z") else t
# restic emits nanoseconds; keep seconds precision
d = datetime.fromisoformat(t[:19] + t[-6:] if "." in t else t)
print(f"{(datetime.now(timezone.utc) - d).total_seconds() / 3600:.6f}")
')"

if [ "$AGE" = "none" ]; then
  stale "no nightly snapshot"
fi
if python3 -c "import sys; sys.exit(0 if float('$AGE') <= float('$MAX') else 1)"; then
  echo "BACKUP FRESH: ${AGE}h"
else
  stale "newest nightly snapshot is ${AGE}h old (max ${MAX}h)"
fi
