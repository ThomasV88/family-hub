#!/usr/bin/env bash
# End-to-end local proof of backup (database + uploads + medical snapshots), off-site copy, restore drill and
# freshness check against a throwaway Postgres and uploads volume (own compose project, own volumes).
# Run from the repository root.
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
restic_c() { restic -r "$COPY_REPO" --password-file "$COPY_PW" "$@"; }
# count_snaps <repo-fn> <path> <tags comma list|-> <excluded tag|->: snapshots with that path, all tags, not the excluded tag
count_snaps() {
  "$1" snapshots --json | python3 -c '
import json, sys
path, tags, excl = sys.argv[1], [t for t in sys.argv[2].split(",") if t != "-"], sys.argv[3]
s = json.load(sys.stdin)
print(len([x for x in s if path in x.get("paths", []) and all(t in x["tags"] for t in tags) and (excl == "-" or excl not in x["tags"])]))
' "$2" "$3" "$4"
}
newest_time() {
  "$1" snapshots --json | python3 -c '
import json, sys
s = [x for x in json.load(sys.stdin) if sys.argv[1] in x.get("paths", [])]
print(max(x["time"] for x in s) if s else "")
' "$2"
}
total_snaps() { "$1" snapshots --json | python3 -c 'import json,sys; print(len(json.load(sys.stdin)))'; }
newest_db_id() {
  restic_r snapshots --json | python3 -c '
import json, sys
s = [x for x in json.load(sys.stdin) if "/family_hub.dump" in x.get("paths", []) and "manual" in x["tags"]]
print(max(s, key=lambda x: x["time"])["short_id"])
'
}
newest_medical_id() {
  restic_r snapshots --json | python3 -c '
import json, sys
s = [x for x in json.load(sys.stdin) if "/medical.tar" in x.get("paths", [])]
print(max(s, key=lambda x: x["time"])["short_id"])
'
}
newest_uploads_id() {
  restic_r snapshots --json | python3 -c '
import json, sys
s = [x for x in json.load(sys.stdin) if "/uploads.tar" in x.get("paths", [])]
print(max(s, key=lambda x: x["time"])["short_id"])
'
}

cleanup() {
  dc down -v --remove-orphans >/dev/null 2>&1 || true
  rm -rf "$TMP"
}
trap cleanup EXIT

dc up -d --wait postgres >/dev/null
psql_live "drop table if exists backup_test_marker; create table backup_test_marker(id int primary key, note text); insert into backup_test_marker values (1,'marker');" >/dev/null

# marker file in the uploads volume (private wish images)
dc run --rm --no-deps -T app sh -c 'echo marker > storage/app/private/wish-images/backup-marker.txt' >/dev/null

# marker file in the medical volume (private medical documents)
dc run --rm --no-deps -T app sh -c 'mkdir -p storage/app/medical/subject-1 && echo medical-marker > storage/app/medical/subject-1/backup-marker.txt' >/dev/null

restic_r init >/dev/null
restic -r "$COPY_REPO" --password-file "$COPY_PW" init >/dev/null

# 1. backup creates one database snapshot (manual+local) and one uploads snapshot (uploads+uploads-manual+local, not manual)
bash scripts/backup.sh manual >/dev/null || fail "backup.sh manual failed"
n=$(count_snaps restic_r /family_hub.dump manual,local -)
[ "$n" = "1" ] || fail "expected one database snapshot tagged manual+local, got '$n'"
n=$(count_snaps restic_r /uploads.tar uploads,uploads-manual,local manual)
[ "$n" = "1" ] || fail "expected one uploads snapshot tagged uploads+uploads-manual+local without manual, got '$n'"
n=$(count_snaps restic_r /medical.tar medical,medical-manual,local manual)
[ "$n" = "1" ] || fail "expected one medical snapshot tagged medical+medical-manual+local without manual, got '$n'"
[ "$(total_snaps restic_r)" = "3" ] || fail "expected 3 snapshots in total"

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
[ "$(count_snaps restic_c /family_hub.dump - -)" -ge 1 ] || fail "copy repo has no database snapshot"
[ "$(count_snaps restic_c /uploads.tar - -)" -ge 1 ] || fail "copy repo has no uploads snapshot"
[ "$(count_snaps restic_c /medical.tar - -)" -ge 1 ] || fail "copy repo has no medical snapshot"
# the ids of this run were copied (same snapshot time), not just any latest snapshot
[ "$(newest_time restic_c /family_hub.dump)" = "$(newest_time restic_r /family_hub.dump)" ] || fail "newest database snapshot differs between primary and copy"
[ "$(newest_time restic_c /uploads.tar)" = "$(newest_time restic_r /uploads.tar)" ] || fail "newest uploads snapshot differs between primary and copy"
[ "$(newest_time restic_c /medical.tar)" = "$(newest_time restic_r /medical.tar)" ] || fail "newest medical snapshot differs between primary and copy"
# drill against the copy repository
cout=$(BACKUP_RESTIC_REPOSITORY="$COPY_REPO" RESTIC_PASSWORD_FILE="$COPY_PW" DRILL_TAG=manual DRILL_CHECK_TABLE=backup_test_marker bash scripts/restore-drill.sh) \
  || fail "drill against the copy repository failed"
echo "$cout" | grep -q 'RESTORE-DRILL UPLOADS OK' || fail "copy drill: uploads not proven: $cout"
echo "$cout" | tail -n 1 | grep -q '^RESTORE-DRILL OK tables=' || fail "copy drill: final line unexpected: $cout"
echo "$cout" | grep -q 'RESTORE-DRILL MEDICAL OK' || fail "copy drill: medical not proven: $cout"
[ "$(restic_c dump --tag uploads-manual --path /uploads.tar latest /uploads.tar | tar -xOf - ./backup-marker.txt)" = "marker" ] \
  || fail "marker not restorable from the copy repository"
[ "$(restic_c dump --tag medical-manual --path /medical.tar latest /medical.tar | tar -xOf - ./subject-1/backup-marker.txt)" = "medical-marker" ] \
  || fail "medical marker not restorable from the copy repository"

# 5. pg_dump failure -> non-zero and no new snapshot
before=$(total_snaps restic_r)
if DB_NAME=does_not_exist bash scripts/backup.sh manual >/dev/null 2>&1; then
  fail "backup succeeded with missing database"
fi
after=$(total_snaps restic_r)
[ "$before" = "$after" ] || fail "snapshot created despite pg_dump failure ($before -> $after)"

# 6. restore drill
out=$(DRILL_TAG=manual DRILL_CHECK_TABLE=backup_test_marker bash scripts/restore-drill.sh) || fail "restore drill failed"
echo "$out" | tail -n 1 | grep -q '^RESTORE-DRILL OK tables=' || fail "drill output unexpected: $out"
# the uploads snapshot is now the newest of any kind; the drill must still have restored the database snapshot
echo "$out" | tail -n 1 | grep -q "snapshot=$(newest_db_id)\$" || fail "drill did not restore the newest database snapshot: $out"
echo "$out" | grep -q 'RESTORE-DRILL UPLOADS OK files=' || fail "drill did not prove uploads: $out"
echo "$out" | grep -q 'RESTORE-DRILL MEDICAL OK files=' || fail "drill did not prove medical: $out"
[ "$(restic_r dump --tag medical-manual --path /medical.tar latest /medical.tar | tar -xOf - ./subject-1/backup-marker.txt)" = "medical-marker" ] \
  || fail "medical marker not restorable from the primary repository"
[ "$(restic_r dump --tag uploads-manual --path /uploads.tar latest /uploads.tar | tar -xOf - ./backup-marker.txt)" = "marker" ] \
  || fail "marker not restorable from the primary repository"
live_tables=$(psql_live "select count(*) from information_schema.tables where table_schema='public'")
echo "$out" | grep -q "tables=$live_tables " || fail "table count mismatch: $out vs $live_tables"
[ "$(psql_live "select count(*) from pg_database where datname='family_hub_restore_test'")" = "0" ] \
  || fail "scratch database still exists"

# 6b. an uploads snapshot id is refused as explicit drill argument
if uout=$(DRILL_TAG=manual bash scripts/restore-drill.sh "$(newest_uploads_id)" 2>&1); then
  fail "drill accepted an uploads snapshot id"
fi
echo "$uout" | grep -q 'not a database snapshot' || fail "unexpected refusal message: $uout"

# 6b2. a medical snapshot id is refused as explicit drill argument
if mout=$(DRILL_TAG=manual bash scripts/restore-drill.sh "$(newest_medical_id)" 2>&1); then
  fail "drill accepted a medical snapshot id"
fi
echo "$mout" | grep -q 'not a database snapshot' || fail "unexpected refusal message: $mout"

# 6c. uploads tar failure -> non-zero, no new uploads snapshot, FAILED: uploads logged
ubefore=$(count_snaps restic_r /uploads.tar - -)
if UPLOADS_PATH=/nonexistent-fh bash scripts/backup.sh manual >/dev/null 2>&1; then
  fail "backup succeeded with unreadable uploads path"
fi
[ "$(count_snaps restic_r /uploads.tar - -)" = "$ubefore" ] || fail "uploads snapshot created despite tar failure"
grep -q 'FAILED: uploads' "$BACKUP_LOG_FILE" || fail "no 'FAILED: uploads' log line"

# 6c2. medical tar failure -> non-zero, no new medical snapshot, the database and uploads snapshots of that run are still written
mbefore=$(count_snaps restic_r /medical.tar - -)
dbefore=$(count_snaps restic_r /family_hub.dump - -)
ubefore=$(count_snaps restic_r /uploads.tar - -)
if MEDICAL_PATH=/nonexistent-fh bash scripts/backup.sh manual >/dev/null 2>&1; then
  fail "backup succeeded with unreadable medical path"
fi
[ "$(count_snaps restic_r /medical.tar - -)" = "$mbefore" ] || fail "medical snapshot created despite tar failure"
[ "$(count_snaps restic_r /family_hub.dump - -)" = "$((dbefore + 1))" ] || fail "database snapshot missing after medical failure"
[ "$(count_snaps restic_r /uploads.tar - -)" = "$((ubefore + 1))" ] || fail "uploads snapshot missing after medical failure"
grep -q 'FAILED: medical' "$BACKUP_LOG_FILE" || fail "no 'FAILED: medical' log line"

# 6d. pre-migrate: database snapshot only
pbefore=$(count_snaps restic_r /uploads.tar - -)
pmbefore=$(count_snaps restic_r /medical.tar - -)
bash scripts/backup.sh pre-migrate >/dev/null || fail "backup.sh pre-migrate failed"
[ "$(count_snaps restic_r /family_hub.dump pre-migrate -)" = "1" ] || fail "no pre-migrate database snapshot"
[ "$(count_snaps restic_r /uploads.tar - -)" = "$pbefore" ] || fail "pre-migrate created an uploads snapshot"
[ "$(count_snaps restic_r /medical.tar - -)" = "$pmbefore" ] || fail "pre-migrate created a medical snapshot"

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
fout=$(bash scripts/backup-freshness.sh) || fail "freshness failed right after nightly backup"
echo "$fout" | grep -q 'uploads=' || fail "freshness output lacks uploads age: $fout"
echo "$fout" | grep -q 'medical=' || fail "freshness output lacks medical age: $fout"
dout=$(DRILL_CHECK_TABLE=backup_test_marker bash scripts/restore-drill.sh) || fail "default drill (nightly snapshot) failed"
echo "$dout" | grep -q '^RESTORE-DRILL OK' || fail "default drill output unexpected: $dout"
echo "$dout" | grep -q 'RESTORE-DRILL UPLOADS OK' || fail "default drill did not prove uploads: $dout"
echo "$dout" | grep -q 'RESTORE-DRILL MEDICAL OK' || fail "default drill did not prove medical: $dout"
# shellcheck disable=SC2046
restic_r forget --prune $(restic_r snapshots --json --tag uploads-nightly | python3 -c 'import json,sys; print(" ".join(x["short_id"] for x in json.load(sys.stdin)))') >/dev/null
if eout=$(bash scripts/backup-freshness.sh 2>&1); then
  fail "freshness passed without an uploads-nightly snapshot"
fi
echo "$eout" | grep -q 'no nightly uploads snapshot' || fail "unexpected freshness message: $eout"
# freshness also needs a medical-nightly snapshot (the uploads one is back from the second nightly below)
bash scripts/backup.sh nightly >/dev/null || fail "second backup.sh nightly failed"
bash scripts/backup-freshness.sh >/dev/null || fail "freshness failed after uploads snapshot returned"
# without a medical-nightly snapshot freshness fails with its own message
# shellcheck disable=SC2046
restic_r forget --prune $(restic_r snapshots --json --tag medical-nightly | python3 -c 'import json,sys; print(" ".join(x["short_id"] for x in json.load(sys.stdin)))') >/dev/null
if mfout=$(bash scripts/backup-freshness.sh 2>&1); then
  fail "freshness passed without a medical-nightly snapshot"
fi
echo "$mfout" | grep -q 'no nightly medical snapshot' || fail "unexpected freshness message: $mfout"
bash scripts/backup.sh nightly >/dev/null || fail "third backup.sh nightly failed"
bash scripts/backup-freshness.sh >/dev/null || fail "freshness failed after medical snapshot returned"
if bash scripts/backup-freshness.sh 0 >/dev/null 2>&1; then
  fail "freshness with max_hours=0 should fail"
fi

echo "TEST-BACKUP OK"
