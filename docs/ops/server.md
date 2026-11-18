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
| mem_limit app | 256m (prod), 256m (staging) | 2026-11-15 (was 192m; 3600 px medical images peak near 141 MiB per decode) |
| mem_limit worker | 160m (prod), 160m (staging) | 2026-11-13 |
| mem_limit scheduler | 128m (prod), 128m (staging) | 2026-11-13 |
| mem_limit nginx | 48m (prod), 48m (staging) | 2026-11-13 |
| Memory total per stack | about 784 MB (limits), about 1.57 GB for both stacks (about 2.5 GB available) | 2026-11-15 |
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

Audit log retention: `php artisan audit:prune` is not in the Laravel scheduler because the runtime role `fh_app` has no DELETE on `audit_logs`. `server-provision.sh --install-cron` adds a monthly cron line (02nd, 05:40, staging +5 min) for user `deploy` that runs it as the owner through the migrate profile: `cd /opt/<stack> && IMAGE_TAG="$(cat .last-good-tag)" docker compose -f compose.server.yaml --profile migrate run --rm --no-deps -T migrate php artisan audit:prune >> logs/cron.log 2>&1`. The explicit `-f` is needed because a provisioned `.env` has no `COMPOSE_FILE`; `IMAGE_TAG` is needed because the compose file requires it. `ops/cron/family-hub.cron.example` shows the same lines.

Freshness alerting: `backup-freshness.sh` (cron, 09:xx) checks only snapshots tagged `nightly`. When the newest one
is older than 26 h it prints `BACKUP STALE` to `logs/cron.log`, appends to `logs/backup.log`, exits non-zero and,
if `BACKUP_ALERT_COMMAND=/path/to/script` is set in `.env`, runs that script with the message as its argument
(hook point for mail or push notification; none is configured in Phase 1).

Profile photos (Phase 10) live in the `uploads` volume under `wish-images/profile-photos/<user id>/` and are covered by the existing uploads snapshot; a daily sweep removes files no member references.
Shopping catalog pictures and store logos (Phase 11) live in the same `uploads` volume under `wish-images/shopping/{catalog,stores}/<id>/` and are covered by the existing backup; on staging the seed fills them with generated shapes and purges them on every reseed.
Recipe photos (Phase 12) live in the same `uploads` volume under `wish-images/recipes/<recipe id>/` (full and thumbnail per photo), are covered by the existing backup and are swept with the wishlist prune; on staging the seed generates them and purges the folders on every reseed.

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
- Recognised document text (Phase 13): the database snapshots (`/family_hub.dump`) now contain the recognised text of documents
  (table `medical_document_texts`, plain text, the owner's decision) until the retention period of the snapshot expires. Removing a text
  in the app (the per-document Remove, a consent withdrawal, deleting or archiving the document) does not remove it from older
  snapshots; production keeps daily 14, weekly 8, monthly 6. Treat every database snapshot and its off-site copy as holding health text.
  `restore-drill.sh` proves the restore works for it: it prints `RESTORE-DRILL TEXT OK rows=N` after checking, in the restored database,
  that the table restored, that a probe string run through the search expression matches a prefix `to_tsquery`, that every row's stored
  search column equals the expression recomputed from its body, and that `public.fh_unaccent` turns `Łukasz` into `Lukasz`
  (the generated column and the search need that wrapper). `RESTORE-DRILL TEXT NONE` means the snapshot predates the table. Counts
  only, never any text. `scripts/test-backup-local.sh` asserts one of the two lines (OK for a database with the table, NONE without it).
- Decision (provisional, owner to confirm in plan 07-20): no app-level file encryption in v1. `APP_KEY` lives on the same host, so it
  would add little; restic encrypts the backups and the volume is 0700/0600.
- nginx: only `POST /health/people/<uuid>/documents` accepts a body up to 12m; PHP `upload_max_filesize=11M`, `post_max_size=13M`.
- Medical image processing (owner decision WR-12, 2026-11-15): an uploaded image is re-encoded to a JPEG with its longest edge at
  most 3600 px (was 2400) at quality 90 (was 82), plus a 480 px thumbnail. EXIF and GPS are stripped and the original is not kept
  (PDF and HEIC are stored unchanged; for AI a HEIC is converted in the job work dir only, see the `ai-worker` service below). Settings: `config/medical.php` `image_full_px`, `image_jpeg_quality`, `image_thumb_px`.
  The wishlist keeps 1280 px at quality 82. Decode memory (GD, measured in a PHP 8.4 process with `memory_limit=128M`, the
  production value, by `tests/Support/medical-image-memory-probe.php`): the source bitmap and the resized copy are alive together,
  about 4.4 bytes per pixel each. A 4032 x 3024 (12 MP) photo peaks at 89.5 MiB above the 24 MiB framework baseline, 112 MiB in
  total (RSS about 141 MiB), so it fits the 128M limit with 16 MiB to spare. With the old 2400 px output the same photo needed
  about 70 MiB. The input cap of 13 MP alone no longer bounds memory (a 3600 x 3600 image would copy 13 MP twice, about 114 MB, and
  die with a fatal error), so `medical.image_decode_budget_bytes` (106 MB) also bounds an estimate of input bytes plus 4.4 bytes
  per pixel of source and output; over it the upload is refused as unreadable before any decode. In practice: a 12 MP phone photo
  up to about 4 MB passes, near-square images above about 10 MP and 10 MB files of 12 MP are refused. Container: the app
  container has `mem_limit` 256m (raised from 192m on 2026-11-15 as a server sizing decision) with up to 4 php-fpm children, and one decode peaks near 141 MiB RSS. Decodes are serialized (one at a time per container). If a container restart with exit 137 ever shows up anyway, lower `pm.max_children` in `docker/app/php-fpm-prod.conf` to 3.
- Delete window (owner decision, 2026-11-15): a health person or record (visit, medication, allergy, vaccination, document) can be
  deleted for good only within `MEDICAL_DELETE_WINDOW_HOURS` of its creation (default 72, range 0 to 8760, 0 = always archive; set in
  `app.env`, template `ops/env/app.env.example`). Older ones are archived: the row and the files stay, they are hidden from lists,
  exports, the emergency card, reminders and AI, and a member with Edit restores them from the Archive. `medical:prune-orphans`
  keeps the files of archived documents (the rows still reference them). Nothing in the app deletes archived data; only a restore
  from backup or a direct database change would.

## AI worker (Phase 8)

- Service `ai-worker` (compose.server.yaml; locally only with `docker compose --profile ai up`). Image `$IMAGE_PREFIX-ai:$IMAGE_TAG`
  (Dockerfile target `ai-worker`, the only image with the pinned Codex CLI). Runs as www-data, one process
  (`queue:work --queue=ai-control,ai,ai-bulk --tries=1 --timeout=720`: the login check runs on the priority queue `ai-control`, so an extraction backlog never makes the agent look "not responding"), `init: true`, no ports, `mem_limit` `MEM_AI` (default 768m, PROVISIONAL, confirmed in 09-20).
- AI inputs (quick 261116-n1p, owner decisions 2026-11-16). PDFs: `ai.pdf.max_pages` is 20 (was 10); the web viewer keeps `view_max_pages` 50.
  The worker rasterizes all pages in one `pdftoppm` spawn bounded by `ai.pdf.pages_timeout` (60 s; `ai.pdf.timeout`, 20 s, stays the
  single-page viewer bound). pdftoppm renders pages one after another, so more pages do not raise peak memory; pages are JPEG q82 at
  scale 1800 (a few hundred KB each), so 20 pages fit the 192m `/ai-work` tmpfs and `MEM_AI` 768m stays. The job bound (`ai.timeouts.job`
  660 s) still covers 60 s rasterize plus 30 s HEIC decode plus the attempts that `min_call` guards.
  HEIC: the `ai-runtime` image stage (so only the `ai-worker` images, not `app`) installs `libheif-tools` (`heif-dec`, libheif 1.23.7 on
  the current base) whose dependencies bring the libde265 HEVC decoder (`libheif-libde265`); the build fails when `heif-dec --list-decoders`
  shows no HEVC decoder. `App\Medical\Documents\HeicConverter` decodes the stored HEIC inside the job work dir on the `/ai-work` tmpfs
  (argv only, `ulimit -v` `ai.heic.ulimit_kb` 524288, hard timeout `ai.heic.timeout` 30 s) and re-encodes the result through
  `ImageProcessor` (GD: EXIF, GPS and XMP are dropped), writing `doc.jpg`; the HEIC is removed before the CLI starts, nothing is
  stored and the medical volume stays read-only. A HEIC that cannot be decoded fails as `unreadable_document` without starting an
  agent. Keys: `ai.heic.binary` (env `AI_HEIF_BINARY`, default `heif-dec`), `timeout`, `ulimit_kb`, `max_jpeg_bytes`. Measured (dev,
  ai image): a 12 MP (4000x3000) HEIC decodes in about 1.4 s end to end with a 116 MB real PHP peak under `memory_limit=128M`; `heif-dec` alone
  peaks at about 49 MB resident in 0.4 s. libheif applies the HEIC rotation on decode and writes orientation 1, so there is no double rotation.
- Env file `ai-worker.env` (template `ops/env/ai-worker.env.example`), never `app.env`: no `APP_KEY`, `MAIL_*`, `GOOGLE_*`, `VAPID_*`, `AWS_*`, `STRIPE_*`, `*_API_KEY`, `*_SECRET`.
  `ai:preflight` refuses to start if one is set (codes `app_key_present`, `forbidden_env`, `forbidden_secret`; names are matched by
  `ai.forbidden_env_patterns`, values are never printed). The dev `ai-worker` reads `ops/env/ai-worker.dev.env` and has the repo `.env` masked. `server-provision.sh` creates `ai-worker.env` (mode 600, never overwritten); `deploy.sh` does not require it: without a non-empty file it skips every ai-worker step with a warning and the core app deploys. It uses the runtime role `fh_app`
  (provisional: no dedicated database role for now). `DB_QUEUE_RETRY_AFTER=900` must stay above the job timeout.
- Deploy coupling: `deploy.sh` does not gate on the ai-worker. The core services (`app worker scheduler nginx`) decide the
  deploy and the rollback; the ai-worker is started afterwards (`up -d --wait ai-worker`) and a failure only logs a warning (AI is
  unavailable, the rest of the deploy stands). The rollback restarts the ai-worker only when `$IMAGE_PREFIX-ai:<last good tag>` exists
  locally (the first Phase 8 rollout has none) and otherwise stops it. `check-compose.sh` and `test-deploy-guards.sh` enforce this.
- Mounts: volume `codex-home` at `/codex` (writable, holds `auth.json`, refreshed in place) and `medical` read-only. Work dir `/ai-work` is a
  tmpfs (mode 0700), discarded on stop. No other service mounts `codex-home`; `scripts/check-compose.sh` fails on any drift.
- `codex-home` is never backed up: it holds a subscription login. `backup.sh`, `restore-drill.sh` and `backup-freshness.sh` never mention it
  (checked by `check-compose.sh`). After a restore the login is repeated, not restored.
- Each stack (production, staging) has its own `codex-home` volume and its own login (separate project names). Never copy `auth.json` between stacks.
- Single concurrency: one `auth.json` per serialized stream. Never scale the service (no `deploy.replicas`).

### One-time login (do in 08-22, not before)

Before the first login check the data-controls and training settings of the ChatGPT plan. Then, on the stack's server directory:

    docker compose exec ai-worker codex login --device-auth
    docker compose exec ai-worker codex login status

The login survives restarts because it lives in the `codex-home` volume. When a refresh fails, run the login again (the admin settings page
reports "login expired" once the health check exists). Never set `OPENAI_API_KEY`/`CODEX_API_KEY`: per-token billing is out of scope.

### Copilot CLI (Phase 9)

- The GitHub Copilot CLI is pinned to 1.0.112 (`docker/ai/copilot/package-lock.json`) and baked into the ai image only (build stage `copilot`, one `COPY --from=copilot` into `ai-runtime`). Its runtime cache is extracted at build time into `/opt/copilot/cache` and read-only, so it starts in about a second and never self-updates (`COPILOT_AUTO_UPDATE=false`, `COPILOT_TELEMETRY_DISABLED=true`). The binary is not stripped or repacked (licence). The app, worker and web images contain no Copilot and no Node.
- Volume `copilot-home` (dev `copilot-home-dev`) at `/copilot`, mounted only into the `ai-worker`. It holds the login only (the per-run homes live on the `/ai-work` tmpfs). It is never backed up and never copied between stacks; after a restore the login is repeated. `check-compose.sh` fails on any other mount or any backup script mentioning it.
- Login (done in 09-20, not before): `docker compose exec ai-worker copilot login --device-code`. On a server without a keyring Copilot stores the token in plaintext under `/copilot`; the owner answers yes. Set the Copilot "Additional usage" budget to $0. Never set `GH_TOKEN`, `GITHUB_TOKEN`, `COPILOT_GITHUB_TOKEN`, `COPILOT_PROVIDER_*`, `COPILOT_ALLOW_ALL*` or `COPILOT_OFFLINE` (static check, env example rule, preflight).
- Check the version with `copilot --version`, never `copilot version` (it calls the network).
- Queues are served in strict order `ai-control,ai,ai-bulk`: login checks and interactive runs always go before a bulk re-read.
- Timeout invariants: `DB_QUEUE_RETRY_AFTER` 900 > worker `--timeout` 720 >= job budget 660 (config raised in 09-10); the reaper threshold is job + 120 = 780.
- PROVISIONAL (confirmed in 09-20): `MEM_AI` 768m and tmpfs `/ai-work` 192m.
- Operations notes from the Phase 9 review:
  - **Rollback (IN-01).** Rolling back to a Phase 8 image keeps the migrated schema. Before the old image starts, resolve `waiting` rows (`update medical_ai_extractions set ...` through the owner role, or cancel them in the app); the old code never resumes or reaps them, and the `one_active` index (which includes `waiting`) blocks any new run on those documents. The `waiting` migration's `down()` refuses while `waiting` or `decided` rows exist.
  - **Pause (IN-03).** Pausing a bulk run only stops new enqueues. Items already queued, and waiting bulk rows that the resume command puts back in the queue, still run. Cancel the run to stop them.
  - **Interactive latency (IN-07).** One worker with no preemption: an interactive run can wait behind one running bulk job for up to the job budget (11 minutes). Queue order `ai-control,ai,ai-bulk` only decides what starts next.
  - **Retention (IN-06).** `ai_bulk_run_items` (document ids) and `ai_bulk_runs` are not pruned by `ai:prune-calls`; they are kept until a retention rule is decided (see DEFERRED.md).
  - **Copilot `--available-tools` (IN-02).** It is passed without a value to mean "no tools"; the fake CLI cannot prove that. Verify it live on every Copilot version bump (the version is pinned in `config/ai.php` and the Dockerfile).
- Full-text reading (Phase 13) env keys. `AI_OCR_ENABLED` must carry the SAME value in `app.env` AND `ai-worker.env` of the stack:
  the app decides the run mode when a run is queued, and `RunExtractionJob` re-reads the flag inside the ai-worker (whose environment
  is `ai-worker.env`, never `app.env`) and `DocumentTextStore::save` refuses while it is off. With the flag on only in `app.env`, runs
  are queued in text mode but no text is saved. `AI_OCR_TEXT_RELIABILITY` is read by the web app only (`app.env` only). A change needs
  the `app`, `worker`, `scheduler` and `ai-worker` services recreated, no other stack restarted. **Existing servers:** `server-provision.sh`
  never overwrites env files, so add the line `AI_OCR_ENABLED=false` (or the value `app.env` has) to the existing `ai-worker.env` by hand
  (mode 600, do not print the file); a missing key means `false` through the config default, so existing deployments keep working unchanged.
  - `AI_OCR_ENABLED` (default `false`). Off: Run AI works as before (one call, entries only), no text is stored, the search box finds titles only.
    Production stays off until the owner enables it; staging may enable it for the check. Turning it on does not touch existing data.
  - `AI_OCR_TEXT_RELIABILITY` (`reliable` or `uncertain`, default `reliable`). `uncertain` shows the "may contain errors" label on the
    recognised text; the switch needs no code change.
  - The recognised text is part of the database snapshots (see "Phase 7: medical files volume", Recognised document text).
- Deferred WR-06: both CLIs run under the www-data uid (same uid as the app files). Still open; now covers two CLIs.

## Deploy

(Filled by plan 01-12.)

`server-provision.sh` creates `ai-worker.env` (mode 600, from the same keys as `ops/env/ai-worker.env.example`, `DB_PASSWORD` = the generated `fh_app` password) next to `app.env` and never overwrites an existing one. `deploy.sh` does not abort when it is missing or empty: it logs a warning, creates an empty mode-600 placeholder so compose can load the project, and skips the ai-worker pull, start and rollback restart (AI extraction unavailable). Re-run `server-provision.sh` to fill the placeholder.

### Contextual help sync (`help:sync`)

The green "?" tips keep their texts in the table `help_texts` (one row per topic key of `resources/help/topics.json`, a text per language, a per-language "edited by an administrator" marker). `deploy.sh` runs `php artisan help:sync` in a one-shot `app` container right after the migration, on staging and production, with the runtime role (the table needs only read and write, which the default privileges of `fh_owner` already grant; no migrate profile). It is idempotent: it inserts keys that are missing, refreshes the default text and the placement of every language an administrator has NOT edited, keeps edited texts and only reports orphan keys (in the table, no longer in the registry); it never deletes. A failure is logged as a warning and does not stop the release: the app falls back to the registry default whenever a row or text is missing. Run it by hand with `docker compose run --rm --no-deps -T app php artisan help:sync`. `staging:seed` calls it too. See `.planning/v1.0-ISSUE-4-NOTES.md`.

### Product name (Phase 14, FamilyHUB)

The app name comes from `APP_NAME` in each stack's `app.env` (top bar alt text, page title, mail header and footer, default From name). `server-provision.sh` writes `app.env` only when it is missing and `deploy.sh` never edits it, so existing stacks keep the old name until the operator changes it. Before or at the production release of Phase 14: back up `/opt/family-hub/app.env` as `app.env.pre-rebrand`, set `APP_NAME="FamilyHUB"` (and `MAIL_FROM_NAME="FamilyHUB"` only if that key exists; change nothing else, print nothing), then the release deploy recreates the containers. Staging is changed during Phase 14 verification. Internal names (directories, compose projects, `fh_*` roles, `fh-*` aliases) stay unchanged.

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
| `SECURITY_IDLE_SECONDS` | 3600 | Idle window for Health and Finances; after it the member confirms again. Only activity inside those modules extends it. Clamped to 60..86400; a malformed value (for example `15m` or `0`) falls back to 3600. The admin can override it in Settings > Members, card "Re-confirmation" (stored in `app_settings`, key `security.idle_seconds`); the stored value wins when valid, otherwise this default applies. |
| `SECURITY_CONFIRM_WINDOW_SECONDS` | 3600 | How long a confirmation (password, code, passkey) or a sign-in counts for security actions. Clamped to 60..3600; a malformed value falls back to 3600. Overridable in Settings the same way (`security.confirm_window_seconds`). |
| `SECURITY_PASSKEYS_ENABLED` | true | `false` closes passkey enrolment, rename and removal (404) and hides the passkey card. Members who already have a passkey can still sign in and confirm with it, so flipping the switch never locks them out. |

There is deliberately no "Sign in with Google again" confirmation (removed in the 06 review fix, CR-02: Google's `max_age`/`auth_time` freshness cannot be proven with Socialite). A member with no password and no second step confirms with the single "Sign in again" action (sign out, then a fresh Google sign-in). Known limitation: for such a member, Google sign-in without 2FA is effectively one click on a device that is already signed in to Google, so the stale-session gate does not stop someone at an unattended, Google-signed-in browser. Recommendation: Google-only members enrol an authenticator app or a passkey, and the admin turns on the second-factor requirement.

Setting up an authenticator app, adding a passkey and changing the admin requirement all need a confirmation within the confirm window (default 1 hour; signing in counts). A member who is past that window confirms in a "Confirm it's you" dialog over the page they are on and the action then runs once; the form is never lost. Nothing needs to be done on the server.

### Turning on the requirement

An admin opens Settings > Members and switches on "Require two-step verification for Health and Finances" (it needs a fresh confirmation and is written to the audit log). It is off by default. Members with access to Health or Finances who have no second step are then guided to Settings > Security before they can open those modules; everybody else is unaffected. Switching it off is the same control.

### Runbook: a member lost every second step

1. The admin opens Settings > Members, picks the member, opens the Security tab and chooses "Reset two-step verification" (confirming their own identity if asked).
2. The reset removes the authenticator app, recovery codes and all passkeys in one transaction, signs the member out on every device and writes `second_factor_reset` to the audit log (admin and member, no secrets).
3. The member signs in with their password (or Google) and sets up a new second step in Settings > Security. If the requirement is on, they are guided there.
4. An admin cannot reset their own row; a second admin does it. If the only admin is locked out, use the shell on the server: clear `two_factor_secret`, `two_factor_recovery_codes`, `two_factor_confirmed_at` for that user and delete their `passkeys` rows (`php artisan tinker`, run through `docker compose exec`, staging first), then record the action by hand.

### What is and is not logged

Audit and application logs never contain a code, secret, recovery code, passkey name or credential id; the canary tests (`SecurityAuditContentTest`) fail the build if one appears. Security pages and secret-bearing endpoints answer `Cache-Control: no-store` and the service worker never caches them.

## Rodinné a osobné použitie (GDPR)

Rozhodnutie majiteľa z 2026-11-15. Platí pre produkciu aj staging.

- **Len rodina majiteľa.** Family Hub používa iba rodina majiteľa na vlastné domáce záležitosti (úlohy, priania, rodinné financie a zdravie). Ide o čisto osobnú a domácu činnosť, na ktorú sa podľa čl. 2 ods. 2 písm. c) GDPR nariadenie nevzťahuje (výnimka pre domácnosť).
- **Len pozvaní členovia.** Účet vznikne iba na pozvánku správcu (žiadna verejná registrácia). Členom sa dajú len ľudia z rodiny, každý vidí iba moduly a osoby, ktoré mu správca povolil. Zdravie a financie sú predvolene zatvorené.
- **Údaje ostávajú v rodine.** Zdravotné údaje a údaje detí sa nezdieľajú mimo rodiny: ani s tretími stranami, ani cez verejné odkazy. Server je vo vlastnej správe majiteľa. Zálohy sú šifrované a zostávajú na serveri (mimo servera sa nezálohuje).
- **Exporty.** Export zdravotných záznamov (tlač, JSON, Markdown) a núdzová karta slúžia na vlastné použitie rodiny. Exportovaný súbor už nechráni oprávnenie aplikácie, preto s ním zaobchádza ten, kto ho stiahol, ako so súkromným dokumentom a neposiela ho mimo rodiny. Každý export sa zapíše do auditu (`medical_exported`, bez obsahu).
- **AI.** AI zostáva vypnutá, kým sa majiteľ nerozhodne: súhlas je predvolene vypnutý a ai-worker nie je prihlásený. Zapnutie by znamenalo, že dokument opustí server smerom k poskytovateľovi AI, a to by bolo nové rozhodnutie majiteľa (podmienky použitia dát, rozsah, kto smie súhlas zapnúť). Do tohto rozhodnutia sa žiadny dokument ani údaj nikam neodosiela.
- **Mazanie.** Zdravotný záznam sa dá natrvalo zmazať len krátko po vytvorení (predvolene 72 hodín, `MEDICAL_DELETE_WINDOW_HOURS`), neskôr sa archivuje: zostáva uložený, ale je skrytý. Zálohy držia zmazané údaje počas doby uchovávania (produkcia: denne 14, týždenne 8, mesačne 6).
