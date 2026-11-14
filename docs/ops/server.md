# Family Hub: server ops runbook

Single source for Phase 1 ops decisions. Plans 01-04, 01-06, 01-08 and 01-10 read values from here. This file records locations and modes of secrets, never their values.

## Decisions

Decided by the owner on 2026-11-13 (option "recommended, no off-site").

| Parameter | Value | Decided |
|---|---|---|
| HOST_PROD | `family-hub.vostack.dev` | 2026-11-13 |
| HOST_STAGE | `family-hub-stage.vostack.dev` | 2026-11-13 |
| Repo visibility | public (kept: GitHub Free has no required-reviewer `production` environment on private repos; rule: no secrets, real data or private keys ever committed, CI secret scan enforces it; revisit if the plan is upgraded) | 2026-11-13 |
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

Freshness alerting: `backup-freshness.sh` (cron, 09:xx) checks only snapshots tagged `nightly`. When the newest one
is older than 26 h it prints `BACKUP STALE` to `logs/cron.log`, appends to `logs/backup.log`, exits non-zero and,
if `BACKUP_ALERT_COMMAND=/path/to/script` is set in `.env`, runs that script with the message as its argument
(hook point for mail or push notification; none is configured in Phase 1).

### Phase 7: medical files volume

- Volume `medical` (compose.server.yaml, one per stack because each stack has its own project name; `medical-dev` locally), mounted at
  `/var/www/html/storage/app/medical` in `app`, `worker` and `scheduler` only, never in nginx or postgres. Mode 0700, files 0600.
- Each nightly and manual `backup.sh` run writes a third restic snapshot `/medical.tar` with tags `<env>`, `medical`, `medical-<kind>`
  (never the bare `nightly`/`manual` tag, which stays "database"). pre-migrate runs skip it. `.env` key `MEDICAL_PATH` overrides the
  in-container path (default as above). Retention matches the database (`medical-nightly`), the off-site copy receives the snapshot id.
- A failing medical tar does not stop the database and uploads snapshots; the run then exits non-zero with `FAILED: medical ...`.
- `backup-freshness.sh` also requires a fresh `medical-nightly` snapshot (`no nightly medical snapshot`). The first deploy of this
  phase needs one manual `backup.sh manual` or the first freshness run reports stale. `restore-drill.sh` prints
  `RESTORE-DRILL MEDICAL OK files=N`.
- Restore one person's files: `restic -r <repo> dump latest /medical.tar --tag medical-nightly | tar -xf - ./<subject-uuid>` into a
  scratch directory, then copy back into the volume as the app user. Files are stored under one directory per subject uuid.
- Erasure: deleted medical data stays in snapshots for the retention window (production: daily 14, weekly 8, monthly 6).
- Decision (provisional, owner to confirm in plan 07-20): no app-level file encryption in v1. `APP_KEY` lives on the same host, so it
  would add little; restic encrypts the backups and the volume is 0700/0600.
- nginx: only `POST /health/people/<uuid>/documents` accepts a body up to 12m; PHP `upload_max_filesize=11M`, `post_max_size=13M`.

## Deploy

(Filled by plan 01-12.)

## Two-step verification and re-authentication

Phase 6. Values live in each stack's `.env` / `app.env`; this section records meaning and procedure only, never a value.

### Keys and secrets

- `APP_KEY` encrypts every member's authenticator secret and recovery codes. Keep it in the backups of each stack (it travels with `app.env`; the restic snapshot already includes that file). Losing it, or changing it, makes every enrolled authenticator app unusable: members could no longer pass the challenge with a code, and recovery codes would not decrypt either. Rotating the key is therefore a planned operation: put the old key in `APP_PREVIOUS_KEYS` so existing secrets still decrypt [ASSUMED: verify on a staging rotation before relying on it], then let members re-enrol. If a rotation goes wrong, the recovery path is the admin reset below.
- `PASSKEYS_USER_HANDLE_SECRET`: set it explicitly in each environment to a long random value that never changes afterwards. When unset it falls back to a value derived from `APP_KEY`, so an `APP_KEY` rotation would silently change every member's passkey user handle. Staging and production use different values.
- Never copy `APP_KEY`, `PASSKEYS_USER_HANDLE_SECRET` or a production database dump into staging.

### Passkey domain binding

Passkeys are bound to the host of each environment's `APP_URL` (the relying-party id). Staging (`family-hub-stage.vostack.dev`) and production (`family-hub.vostack.dev`) therefore hold separate credentials; a passkey registered on one never works on the other. `APP_URL` must be the public `https://` URL of that environment. Optional overrides: `PASSKEYS_RP_ID` and `PASSKEYS_ALLOWED_ORIGINS` (comma list); leave them unset unless the public host changes. After changing the host, existing passkeys stop working until members add new ones, so the authenticator app or a recovery code must still be available.

### Settings (environment variables, defaults in `config/security.php`)

| Variable | Default | Meaning |
|---|---|---|
| `SECURITY_IDLE_SECONDS` | 900 | Idle window for Health and Finances; after it the member confirms again. Only activity inside those modules extends it. Clamped to 60..86400; a malformed value (for example `15m` or `0`) falls back to 900. |
| `SECURITY_CONFIRM_WINDOW_SECONDS` | 600 | How long a confirmation (password, code, passkey) or a sign-in counts for security actions. Clamped to 60..3600; a malformed value falls back to 600. |
| `SECURITY_PASSKEYS_ENABLED` | true | `false` closes passkey enrolment, rename and removal (404) and hides the passkey card. Members who already have a passkey can still sign in and confirm with it, so flipping the switch never locks them out. |

There is deliberately no "Sign in with Google again" confirmation (removed in the 06 review fix, CR-02: Google's `max_age`/`auth_time` freshness cannot be proven with Socialite). A member with no password and no second step confirms with the single "Sign in again" action (sign out, then a fresh Google sign-in). Known limitation: for such a member, Google sign-in without 2FA is effectively one click on a device that is already signed in to Google, so the stale-session gate does not stop someone at an unattended, Google-signed-in browser. Recommendation: Google-only members enrol an authenticator app or a passkey, and the admin turns on the second-factor requirement.

Setting up an authenticator app, adding a passkey and changing the admin requirement all need a confirmation within the last 10 minutes (signing in counts). A member who is past that window confirms inside the dialog; nothing needs to be done on the server.

### Turning on the requirement

An admin opens Settings > Members and switches on "Require two-step verification for Health and Finances" (it needs a fresh confirmation and is written to the audit log). It is off by default. Members with access to Health or Finances who have no second step are then guided to Settings > Security before they can open those modules; everybody else is unaffected. Switching it off is the same control.

### Runbook: a member lost every second step

1. The admin opens Settings > Members, picks the member, opens the Security tab and chooses "Reset two-step verification" (confirming their own identity if asked).
2. The reset removes the authenticator app, recovery codes and all passkeys in one transaction, signs the member out on every device and writes `second_factor_reset` to the audit log (admin and member, no secrets).
3. The member signs in with their password (or Google) and sets up a new second step in Settings > Security. If the requirement is on, they are guided there.
4. An admin cannot reset their own row; a second admin does it. If the only admin is locked out, use the shell on the server: clear `two_factor_secret`, `two_factor_recovery_codes`, `two_factor_confirmed_at` for that user and delete their `passkeys` rows (`php artisan tinker`, run through `docker compose exec`, staging first), then record the action by hand.

### What is and is not logged

Audit and application logs never contain a code, secret, recovery code, passkey name or credential id; the canary tests (`SecurityAuditContentTest`) fail the build if one appears. Security pages and secret-bearing endpoints answer `Cache-Control: no-store` and the service worker never caches them.
