#!/usr/bin/env bash
# Exits 1 when the newest snapshot is older than max_hours (default 26).
# Usage: backup-freshness.sh [max_hours=26]
set -euo pipefail

MAX="${1:-26}"
for key in STACK_ENV BACKUP_RESTIC_REPOSITORY RESTIC_PASSWORD_FILE; do
  if [ -z "${!key:-}" ] && [ -f .env ]; then
    line="$(grep -E "^${key}=" .env | tail -n 1 || true)"
    [ -n "$line" ] && { val="${line#*=}"; val="${val%\"}"; val="${val#\"}"; export "$key=$val"; }
  fi
done
RESTIC_PASSWORD_FILE="${RESTIC_PASSWORD_FILE:-./secrets/restic_password}"
LOG_FILE="${BACKUP_LOG_FILE:-./logs/backup.log}"
mkdir -p "$(dirname "$LOG_FILE")"
[ -n "${BACKUP_RESTIC_REPOSITORY:-}" ] || { echo "BACKUP_RESTIC_REPOSITORY not set" >&2; exit 1; }

JSON="$(restic -r "$BACKUP_RESTIC_REPOSITORY" --password-file "$RESTIC_PASSWORD_FILE" snapshots --json --latest 1)" \
  || { echo "BACKUP STALE: repository unreadable" >&2; exit 1; }

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
  echo "BACKUP STALE: no snapshot" >&2; exit 1
fi
if python3 -c "import sys; sys.exit(0 if float('$AGE') <= float('$MAX') else 1)"; then
  echo "BACKUP FRESH: ${AGE}h"
else
  echo "BACKUP STALE: ${AGE}h" >&2
  printf '%s freshness[%s] STALE %sh\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "${STACK_ENV:-}" "$AGE" >> "$LOG_FILE"
  exit 1
fi
