#!/usr/bin/env bash
# Static check of compose.server.yaml against the env templates. No containers are started.
set -euo pipefail
cd "$(dirname "$0")/.."

TMP=$(mktemp -d)
trap 'rm -rf "$TMP"' EXIT

cp ops/env/compose.env.example "$TMP/.env"
cp ops/env/app.env.example "$TMP/app.env"
cp ops/env/migrate.env.example "$TMP/migrate.env"
cp ops/env/ai-worker.env.example "$TMP/ai-worker.env"
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


for s in ("app", "nginx", "worker", "scheduler", "postgres", "ai-worker"):
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

# ai-worker isolation (Phase 8): the only holder of the Codex login, read-only medical, no app secrets, single process
ai = svcs["ai-worker"]
if "ports" in ai:
    fail("ai-worker publishes ports")
if ai.get("init") is not True:
    fail("ai-worker has no init: true")
if (ai.get("deploy") or {}).get("replicas") not in (None, 1):
    fail("ai-worker must not be scaled")
am = [(v.get("source"), v.get("target"), bool(v.get("read_only"))) for v in ai.get("volumes", [])]
if ("codex-home", "/codex", False) not in am:
    fail("ai-worker does not mount codex-home at /codex")
if ("medical", MED, True) not in am:
    fail("ai-worker does not mount medical read-only")
if any(src == "uploads" or tgt == WISH for src, tgt, _ in am):
    fail("ai-worker mounts the uploads volume")
if len(am) != 2:
    fail("ai-worker has volumes besides codex-home and medical")
for name, svc in svcs.items():
    if name != "ai-worker" and any(src == "codex-home" or tgt == "/codex" for src, tgt in mounts(svc)):
        fail("%s mounts codex-home" % name)
aenv = ai.get("environment") or {}
for key in aenv:
    if re.match(r"APP_KEY$|MAIL_|GOOGLE_|VAPID_|.*_API_KEY$|REDIS", key):
        fail("ai-worker gets forbidden variable %s" % key)
if aenv.get("DB_USERNAME") != "fh_app":
    fail("ai-worker does not use the runtime role fh_app")
if aenv.get("DB_QUEUE_RETRY_AFTER") != "900":
    fail("ai-worker DB_QUEUE_RETRY_AFTER is not 900")
if "queue=ai-control,ai " not in " ".join(ai.get("command", [])):
    fail("ai-worker does not work ai-control before ai")
if not any("/ai-work" in t for t in (ai.get("tmpfs") or [])):
    fail("ai-worker has no tmpfs work dir")
for name, svc in svcs.items():
    if name == "ai-worker":
        continue
    if "ai-worker.env" in json.dumps(svc.get("env_file", "")):
        fail("%s reads ai-worker.env" % name)
PY

# Static rules on the raw files (compose inlines env_file in its rendered config)
python3 -I - <<'PY'
import re, sys


def fail(msg):
    print("FAIL: " + msg, file=sys.stderr)
    sys.exit(1)


raw = open("compose.server.yaml").read()
block = raw.split("\n  ai-worker:\n", 1)[1].split("\n  scheduler:\n", 1)[0]
if not re.search(r"^    env_file: ai-worker\.env$", block, re.M):
    fail("ai-worker env_file is not exactly ai-worker.env")
if "app.env" in block.replace("never app.env", ""):
    fail("ai-worker block mentions app.env")
for line in open("ops/env/ai-worker.env.example"):
    if line.lstrip().startswith("#"):
        continue
    if re.match(r"\s*(\w*_API_KEY|APP_KEY|MAIL_\w*|GOOGLE_\w*|VAPID_\w*)\s*=", line):
        fail("ai-worker.env.example sets a forbidden variable: " + line.strip())
for f in ("scripts/backup.sh", "scripts/restore-drill.sh", "scripts/backup-freshness.sh"):
    if "codex-home" in open(f).read():
        fail("%s mentions codex-home (credentials must never be backed up)" % f)
df = open("docker/app/Dockerfile").read()
if df.count("COPY --from=codex") != 1:
    fail("COPY --from=codex must appear exactly once")
if df.index("COPY --from=codex") < df.index("AS ai-runtime"):
    fail("COPY --from=codex appears before the ai-runtime stage")
deploy = open("scripts/deploy.sh").read()
up_lists = re.findall(r"up -d --wait[^\n]*", deploy)
services = re.findall(r"^  ([a-z][a-z-]*):\n", raw.split("\nservices:\n", 1)[1].split("\nnetworks:\n", 1)[0], re.M)
long_running = [s for s in services if s not in ("postgres", "migrate", "certbot")]
for line in up_lists:
    if "postgres" in line.split("up -d --wait")[1].split()[:1]:
        continue
    for s in long_running:
        if s not in line.split():
            fail("deploy.sh up list lacks service %s: %s" % (s, line.strip()))
PY


# compose.yaml (dev): the same rule with the dev volume names
DEV="$TMP/dev.json"
docker compose -f compose.yaml --project-directory "$PWD" --profile ai config --format json > "$DEV" 2>/dev/null || \
  docker compose -f compose.yaml --profile ai config --format json > "$DEV"
python3 -I - "$DEV" <<'PY'
import json, re, sys

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
# The dev ai-worker gets its own env file and a masked .env, so dev exercises the isolation production relies on
dev_ai = svcs.get("ai-worker", {})
for key in dev_ai.get("environment") or {}:
    if re.match(r"MAIL_|GOOGLE_|VAPID_|AWS_|STRIPE_|.*_API_KEY$|.*_SECRET$|REDIS", key):
        print("FAIL: dev ai-worker gets forbidden variable %s" % key, file=sys.stderr)
        sys.exit(1)
if (dev_ai.get("environment") or {}).get("APP_KEY") not in (None, ""):
    print("FAIL: dev ai-worker has an APP_KEY", file=sys.stderr)
    sys.exit(1)
if not any(v.get("target") == "/var/www/html/.env" and v.get("source") == "/dev/null" for v in dev_ai.get("volumes", [])):
    print("FAIL: dev ai-worker does not mask the repo .env", file=sys.stderr)
    sys.exit(1)
PY

echo "CHECK-COMPOSE OK"
