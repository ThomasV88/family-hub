#!/usr/bin/env bash
# Static check of compose.server.yaml against the env templates. No containers are started.
set -euo pipefail
cd "$(dirname "$0")/.."

TMP=$(mktemp -d)
trap 'rm -rf "$TMP"' EXIT

cp ops/env/compose.env.example "$TMP/.env"
cp ops/env/app.env.example "$TMP/app.env"
cp ops/env/migrate.env.example "$TMP/migrate.env"
echo "IMAGE_TAG=check" >> "$TMP/.env"
mkdir -p "$TMP/secrets" "$TMP/postgres-init" "$TMP/letsencrypt"
for s in db_root_password db_owner_password db_app_password restic_password; do echo dummy > "$TMP/secrets/$s"; done
echo dummy > "$TMP/secrets/cloudflare.ini"

OUT="$TMP/config.json"
docker compose -f compose.server.yaml --project-directory "$TMP" --env-file "$TMP/.env" \
  --profile migrate --profile certbot config --format json > "$OUT"

command -v python3 >/dev/null || { echo "FAIL: python3 is required" >&2; exit 1; }
python3 -I - "$OUT" <<'PY'
import json, re, sys

cfg = json.load(open(sys.argv[1]))
svcs = cfg["services"]


def fail(msg):
    print("FAIL: " + msg, file=sys.stderr)
    sys.exit(1)


for s in ("app", "nginx", "worker", "scheduler", "postgres"):
    if s not in svcs:
        fail("service %s missing" % s)
    if not svcs[s].get("mem_limit"):
        fail("service %s has no mem_limit" % s)
for name, svc in svcs.items():
    if "ports" in svc:
        fail("service %s publishes ports" % name)
if "fh-stage-nginx" not in svcs["nginx"]["networks"]["edge"]["aliases"]:
    fail("nginx edge alias missing")
if cfg["networks"]["edge"].get("external") is not True:
    fail("edge network is not external")
if "migrate" not in svcs["migrate"].get("profiles", []):
    fail("migrate profile missing")
for name, svc in svcs.items():
    for key in svc.get("environment", {}) or {}:
        if re.search(r"_API_KEY$|REDIS", key):
            fail("forbidden environment key %s in %s" % (key, name))
for s in ("app", "worker", "scheduler", "nginx", "postgres"):
    if (svcs[s].get("environment") or {}).get("DB_USERNAME") == "fh_owner":
        fail("%s sees the owner role" % s)
if svcs["migrate"]["environment"].get("DB_USERNAME") != "fh_owner":
    fail("migrate does not use the owner role")
WISH = "/var/www/html/storage/app/private/wish-images"


def mounts(svc):
    return [(v.get("source"), v.get("target")) for v in svc.get("volumes", [])]


for s in ("app", "worker", "scheduler"):
    if ("uploads", WISH) not in mounts(svcs[s]):
        fail("%s does not mount volume uploads at %s" % (s, WISH))
for s in ("nginx", "postgres"):
    if any(src == "uploads" or tgt == WISH for src, tgt in mounts(svcs[s])):
        fail("%s mounts the uploads volume" % s)
MED = "/var/www/html/storage/app/medical"
for s in ("app", "worker", "scheduler"):
    if ("medical", MED) not in mounts(svcs[s]):
        fail("%s does not mount volume medical at %s" % (s, MED))
    if ("uploads", WISH) not in mounts(svcs[s]):
        fail("%s lost the uploads mount" % s)
for s in ("nginx", "postgres"):
    if any(src == "medical" or tgt == MED for src, tgt in mounts(svcs[s])):
        fail("%s mounts the medical volume" % s)
PY


# compose.yaml (dev): the same rule with the dev volume names
DEV="$TMP/dev.json"
docker compose -f compose.yaml --project-directory "$PWD" config --format json > "$DEV" 2>/dev/null || \
  docker compose -f compose.yaml config --format json > "$DEV"
python3 -I - "$DEV" <<'PY'
import json, sys

svcs = json.load(open(sys.argv[1]))["services"]
MED = "/var/www/html/storage/app/medical"


def mounts(svc):
    return [(v.get("source"), v.get("target")) for v in svc.get("volumes", [])]


for s in ("app", "worker", "scheduler"):
    if ("medical-dev", MED) not in mounts(svcs[s]):
        print("FAIL: dev %s does not mount medical-dev at %s" % (s, MED), file=sys.stderr)
        sys.exit(1)
for s in ("nginx", "postgres"):
    if any(src == "medical-dev" or tgt == MED for src, tgt in mounts(svcs[s])):
        print("FAIL: dev %s mounts the medical volume" % s, file=sys.stderr)
        sys.exit(1)
PY

echo "CHECK-COMPOSE OK"
