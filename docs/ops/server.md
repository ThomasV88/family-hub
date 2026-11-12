# Family Hub: server ops runbook

Single source for Phase 1 ops decisions. Plans 01-04, 01-06, 01-08 and 01-10 read values from here. This file records locations and modes of secrets, never their values.

## Decisions

Decided by the owner on 2026-11-13 (option "recommended, no off-site").

| Parameter | Value | Decided |
|---|---|---|
| HOST_PROD | `family-hub.vostack.dev` | 2026-11-13 |
| HOST_STAGE | `family-hub-stage.vostack.dev` | 2026-11-13 |
| Repo visibility | private (owner switches it in GitHub, see Task 3 of plan 01-02) | 2026-11-13 |
| mem_limit postgres | 192m (prod), 192m (staging) | 2026-11-13 |
| mem_limit app | 192m (prod), 192m (staging) | 2026-11-13 |
| mem_limit worker | 160m (prod), 160m (staging) | 2026-11-13 |
| mem_limit scheduler | 128m (prod), 128m (staging) | 2026-11-13 |
| mem_limit nginx | 48m (prod), 48m (staging) | 2026-11-13 |
| Memory total per stack | about 720 MB, about 1.44 GB for both stacks (about 2.7 GB available) | 2026-11-13 |
| Retention production | `--keep-daily 14 --keep-weekly 8 --keep-monthly 6` | 2026-11-13 |
| Retention staging | `--keep-daily 7` | 2026-11-13 |
| Off-site backup target | none in Phase 1 (protects against mistakes and corruption, not against loss of the VPS) | 2026-11-13 |
| Restic password locations | on the server in `secrets/restic_password` (0600, owner `deploy`) plus an off-server copy in the owner's password manager | 2026-11-13 |
| Pre-migration safety dumps | stored in restic with tag `pre-migrate`, never as plain files | 2026-11-13 |

## Layout

| Item | Production | Staging |
|---|---|---|
| Directory | `/opt/family-hub` | `/opt/family-hub-staging` |
| Compose project | `family-hub` | `family-hub-staging` |
| Edge network alias | `fh-prod-nginx` | `fh-stage-nginx` |
| Restic repository | `/var/backups/restic/family-hub` | `/var/backups/restic/family-hub-staging` |
| Git branch | `main` | `develop` |

Files in each stack directory (owner `deploy`, mode 0600 unless noted):

- `.env`
- `app.env`
- `migrate.env`
- `secrets/db_root_password`
- `secrets/db_owner_password`
- `secrets/db_app_password`
- `secrets/restic_password`
- `secrets/cloudflare.ini`

The `secrets/` directory itself is 0700. Values are never written to this document, to git, or to logs.

## Rules

- Back up a file before changing it, as `*.pre-family-hub`.
- Run `nginx -t` before every reload.
- Edit the edge `/opt/edge/nginx.conf` in place with `cat new > nginx.conf` (the file is bind-mounted; no `mv`, no `sed -i`), then `docker exec edge-edge-1 nginx -t` and `nginx -s reload`.
- Never restart other stacks (work-tracker, mail, edge).
- Never print secrets (`.env`, `app.env`, `migrate.env`, `secrets/*`).
- Test on staging first.
- Production is deployed only through a PR from `develop` to `main` (merge commit).
- Never restore a production backup into staging.

## Provisioning

(Filled by plan 01-10.)

## Edge

(Filled by plan 01-10.)

## Backups and restore drill

(Filled by plan 01-11.)

## Deploy

(Filled by plan 01-12.)
