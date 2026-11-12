#!/usr/bin/env bash
# Prepares a Family Hub stack directory on the server (idempotent, never prints secret values).
#
# Usage (self-contained, run as root on the server):
#   cat scripts/server-provision.sh | ssh my-server-agent 'sudo bash -s -- staging family-hub-stage.vostack.dev [--install-cron]'
#
#   server-provision.sh <staging|production> <app_domain> [--cert-email <addr>] [--install-cron] [--root <dir>]
#
# --root <dir>      dry-run mode: everything is created below <dir> (as <dir>/opt/..., <dir>/var/...) and
#                   chown, the repository setup and crontab are skipped. Used by scripts/test-server-provision.sh.
# --cert-email      address for Let's Encrypt (default: $CERT_EMAIL, else a placeholder you must edit in .env).
# --install-cron    adds the backup/cert/check cron lines for user `deploy` (existing entries are kept).
#
# The env templates below mirror ops/env/*.example key for key (the test checks this).
set -euo pipefail

usage() { echo "usage: $0 <staging|production> <app_domain> [--cert-email <addr>] [--install-cron] [--root <dir>]" >&2; exit 2; }

[ $# -ge 2 ] || usage
STACK_ENV="$1"; APP_DOMAIN="$2"; shift 2
case "$STACK_ENV" in staging|production) ;; *) usage ;; esac
case "$APP_DOMAIN" in
  ''|*[!A-Za-z0-9.-]*) echo "invalid app_domain" >&2; exit 2 ;;
esac

ROOT=""
INSTALL_CRON=0
CERT_EMAIL="${CERT_EMAIL:-}"
while [ $# -gt 0 ]; do
  case "$1" in
    --root) ROOT="${2:?--root needs a directory}"; shift 2 ;;
    --install-cron) INSTALL_CRON=1; shift ;;
    --cert-email) CERT_EMAIL="${2:?--cert-email needs an address}"; shift 2 ;;
    *) usage ;;
  esac
done
ROOT="${ROOT%/}"
DRY=0; [ -n "$ROOT" ] && DRY=1

if [ "$STACK_ENV" = staging ]; then
  STACK_SLUG=family-hub-staging; EDGE_ALIAS=fh-stage-nginx; STAGING_RESEED=1
  CRON_OFFSET=5
else
  STACK_SLUG=family-hub; EDGE_ALIAS=fh-prod-nginx; STAGING_RESEED=0
  CRON_OFFSET=0
fi
STACK_DIR_REL="/opt/$STACK_SLUG"
TARGET_DIR="$ROOT$STACK_DIR_REL"
COMPOSE_PROJECT_NAME="$STACK_SLUG"
RESTIC_REPO_REL="/var/backups/restic/$STACK_SLUG"
RESTIC_REPO="$ROOT$RESTIC_REPO_REL"
IMAGE_PREFIX="ghcr.io/thomasv88/family-hub"
CERT_EMAIL_PLACEHOLDER=0
if [ -z "$CERT_EMAIL" ]; then CERT_EMAIL="owner@example.com"; CERT_EMAIL_PLACEHOLDER=1; fi
case "$CERT_EMAIL" in *[!A-Za-z0-9.@_+-]*) echo "invalid cert email" >&2; exit 2 ;; esac

if [ "$DRY" = 0 ] && [ "$(id -u)" != 0 ]; then
  echo "run as root (sudo bash -s -- ...)" >&2; exit 1
fi
command -v openssl >/dev/null || { echo "openssl not found" >&2; exit 1; }

CREATED=(); KEPT=()
own() { [ "$DRY" = 1 ] || chown deploy:deploy "$1"; }
mkdir_own() {  # mkdir_own <dir> <mode>
  if [ -d "$1" ]; then KEPT+=("$1"); else CREATED+=("$1"); fi
  mkdir -p "$1"; chmod "$2" "$1"; own "$1"
}

umask 077
mkdir -p "$ROOT/opt" "$ROOT/var/backups/restic" 2>/dev/null || true
mkdir_own "$TARGET_DIR" 750
mkdir_own "$TARGET_DIR/secrets" 700
mkdir_own "$TARGET_DIR/logs" 750
mkdir_own "$TARGET_DIR/letsencrypt" 750
mkdir_own "$TARGET_DIR/postgres-init" 750

# The Cloudflare DNS token is placed by the owner (it is not generated here).
if [ ! -s "$TARGET_DIR/secrets/cloudflare.ini" ]; then
  cat >&2 <<EOF
STOP: $STACK_DIR_REL/secrets/cloudflare.ini is missing or empty.
Copy the DNS-edit token file of the existing stack (for example from /opt/work-tracker/secrets or
/opt/mail/secrets) in a root shell on the server, then re-run this script:
  install -o deploy -g deploy -m 600 <source cloudflare.ini> $STACK_DIR_REL/secrets/cloudflare.ini
The directories created so far are kept; the script is idempotent.
EOF
  exit 1
fi

gen_secret() {  # gen_secret <name>: random value into secrets/<name> only if absent; never printed
  local f="$TARGET_DIR/secrets/$1"
  if [ -s "$f" ]; then KEPT+=("secrets/$1"); return; fi
  openssl rand -base64 36 | tr -d '\n' > "$f"
  chmod 600 "$f"; own "$f"; CREATED+=("secrets/$1")
}
gen_secret db_root_password
gen_secret db_owner_password
gen_secret db_app_password
gen_secret restic_password
chmod 600 "$TARGET_DIR/secrets/cloudflare.ini"; own "$TARGET_DIR/secrets/cloudflare.ini"

write_file() {  # write_file <name>: stdin to <TARGET_DIR>/<name> only if absent (mode 0600)
  local f="$TARGET_DIR/$1"
  if [ -s "$f" ]; then KEPT+=("$1"); cat >/dev/null; return; fi
  cat > "$f"; chmod 600 "$f"; own "$f"; CREATED+=("$1")
}

# --- .env: compose interpolation only, no credentials ---
write_file .env <<EOF
# Compose interpolation and deploy.sh only. No credentials here (deploy.sh refuses them).
COMPOSE_PROJECT_NAME=$COMPOSE_PROJECT_NAME
EDGE_ALIAS=$EDGE_ALIAS
APP_DOMAIN=$APP_DOMAIN
IMAGE_PREFIX=$IMAGE_PREFIX
# staging or production
STACK_ENV=$STACK_ENV
# 1 only on the staging stack: wipes and reseeds the database on deploy
STAGING_RESEED=$STAGING_RESEED
CERT_EMAIL=$CERT_EMAIL
BACKUP_RESTIC_REPOSITORY=$RESTIC_REPO_REL
BACKUP_COPY_REPOSITORY=
MEM_POSTGRES=192m
MEM_APP=192m
MEM_WORKER=160m
MEM_SCHEDULER=128m
MEM_NGINX=48m
EOF

# --- app.env: runtime role fh_app only ---
if [ ! -s "$TARGET_DIR/app.env" ]; then
  APP_KEY_VALUE="base64:$(openssl rand -base64 32)"
  DB_APP_PW="$(cat "$TARGET_DIR/secrets/db_app_password")"
  write_file app.env <<EOF
# Laravel runtime for app, worker and scheduler. Runtime role fh_app only.
APP_NAME="Family Hub"
# staging or production
APP_ENV=$STACK_ENV
APP_KEY=$APP_KEY_VALUE
APP_DEBUG=false
APP_URL=https://$APP_DOMAIN
LOG_CHANNEL=stderr
DB_CONNECTION=pgsql
DB_HOST=postgres
DB_PORT=5432
DB_DATABASE=family_hub
DB_USERNAME=fh_app
DB_PASSWORD=$DB_APP_PW
SESSION_DRIVER=database
SESSION_SECURE_COOKIE=true
SESSION_SAME_SITE=lax
SESSION_HTTP_ONLY=true
CACHE_STORE=database
QUEUE_CONNECTION=database
EOF
  unset APP_KEY_VALUE DB_APP_PW
else
  KEPT+=("app.env")
fi

# --- migrate.env: owner (DDL) credentials, loaded only by the one-shot migrate service ---
if [ ! -s "$TARGET_DIR/migrate.env" ]; then
  DB_OWNER_PW="$(cat "$TARGET_DIR/secrets/db_owner_password")"
  write_file migrate.env <<EOF
# Owner (DDL) credentials. Loaded only by the one-shot migrate service.
DB_USERNAME=fh_owner
DB_PASSWORD=$DB_OWNER_PW
EOF
  unset DB_OWNER_PW
else
  KEPT+=("migrate.env")
fi

# --- restic repository ---
if [ -d "$RESTIC_REPO" ]; then KEPT+=("$RESTIC_REPO_REL"); else CREATED+=("$RESTIC_REPO_REL"); fi
mkdir -p "$RESTIC_REPO"; chmod 700 "$RESTIC_REPO"; own "$RESTIC_REPO"
if [ "$DRY" = 0 ]; then
  if [ ! -f "$RESTIC_REPO/config" ]; then
    command -v restic >/dev/null || { echo "restic not installed (apt-get install restic)" >&2; exit 1; }
    sudo -u deploy restic init --repo "$RESTIC_REPO" --password-file "$TARGET_DIR/secrets/restic_password" >/dev/null
    CREATED+=("backup repository initialised")
  else
    KEPT+=("backup repository initialised")
  fi
fi

# --- cron (deploy user) ---
cron_block() {
  local d="$STACK_DIR_REL" o="$CRON_OFFSET"
  printf '# family-hub %s (managed by server-provision.sh)\n' "$d"
  printf '%s 3 * * *  cd %s && bash ./backup.sh nightly >> logs/cron.log 2>&1\n' $((45 + o)) "$d"
  printf '%s 4 * * *  cd %s && bash ./cert-renew.sh renew >> logs/cron.log 2>&1\n' $((25 + o)) "$d"
  printf '%s 5 * * 0   cd %s && restic -r "$(grep -E '"'"'^BACKUP_RESTIC_REPOSITORY='"'"' .env | cut -d= -f2-)" --password-file ./secrets/restic_password check >> logs/cron.log 2>&1\n' "$o" "$d"
  printf '%s 5 1 * *  cd %s && bash ./restore-drill.sh >> logs/cron.log 2>&1\n' $((30 + o)) "$d"
  printf '%s 9 * * *   cd %s && bash ./backup-freshness.sh >> logs/cron.log 2>&1\n' "$o" "$d"
}
if [ "$INSTALL_CRON" = 1 ] && [ "$DRY" = 0 ]; then
  existing="$(crontab -u deploy -l 2>/dev/null || true)"
  if printf '%s\n' "$existing" | grep -qF "# family-hub $STACK_DIR_REL "; then
    KEPT+=("crontab block")
  else
    { [ -z "$existing" ] || printf '%s\n' "$existing"; cron_block; } | crontab -u deploy -
    CREATED+=("crontab block")
  fi
elif [ "$INSTALL_CRON" = 1 ]; then
  cron_block > "$TARGET_DIR/logs/cron.preview"
  CREATED+=("logs/cron.preview (dry run)")
fi

echo "Provisioned $STACK_ENV stack at $STACK_DIR_REL (names only, no values):"
for x in "${CREATED[@]:-}"; do [ -n "$x" ] && echo "  created: $x"; done
for x in "${KEPT[@]:-}"; do [ -n "$x" ] && echo "  kept:    $x"; done
if [ "$CERT_EMAIL_PLACEHOLDER" = 1 ]; then
  echo "WARNING: CERT_EMAIL in $STACK_DIR_REL/.env is a placeholder; set a real address before the first certificate."
fi
echo "REMINDER: copy secrets/restic_password to the owner's password manager. Read it in a root shell on the"
echo "          server (this script never prints it); without it the backups cannot be restored."
