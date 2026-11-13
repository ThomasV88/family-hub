#!/usr/bin/env bash
# End-to-end local proof of backup, restore drill and freshness check against a throwaway
# Postgres (own compose project, own volume). Run from the repository root.
set -euo pipefail

cd "$(dirname "$0")/.."
command -v restic >/dev/null || { echo "restic not found in PATH" >&2; exit 1; }

export COMPOSE_PROJECT_NAME="${TEST_BACKUP_PROJECT:-fh0106}"
export COMPOSE_FILE=compose.yaml
export STACK_ENV=local
export DB_NAME=family_hub
export DB_DUMP_USER=fh_owner

TMP="$(mktemp -d)"
export BACKUP_LOG_FILE="$TMP/backup.log"
export BACKUP_RESTIC_REPOSITORY="$TMP/repo"
export RESTIC_PASSWORD_FILE="$TMP/pw"
COPY_REPO="$TMP/copy"
COPY_PW="$TMP/pw-copy"
printf 'test-password-one' > "$RESTIC_PASSWORD_FILE"
printf 'test-password-two' > "$COPY_PW"
chmod 600 "$RESTIC_PASSWORD_FILE" "$COPY_PW"

dc() { docker compose -f "$COMPOSE_FILE" "$@"; }
psql_live() { dc exec -T postgres psql -U fh_owner -d "$DB_NAME" -v ON_ERROR_STOP=1 -qtAc "$1"; }
fail() { echo "TEST-BACKUP FAIL: $*" >&2; exit 1; }
restic_r() { restic -r "$BACKUP_RESTIC_REPOSITORY" --password-file "$RESTIC_PASSWORD_FILE" "$@"; }

cleanup() {
  dc down -v --remove-orphans >/dev/null 2>&1 || true
  rm -rf "$TMP"
}
trap cleanup EXIT

dc up -d --wait postgres >/dev/null
psql_live "drop table if exists backup_test_marker; create table backup_test_marker(id int primary key, note text); insert into backup_test_marker values (1,'marker');" >/dev/null

restic_r init >/dev/null
restic -r "$COPY_REPO" --password-file "$COPY_PW" init >/dev/null

# 1. backup creates exactly one snapshot tagged manual and local
bash scripts/backup.sh manual >/dev/null || fail "backup.sh manual failed"
n=$(restic_r snapshots --json | python3 -c 'import json,sys; s=json.load(sys.stdin); print(len([x for x in s if "manual" in x["tags"] and "local" in x["tags"]]), len(s))')
[ "$n" = "1 1" ] || fail "expected one snapshot tagged manual+local, got '$n'"

# 2. repository is encrypted: wrong password fails
printf 'wrong' > "$TMP/pw-wrong"
if restic -r "$BACKUP_RESTIC_REPOSITORY" --password-file "$TMP/pw-wrong" snapshots >/dev/null 2>&1; then
  fail "wrong password was accepted"
fi

# 3. no plaintext dump/sql anywhere
if find "$TMP" -type f \( -name '*.dump' -o -name '*.sql' \) | grep -q .; then
  fail "plaintext dump file found"
fi

# 4. off-site copy
BACKUP_COPY_REPOSITORY="$COPY_REPO" BACKUP_COPY_PASSWORD_FILE="$COPY_PW" bash scripts/backup.sh manual >/dev/null \
  || fail "backup with copy repo failed"
c=$(restic -r "$COPY_REPO" --password-file "$COPY_PW" snapshots --json | python3 -c 'import json,sys; print(len(json.load(sys.stdin)))')
[ "$c" -ge 1 ] || fail "copy repo has no snapshot"

# 5. pg_dump failure -> non-zero and no new snapshot
before=$(restic_r snapshots --json | python3 -c 'import json,sys; print(len(json.load(sys.stdin)))')
if DB_NAME=does_not_exist bash scripts/backup.sh manual >/dev/null 2>&1; then
  fail "backup succeeded with missing database"
fi
after=$(restic_r snapshots --json | python3 -c 'import json,sys; print(len(json.load(sys.stdin)))')
[ "$before" = "$after" ] || fail "snapshot created despite pg_dump failure ($before -> $after)"

# 6. restore drill
out=$(DRILL_TAG=manual DRILL_CHECK_TABLE=backup_test_marker bash scripts/restore-drill.sh) || fail "restore drill failed"
echo "$out" | grep -q '^RESTORE-DRILL OK tables=' || fail "drill output unexpected: $out"
live_tables=$(psql_live "select count(*) from information_schema.tables where table_schema='public'")
echo "$out" | grep -q "tables=$live_tables " || fail "table count mismatch: $out vs $live_tables"
[ "$(psql_live "select count(*) from pg_database where datname='family_hub_restore_test'")" = "0" ] \
  || fail "scratch database still exists"

# 7. stale scratch DB from a crashed run is dropped first
dc exec -T postgres psql -U postgres -d postgres -qc "create database family_hub_restore_test owner fh_owner" >/dev/null
DRILL_TAG=manual DRILL_CHECK_TABLE=backup_test_marker bash scripts/restore-drill.sh | grep -q '^RESTORE-DRILL OK' \
  || fail "drill did not recover from stale scratch DB"

# 8. drill refuses the live database name
if DRILL_DB="$DB_NAME" bash scripts/restore-drill.sh >/dev/null 2>&1; then
  fail "drill accepted the live DB as target"
fi

# 8b. drill refuses a target name that is not a *_restore_test scratch name
if DRILL_DB="postgres" bash scripts/restore-drill.sh >/dev/null 2>&1; then
  fail "drill accepted an arbitrary DRILL_DB"
fi

# 9. freshness: only nightly snapshots count, a newer manual snapshot must not mask a missing nightly
if bash scripts/backup-freshness.sh >/dev/null 2>&1; then
  fail "freshness passed with manual snapshots only"
fi
ALERT_LOG=$(mktemp)
printf '#!/bin/sh\necho "$1" >> "%s"\n' "$ALERT_LOG" > "$ALERT_LOG.sh"; chmod +x "$ALERT_LOG.sh"
BACKUP_ALERT_COMMAND="$ALERT_LOG.sh" bash scripts/backup-freshness.sh >/dev/null 2>&1 || true
grep -q 'BACKUP STALE' "$ALERT_LOG" || fail "alert hook was not called on stale"
rm -f "$ALERT_LOG" "$ALERT_LOG.sh"
bash scripts/backup.sh nightly >/dev/null || fail "backup.sh nightly failed"
bash scripts/backup-freshness.sh >/dev/null || fail "freshness failed right after nightly backup"
DRILL_CHECK_TABLE=backup_test_marker bash scripts/restore-drill.sh | grep -q '^RESTORE-DRILL OK' \
  || fail "default drill (nightly snapshot) failed"
if bash scripts/backup-freshness.sh 0 >/dev/null 2>&1; then
  fail "freshness with max_hours=0 should fail"
fi

echo "TEST-BACKUP OK"
