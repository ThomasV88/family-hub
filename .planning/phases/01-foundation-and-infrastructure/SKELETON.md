# Walking Skeleton — Family Hub

**Phase:** 1
**Generated:** 2026-11-12

## Capability Proven End-to-End

A family member opens the staging (and then production) URL over HTTPS through the existing edge proxy, sees the Family Hub home page with the latest background check that the queue worker wrote into Postgres, presses "Run check now" to record a new one, switches the page between Slovak, Polish and English, and can install the app to the home screen; the database behind it is backed up with encryption and a restore has been performed.

## Architectural Decisions

| Decision | Choice | Rationale |
|---|---|---|
| Framework | Laravel 13 (PHP 8.4 in images, ^8.3 required) on a plain `laravel/laravel` skeleton, no starter kit | Owner-locked stack. Starter kits ship Fortify pages and their own layout that conflict with invite-only auth (Phase 2) and the design system (Phase 01.1) |
| Frontend | Inertia 3 (`inertiajs/inertia-laravel` 3.x, `@inertiajs/vue3` 3.x) + Vue 3.5, Vite 8 via `laravel-vite-plugin` 3.x | Owner-locked; server-driven pages, no separate API layer for page data |
| Data layer | PostgreSQL 18 (`postgres:18-alpine`), Eloquent, Laravel migrations run only by the one-shot `migrate` container with the owner role (`fh_owner`); runtime uses `fh_app` (no DDL) | Matches task-tracker on the same server; least privilege |
| Queue / cache / session | Laravel `database` driver for queue, cache and session (no Redis); `worker` runs `queue:work`, `scheduler` runs `schedule:work`, same image as `app` | Owner-locked: no Redis, separate worker container |
| Auth | None in Phase 1 (public placeholder page only). Phase 2 adds Fortify + Socialite, invite-only. Session cookies already `Secure`, `HttpOnly`, `SameSite=Lax` | Auth is Phase 2 scope |
| i18n | UI strings in `lang/{en,sk,pl}.json` (vue-i18n message syntax), shared to the client through Inertia props for the current locale only; server-only strings in `lang/{locale}/*.php` (Laravel `trans_choice` syntax). Plurals via `Intl.PluralRules` mapped to message forms; dates and numbers via `Intl` formats `en-GB`, `sk-SK`, `pl-PL`. Locale order: `locale` cookie, `Accept-Language`, fallback `en` (user column added in Phase 2) | Owner-locked Laravel localization + vue-i18n; CLDR-correct Slavic plurals |
| PWA | `vite-plugin-pwa` 2.x, `injectManifest` with hand-written `resources/js/sw.js`: precache of hashed build assets, icons and `offline.html` only, no runtime caching, `registerType: 'prompt'`. Build fails if the precache list contains anything outside the allowlist (`scripts/check-sw-precache.mjs`). Dynamic responses carry `Cache-Control: no-store, private` | Medical and finance data must never be cached on shared family devices |
| Deployment target | Owner VPS, Docker Compose. `/opt/family-hub` (project `family-hub`, edge alias `fh-prod-nginx`, branch `main`) and `/opt/family-hub-staging` (project `family-hub-staging`, alias `fh-stage-nginx`, branch `develop`). One `compose.server.yaml` for both. Images `app` and `nginx` built in GitHub Actions, pushed to GHCR tagged `sha-<commit>`, deployed by `deploy.sh` over SSH (task-tracker pattern). App nginx terminates TLS (Let's Encrypt DNS-01 via Cloudflare) behind the edge SNI passthrough with PROXY protocol. No published ports | Clones the proven work-tracker pattern on the same server |
| Secrets layout | `.env` (compose interpolation only, no passwords), `app.env` (runtime, `fh_app`), `migrate.env` (owner role, one-shot only), `secrets/` (postgres passwords, restic password, cloudflare.ini), all 0600/0700, owned by `deploy`, never in git | Task-tracker split; deploy.sh rejects passwords in `.env` |
| Backups | Host cron (user `deploy`) runs `scripts/backup.sh`: `pg_dump -Fc` streamed into a restic repository (encrypted, retention via `forget --prune`, weekly `check`, monthly scripted restore drill into a scratch DB). Pre-migration safety dumps also go to restic, never plain files | FOUND-04 requires encryption and a proven restore |
| Staging data | Only `php artisan staging:seed` (Faker, `@example.test` emails); `staging:audit` fails if non-synthetic rows exist; refused in production by the command and by `deploy.sh` preflight; production backups are never restored into staging | FOUND-05 |
| Directory layout | Laravel conventional layout: `app/Http/Controllers`, `app/Jobs`, `app/Models`, `app/Console/Commands`, `resources/js/{Pages,Layouts,Components}`, `lang/`, `tests/{Feature,Unit,js}`; infra in `docker/`, `compose.yaml` (dev), `compose.server.yaml` (servers), `scripts/` (deploy, backup, smoke, checks), `ops/` (env templates, cron), `docs/ops/server.md` (runbook) | Standard Laravel conventions keep later modules predictable; later modules add `Pages/<Module>/` and `Controllers/<Module>/` |
| Tests | Pest 5 (feature/unit, run in the dev `app` container against Postgres), Vitest (jsdom) for Vue and i18n, shell checks for compose/deploy guards/backups, post-deploy `scripts/smoke-server.sh` | Owner-locked Pest + Vitest |
| Branch flow | Work happens on `develop` (pushed = staging deploy); production only via PR `develop` to `main` (merge commit) with the GitHub `production` environment requiring owner approval | Same as work-tracker |

## Stack Touched in Phase 1

- [ ] Project scaffold (framework, build, lint, test runner) — plan 01-01
- [ ] Routing — `GET /`, `POST /heartbeat`, `POST /locale` — plans 01-01, 01-03, 01-07
- [ ] Database — worker writes `system_heartbeats`, home page reads them — plan 01-03
- [ ] UI — "Run check now" button and language switcher wired to the server — plans 01-03, 01-07
- [ ] Deployment — staging via push to `develop`, production via PR to `main`, both through the edge — plans 01-04, 01-08, 01-10, 01-11, 01-12

## Out of Scope (Deferred to Later Slices)

- Authentication, invitations, user locale preference column, permissions, audit log (Phase 2)
- Design tokens, component library, app shell navigation, dark theme (Phase 01.1). Phase 1 UI is a single unstyled-but-tidy page using a few CSS variables copied from the sketch theme
- Push notifications, email sending, uploads volume backup (Phases 3, 4, 7)
- Any medical or finance tables and their synthetic seeds (Phases 5, 7 extend `StagingSeeder`)
- AI worker image additions and CLI credential volumes (Phase 8)
- Alerting by email on backup failure (logged and checked by `backup-freshness.sh` only)

## Subsequent Slice Plan

Each later phase adds one vertical slice on top of this skeleton without altering its architectural decisions:

- Phase 01.1: design system and responsive app shell replacing the placeholder layout
- Phase 2: invite-only accounts, Google sign-in, per-module permissions, audit log
- Phase 3: shared and personal to-do with recurrence and reminders
- Phase 4: wishlists with hidden reservations and occasions
- Phase 5: finance ledger, recurring expenses, budgets, CSV import
- Phase 6: second factor and idle re-authentication
- Phase 7: medical records (manual)
- Phase 8: AI extraction worker (Codex)
- Phase 9: multi-agent, quota handling, bulk re-generation
