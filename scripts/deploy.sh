#!/usr/bin/env bash
# Server deploy for the production and staging stacks. Run from the stack directory:
#   IMAGE_TAG=sha-<commit> bash ./deploy.sh
# Exit 0 only when the stack is healthy.
#
# Order: guards -> pull -> postgres -> encrypted pre-migrate backup (restic, backup.sh) -> migrate
#        (one-shot owner-role container) -> [staging reseed + audit] -> certificate -> up (healthchecks)
#        -> .last-good-tag. Migrations never run without a fresh backup. If `up` fails, the previous
#        good tag is started again. Migrations are additive in this project; a destructive migration
#        needs a manual restore from the `pre-migrate` restic snapshot (restore-drill.sh documents it).
# Never prints the content of .env, app.env, migrate.env or secrets/*.
# With DEPLOY_SH_SOURCE_ONLY=1 only the guard functions are defined (used by test-deploy-guards.sh).
set -euo pipefail

log() { printf '%s %s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$*"; }
err() { printf '%s %s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$*" >&2; }

# Reads KEY=value from .env without sourcing it.
env_get() { sed -n "s/^$1=//p" .env 2>/dev/null | head -n1 | tr -d "'\"\r"; }
appenv_get() { sed -n "s/^$1=//p" app.env 2>/dev/null | head -n1 | tr -d "'\"\r "; }

REQUIRED_FILES=(.env app.env migrate.env compose.server.yaml backup.sh postgres-init/01-roles.sh)
REQUIRED_SECRETS=(secrets/db_root_password secrets/db_owner_password secrets/db_app_password secrets/restic_password secrets/cloudflare.ini)

guard_inputs() {
  if [ -z "${IMAGE_TAG:-}" ]; then
    err "IMAGE_TAG is missing (expected sha-<commit>). Nothing deployed."
    return 1
  fi
  if ! [[ "$IMAGE_TAG" =~ ^sha-[0-9a-f]{7,40}$ ]]; then
    err "IMAGE_TAG does not match ^sha-[0-9a-f]{7,40}\$. Nothing deployed."
    return 1
  fi
  local f bad=0
  for f in "${REQUIRED_FILES[@]}" "${REQUIRED_SECRETS[@]}"; do
    if [ ! -s "$f" ]; then
      err "Missing or empty required file: $f"
      bad=1
    fi
  done
  local key
  for key in COMPOSE_PROJECT_NAME EDGE_ALIAS APP_DOMAIN IMAGE_PREFIX STACK_ENV; do
    if [ -z "$(env_get "$key")" ]; then
      err "$key is missing in .env"
      bad=1
    fi
  done
  [ "$bad" = 0 ] || { err "Nothing deployed."; return 1; }
}

# .env is read by compose and this script only; credentials belong in app.env, migrate.env or secrets/.
guard_env_has_no_passwords() {
  # Keys ending in _FILE (RESTIC_PASSWORD_FILE, BACKUP_COPY_PASSWORD_FILE) hold paths, not secrets.
  if grep -v '^[[:space:]]*#' .env 2>/dev/null | grep -Ei '^[^=]*(PASSWORD|APP_KEY)[^=]*=' \
    | grep -Eivq '^[[:space:]]*[A-Za-z0-9_]*_FILE[[:space:]]*='; then
    err ".env contains PASSWORD or APP_KEY lines; they belong in app.env, migrate.env or secrets/. Nothing deployed."
    return 1
  fi
}

# secrets/* must be exactly 0600; the env files must not be accessible to others.
guard_secrets() {
  local f mode bad=0
  for f in "${REQUIRED_SECRETS[@]}"; do
    [ -e "$f" ] || continue
    mode=$(stat -c %a "$f")
    if [ "$mode" != 600 ]; then
      err "$f has mode $mode (expected 600)"
      bad=1
    fi
  done
  for f in .env app.env migrate.env; do
    [ -e "$f" ] || continue
    mode=$(stat -c %a "$f")
    if [ $((8#$mode & 8#007)) != 0 ]; then
      err "$f is accessible to others (mode $mode)"
      bad=1
    fi
  done
  [ "$bad" = 0 ] || { err "Nothing deployed."; return 1; }
}

# A staging reseed wipes the database. It needs staging in four independent places and never runs from
# the production directory.
guard_reseed() {
  local reseed project here app_env
  reseed=$(env_get STAGING_RESEED)
  project=$(env_get COMPOSE_PROJECT_NAME)
  app_env=$(appenv_get APP_ENV)
  here=$(pwd -P)
  case "$reseed" in
    ''|0) return 0 ;;
    1) ;;
    *)
      err "STAGING_RESEED must be 0, 1 or empty (got '$reseed'). Nothing deployed."
      return 1
      ;;
  esac
  if [ "$(env_get STACK_ENV)" != staging ] \
    || [ "$project" != family-hub-staging ] \
    || [ "$app_env" != staging ] \
    || [ "$here" = /opt/family-hub ]; then
    err "STAGING_RESEED=1 is only allowed on the staging stack (STACK_ENV=staging, project family-hub-staging, APP_ENV=staging, not /opt/family-hub). Nothing deployed."
    return 1
  fi
}

guard_host() {
  if ! docker network inspect edge >/dev/null 2>&1; then
    err "Docker network 'edge' is missing. Nothing deployed."
    return 1
  fi
}

if [ "${DEPLOY_SH_SOURCE_ONLY:-}" = 1 ]; then
  # shellcheck disable=SC2317
  return 0 2>/dev/null || exit 0
fi

main() {
  cd "$(dirname "$0")"
  local here
  here=$(pwd -P)

  guard_inputs
  guard_env_has_no_passwords
  guard_secrets
  guard_reseed
  guard_host

  export IMAGE_TAG
  COMPOSE_PROJECT_NAME=$(env_get COMPOSE_PROJECT_NAME)
  export COMPOSE_PROJECT_NAME
  local app_domain reseed
  app_domain=$(env_get APP_DOMAIN)
  reseed=$(env_get STAGING_RESEED)

  # Each stack keeps its own docker config so a registry login/logout of one deploy never touches another.
  DOCKER_CONFIG=${DOCKER_CONFIG:-$here/.docker}
  export DOCKER_CONFIG
  # The CI registry token must not stay on the server disk.
  trap 'docker logout ghcr.io >/dev/null 2>&1 || true' EXIT

  dc() { docker compose -f compose.server.yaml "$@"; }

  log "Deploying $IMAGE_TAG to $COMPOSE_PROJECT_NAME ($here)"

  if [ "${SKIP_PULL:-}" = 1 ]; then
    log "Skipping pull (SKIP_PULL=1), checking local images"
    local prefix
    prefix=$(env_get IMAGE_PREFIX)
    docker image inspect "$prefix-app:$IMAGE_TAG" "$prefix-nginx:$IMAGE_TAG" >/dev/null
  else
    log "Pulling images"
    dc pull app nginx
  fi

  log "Starting postgres"
  dc up -d --wait postgres

  # Encrypted restic snapshot tagged pre-migrate (plan 01-06). No plaintext dump files are written here.
  log "Pre-migration backup"
  if ! bash ./backup.sh pre-migrate; then
    err "Pre-migration backup failed, migration not run. The previous version keeps running."
    exit 1
  fi

  log "Migrating (owner role, one-shot container)"
  if ! dc --profile migrate run --rm --no-deps -T migrate; then
    err "Migration failed. The previous version keeps running; recover from the pre-migrate snapshot if needed."
    exit 1
  fi

  if [ "$reseed" = 1 ]; then
    log "Staging reseed"
    if ! dc run --rm --no-deps -T app php artisan staging:seed --force; then
      err "staging:seed failed. Staging data may be partial; the next deploy reseeds."
      exit 1
    fi
    if ! dc run --rm --no-deps -T app php artisan staging:audit; then
      err "Staging audit failed (staging data is not clean). Nothing started."
      exit 1
    fi
  fi

  if [ ! -f "letsencrypt/live/$app_domain/fullchain.pem" ]; then
    log "Issuing certificate for $app_domain"
    bash ./cert-renew.sh issue
  fi

  log "Starting services"
  if dc up -d --wait --remove-orphans app worker scheduler nginx; then
    printf '%s\n' "$IMAGE_TAG" > .last-good-tag
    log "Healthy. Recorded $IMAGE_TAG as last good tag."
  else
    err "Services did not become healthy."
    if [ -s .last-good-tag ]; then
      local good
      good=$(cat .last-good-tag)
      err "Rolling back to $good"
      IMAGE_TAG=$good dc up -d --wait --remove-orphans app worker scheduler nginx || err "Rollback to $good also failed."
    else
      err "No .last-good-tag to roll back to."
    fi
    exit 1
  fi

  docker image prune -f --filter until=168h >/dev/null || true
  log "Deploy finished"
}

main "$@"
