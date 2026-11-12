# Phase 1: Foundation and Infrastructure - Research

**Researched:** 2026-11-12
**Domain:** Laravel + Inertia/Vue greenfield scaffold, Docker Compose deployment behind an SNI-passthrough edge proxy, PWA shell, SK/PL/EN i18n, encrypted Postgres backups
**Confidence:** MEDIUM-HIGH (server conventions verified by reading the live server and the task-tracker CI; library versions verified on registries; PWA/i18n details partly from training knowledge)

## Summary

Phase 1 is a pure infrastructure phase with no feature code. The most valuable finding is that the owner already has a complete, working deployment pattern in `task-tracker` (GitHub Actions builds two images to GHCR, `scp`s compose and `deploy.sh` to `/opt/<stack>`, runs `deploy.sh` over SSH with `IMAGE_TAG=sha-<commit>`, then a smoke test). It runs `postgres:18-alpine`, nginx terminating its own Let's Encrypt (DNS-01 via Cloudflare) TLS behind the edge's SNI passthrough with PROXY protocol, separate secret files per consumer, a verified pre-migration dump, a `.last-good-tag` rollback, and a staging reseed with hard guards against running on production. Family Hub should clone this pattern, replacing CodeIgniter specifics with Laravel and replacing the unencrypted dumps with an encrypted, restore-tested backup.

Current stack versions are Laravel 13 (PHP ^8.3), `inertiajs/inertia-laravel` 3.x with `@inertiajs/vue3` 3.11, Vite 8, `laravel-vite-plugin` 3.2 (requires Vite ^8), `vite-plugin-pwa` 2.0.0 (supports Vite 8, needs `workbox-build`/`workbox-window` ^7.4.1), `vue-i18n` 11.4 (Node >=22). Laravel's `MessageSelector` already has plural rules for `sk` and `pl`, and the frontend should use `vue-i18n` with custom plural rules plus `Intl` for dates and numbers.

The server is small: 3.8 GB RAM (about 1.1 GB used, 2.7 GB available) and 16 GB free disk, already hosting two task-tracker stacks (each with its own Postgres) plus the mail stack. Two more full stacks (each with `app`, `nginx`, `worker`, `scheduler`, `postgres`) must run with tight memory settings, and image pruning and backup retention need to be planned from the start.

**Primary recommendation:** Clone the task-tracker deploy pattern (compose.server.yaml + deploy.sh + GHCR images + SSH deploy + smoke test), build the app as a plain `laravel/laravel` skeleton plus Inertia/Vue (not a starter kit), cache only the static shell in a hand-controlled `injectManifest` service worker, and implement backups as `pg_dump -Fc` piped into a restic repository (encrypted by default) with a scripted restore drill into a scratch database that is run and logged before the phase closes.

## Project Constraints (from CLAUDE.md)

- Stack is locked: PHP/Laravel; Inertia + Vue; PWA via `vite-plugin-pwa`; PostgreSQL; Laravel database queue on Postgres (no Redis) with a separate worker container; Fortify + Socialite (later phases); custom policy layer; Laravel localization + vue-i18n; Symfony Process for AI CLIs (later); Pest + Vitest; Compose services `app`, `nginx`, `worker`, `scheduler`, `postgres`.
- Ignore the Node/Next.js/Drizzle/pg-boss/Better Auth/next-intl/execa recommendations in CLAUDE.md PART 2; verify package choices per phase.
- Deployment: Docker Compose on owner VPS, nginx joins existing `edge` network with alias `fh-prod-nginx` / `fh-stage-nginx`.
- Languages SK, PL, EN from the start. Devices: phone, desktop, iPad (PWA).
- Privacy: service worker must never cache authenticated medical/finance responses; staging synthetic data only.
- No per-token AI billing (not relevant to Phase 1; do not set `*_API_KEY` env vars in any container).
- Global rules: back up files before changing them on the server (`*.pre-zmena`), run `nginx -t` before reload, no restart of other stacks, test on staging only, production only via PR to `main`, never print secrets (`.env`, `secrets/`, `app.env`, `cloudflare.ini`), edit edge `nginx.conf` in place (`cat new > nginx.conf`, not `mv`/`sed -i`).
- GSD: file-changing work goes through GSD commands. Project skill `sketch-findings-family-hub` exists (design direction); Phase 1 has no real UI beyond a placeholder page, the design system belongs to Phase 01.1.

<phase_requirements>
## Phase Requirements

| ID | Description | Research Support |
|----|-------------|------------------|
| FOUND-01 | Docker Compose on VPS behind edge proxy, separate staging (`develop`) and production (`main`) | Section "Deployment pattern (verified from live server)", compose layout, CI workflow, edge map lines, TLS/PROXY protocol, DNS |
| FOUND-02 | SK/PL/EN UI, language switcher, correct plural/date/number per locale | i18n architecture (Laravel `lang/` + vue-i18n + `Intl`), Slovak/Polish plural rules, switcher persistence |
| FOUND-03 | Installable PWA, responsive, SW caches only app shell | `vite-plugin-pwa` injectManifest custom SW, allowlist caching, iOS manifest/icon requirements |
| FOUND-04 | Encrypted scheduled DB backup with tested restore | restic-based encrypted backup, cron, restore drill script, verification |
| FOUND-05 | Staging has only synthetic data | Staging seed command with production guards, no prod-to-staging copy, deploy-time guard |
</phase_requirements>

## Architectural Responsibility Map

| Capability | Primary Tier | Secondary Tier | Rationale |
|------------|-------------|----------------|-----------|
| TLS termination, HSTS, PROXY protocol real IP | App nginx container | Edge (SNI passthrough only) | Edge does not decrypt; each stack holds its own cert (verified in edge nginx.conf) |
| Routing to stack by hostname | Edge proxy (`/opt/edge`) | Cloudflare DNS | SNI map in edge nginx.conf; Cloudflare proxies the DNS name |
| Static assets and PWA files (manifest, SW, icons) | App nginx (assets baked into nginx image) | Browser cache | Service worker must be served from root scope with no-cache headers |
| Locale selection and translation strings | Laravel (middleware, `lang/`) | Browser (vue-i18n, `Intl`) | Server decides locale from user/cookie, shares messages via Inertia props |
| Offline shell / install | Browser (service worker) | Nginx (headers) | SW precaches only build assets; no API runtime caching |
| Backups and restore | Host cron + restic using `docker compose exec` | Off-site/second location | Encrypted repository outside the Postgres volume |
| Staging synthetic data | Laravel artisan seeder | deploy.sh guards | Only seeders with Faker, forbidden when `APP_ENV=production` |
| Queue and scheduling | `worker` and `scheduler` containers | Postgres (jobs table) | No Redis; same image, different command |

## Standard Stack

### Core
| Library | Version | Purpose | Why Standard |
|---------|---------|---------|--------------|
| PHP | 8.3 or 8.4 (php-fpm, Alpine or Debian image) | Runtime | Laravel 13 requires ^8.3 [VERIFIED: composer show laravel/framework] |
| laravel/framework | 13.x (v13.42.0 current) | Backend framework | Owner-locked [VERIFIED: composer registry] |
| inertiajs/inertia-laravel | 3.x (v3.7.0) | Server adapter, supports Laravel ^11.35, 12, 13 | Owner-locked [VERIFIED: composer registry] |
| @inertiajs/vue3 | 3.11.0 | Vue client adapter | [VERIFIED: npm registry] |
| vue | 3.5.45 | UI | [VERIFIED: npm registry] |
| vite / @vitejs/plugin-vue / laravel-vite-plugin | 8.3.8 / 6.0.11 / 3.2.1 (peer: vite ^8) | Build | laravel-vite-plugin 3.x requires Vite 8 [VERIFIED: npm peerDependencies] |
| vite-plugin-pwa | 2.0.0 (peer: vite up to ^8, workbox-build/window ^7.4.1) | PWA manifest and service worker build | Owner-locked [VERIFIED: npm registry] |
| workbox-build, workbox-window | 7.4.1 | Required peers of vite-plugin-pwa | [VERIFIED: npm registry] |
| vue-i18n | 11.4.19 | Frontend i18n, plural rules | Owner-locked [VERIFIED: npm registry] |
| PostgreSQL | 18-alpine (same as task-tracker on this server) | DB and queue | Matches existing server image [VERIFIED: live compose file] |
| restic | 0.19.1 (already installed on server at /usr/local/bin/restic) | Encrypted, deduplicated backups | Encrypted repository by design, verified installed on server [VERIFIED: ssh probe] |

### Supporting
| Library | Version | Purpose | When to Use |
|---------|---------|---------|-------------|
| pestphp/pest | 5.3.x | PHP tests | Owner-locked; backend, i18n and smoke tests [VERIFIED: composer registry] |
| vitest | 5.1.0 | Frontend unit tests | Owner-locked; locale/plural tests [VERIFIED: npm registry] |
| laravel/pint | current | PHP formatting | CI lint |
| certbot/dns-cloudflare | v5.8.0 image | TLS certs (DNS-01) | Same image tag the task-tracker uses [VERIFIED: live compose file] |
| @vite-pwa/assets-generator | ^1 or ^2 | Generate PWA icons (192/512, maskable, apple-touch 180) | Optional; can pre-generate icons once instead |

### Alternatives Considered
| Instead of | Could Use | Tradeoff |
|------------|-----------|----------|
| restic | `pg_dump` piped to `age` (needs `age` installed; not present on server) or `gpg` (present) | restic is already installed and gives encryption, retention (`forget --prune`), integrity check (`check`), and snapshots in one tool. `age`/`gpg` would need hand-rolled retention and verification. |
| Plain skeleton + Inertia | Official Vue starter kit | Starter kit ships Fortify auth pages, shadcn-vue and its own layout, which conflict with invite-only auth (Phase 2) and the design system (Phase 01.1). Plain skeleton avoids ripping out code. |
| vue-i18n with Laravel lang files shared by Inertia | `laravel-vue-i18n` package | Owner locked "Laravel localization + vue-i18n"; follow that. `laravel-vue-i18n` would be a third-party shortcut, not needed. |

**Installation:**
```bash
composer create-project laravel/laravel .          # Laravel 13
composer require inertiajs/inertia-laravel
composer require --dev pestphp/pest pestphp/pest-plugin-laravel laravel/pint
npm install vue @inertiajs/vue3 vue-i18n
npm install -D vite @vitejs/plugin-vue laravel-vite-plugin vite-plugin-pwa workbox-build workbox-window vitest @vue/test-utils jsdom
```

**Version verification:** versions above were read from `npm view` / `composer show -a` on 2026-11-12. `vite-plugin-pwa` 2.0.0 is a new major (release notes only say "next major"; breaking changes were not retrievable). Pin it and read its README migration note when installing.

## Package Legitimacy Audit

slopcheck could not be run (`pip` is not installed on the research host), so per protocol every package is tagged `[ASSUMED]` for legitimacy. All are well-known first-party or widely used packages confirmed to exist with expected versions on the correct registries (npm view / composer show), and none was found through WebSearch alone. The planner should add one `checkpoint:human-verify` before the first `composer require` / `npm install`.

| Package | Registry | Age | Downloads | Source Repo | slopcheck | Disposition |
|---------|----------|-----|-----------|-------------|-----------|-------------|
| laravel/framework, inertiajs/inertia-laravel, pestphp/pest, laravel/pint | Packagist | years | very high | github.com/laravel, inertiajs, pestphp | not run | Approved pending human check [ASSUMED] |
| vue, @inertiajs/vue3, vue-i18n, vite, @vitejs/plugin-vue, laravel-vite-plugin, vitest | npm | years | very high | official orgs | not run | Approved pending human check [ASSUMED] |
| vite-plugin-pwa, workbox-build, workbox-window | npm | years | high | github.com/vite-pwa, GoogleChrome/workbox | not run | Approved pending human check [ASSUMED]; `npm view vite-plugin-pwa scripts.postinstall` returned empty |

**Packages removed:** none. **Packages flagged [SUS]:** none (tool unavailable).

## Architecture Patterns

### System Architecture Diagram

```
Browser / installed PWA
   | HTTPS (Cloudflare proxied DNS: fh host)
   v
Cloudflare --> Hetzner firewall (443 from Cloudflare only)
   v
edge-edge-1 (nginx stream, SNI map, PROXY protocol)   <-- add 2 lines: fh-prod-nginx:443, fh-stage-nginx:443
   v  (TLS passthrough, network "edge", alias fh-prod-nginx / fh-stage-nginx)
nginx container (terminates TLS with own LE cert, accepts PROXY protocol, HSTS, serves built assets + sw.js + manifest)
   | fastcgi (internal network)
   v
app (php-fpm, Laravel) ----> postgres (volume pgdata, internal network only)
worker (php artisan queue:work, database queue) ---^
scheduler (php artisan schedule:work) ---^

CI (GitHub Actions): push develop|main
   check job (pest, vitest, build) --> build images (app, nginx) --> GHCR sha-<commit>
   --> scp compose + deploy.sh --> ssh deploy.sh:
       pull -> db up -> verified backup -> migrate (one-shot) -> [staging: reseed] -> cert -> up --wait -> .last-good-tag -> smoke

Host cron (deploy user): nightly pg_dump -Fc | restic backup --stdin ; weekly restic check ; restore drill script
```

### Recommended Project Structure
```
app/ bootstrap/ config/ database/ lang/{en,sk,pl}/ resources/js/ routes/ tests/   # Laravel skeleton
resources/js/{app.js,i18n.js,Pages/,Layouts/,sw.js}
docker/
  app/Dockerfile (targets: dev, prod)     # php-fpm, opcache, pdo_pgsql, intl
  nginx/Dockerfile + templates/           # builds assets in a node stage, copies public/ into nginx image
  postgres/init/                          # role provisioning (owner vs rw), as task-tracker does
compose.yaml                              # local dev
compose.server.yaml                       # prod+staging: one definition, two deployments
scripts/{deploy.sh,cert-renew.sh,backup.sh,restore-drill.sh,smoke-server.sh}
.github/workflows/ci.yml
docs/ops/server.md                        # runbook incl. edge lines, DNS, secrets layout
```

### Pattern 1: One compose definition, two deployments (verified from task-tracker)
**What:** `compose.server.yaml` with `name: ${COMPOSE_PROJECT_NAME:?}`, `.env` (non-secret interpolation only: project, `EDGE_ALIAS`, `APP_DOMAIN`, `IMAGE_PREFIX`, TLS), `app.env` (runtime), `migrate.env` (owner DB credentials, one-shot container only), `secrets/db_*_password`. nginx attaches to `default` and external network `edge` with `aliases: ["${EDGE_ALIAS:?}"]`. No published ports. `docker compose up -d --wait` with healthchecks. Directories `/opt/family-hub` (project `family-hub`, alias `fh-prod-nginx`) and `/opt/family-hub-staging` (project `family-hub-staging`, alias `fh-stage-nginx`). [VERIFIED: live /opt/work-tracker/compose.server.yaml and deploy.sh]
**Laravel adaptation:** services `app`, `nginx`, `worker`, `scheduler`, `postgres`; `migrate` (profile) runs `php artisan migrate --force` with owner credentials; `seed` (profile, staging only); `certbot` (profile). `worker` and `scheduler` use the same image as `app` with only `app.env` (runtime role without DDL), `restart: unless-stopped`, and healthchecks (`php artisan queue:monitor` is not a health check; use a heartbeat via scheduler writing cache/DB timestamp, or keep simple `pgrep -f "queue:work"`).

### Pattern 2: nginx behind PROXY protocol (verified requirement)
The edge sends PROXY protocol (`NGINX_PROXY_PROTOCOL: proxy_protocol` in task-tracker). The app nginx must `listen 443 ssl proxy_protocol; http2 on;`, `set_real_ip_from <edge subnet 10.250.250.0/24>; real_ip_header proxy_protocol;`, pass `X-Forwarded-Proto https` to Laravel and configure Laravel trusted proxies (`$middleware->trustProxies(at: '*')` acceptable only because php-fpm is reachable solely from the nginx container) so URL generation and secure cookies work. Cloudflare sits in front, so the true client IP is in `CF-Connecting-IP`, which is only trustworthy if you restrict to Cloudflare ranges (Hetzner firewall already does that); log it but do not use it for authorization. [CITED: edge nginx.conf, compose.server.yaml; trust details ASSUMED]

### Pattern 3: i18n architecture
- Server: `lang/en`, `lang/sk`, `lang/pl` plus JSON files. Middleware `SetLocale` picks locale in order: authenticated user preference (Phase 2 column, until then skip), `locale` cookie, `Accept-Language` negotiated against `['sk','pl','en']`, fallback `en`. Store with a `locale` cookie from the switcher (`POST /locale`), `app()->setLocale()`, and `Carbon::setLocale()`.
- Share via `HandleInertiaRequests::share`: `locale` and `messages` (for the current locale only, loaded from a JSON file generated or read from `lang/{locale}.json`). Keep one source of truth: UI strings in `lang/{locale}.json` consumed by both Laravel `__()` and vue-i18n (vue-i18n takes messages as JSON). Avoid duplicating strings in PHP arrays and JS.
- Client: `createI18n({ legacy: false, locale, fallbackLocale: 'en', messages: { [locale]: messages }, pluralRules: { sk, pl } })`. vue-i18n default plural rule handles only 2-3 choices; supply custom rules for the Slavic forms (Slovak: 1 / 2-4 / 5+ with fractions; Polish: 1 / 2-4 excluding 12-14 / other). Alternative that avoids hand-written rules: use `Intl.PluralRules(locale)` inside a custom `pluralRules` function mapping category (`one`, `few`, `many`, `other`) to message index, with messages written as `one | few | many | other`. [ASSUMED: vue-i18n 11 custom pluralRules API shape, verify against docs when implementing; Context7/ctx7 not available in this session]
- Dates and numbers: `Intl.DateTimeFormat` / `Intl.NumberFormat` via vue-i18n `datetimeFormats` / `numberFormats` per locale (`sk-SK`, `pl-PL`, `en-GB`). Server: Carbon `isoFormat`, `Number::format($n, locale: ...)` (needs `intl`). Install `ext-intl` in the PHP image (the host dev machine has it; verify in the image).
- Laravel plural: `trans_choice` works for `sk` and `pl` (`MessageSelector` contains `sk` and `pl` rules) [VERIFIED: laravel/framework 13.x MessageSelector.php]. Prefer ICU-style correctness tests in both layers.
- Verifiable proof for success criterion 2: a test page (or the placeholder home page) that renders a pluralised item count, a date, and a number in each locale, and Pest + Vitest assertions on exact strings (e.g., 1 / 2 / 5 / 22 / 12 items in Polish; 1 / 3 / 5 in Slovak; `1 234,56` formats).

### Pattern 4: PWA with a controlled service worker
Use `vite-plugin-pwa` with `strategy: 'injectManifest'` and a hand-written `resources/js/sw.js` so cache behaviour is explicit and testable:
- Precache only `self.__WB_MANIFEST` (hashed build assets, icons) and nothing dynamic. Do not register `registerRoute` for HTML/Inertia/XHR/JSON requests. Because Inertia pages are server-rendered per user and returned as HTML/JSON, they must never be cached; do not add a navigation fallback to a cached `index.html` (Laravel has no static shell HTML). For offline, serve a tiny static `offline.html` (precached) as a navigation fallback when the network fails.
- `registerType: 'prompt'` (not silent autoUpdate) to avoid a stale shell breaking Inertia version mismatches; Inertia handles asset version changes with a hard reload on 409.
- Manifest: `name`, `short_name`, `start_url: '/'`, `scope: '/'`, `display: 'standalone'`, `theme_color`/`background_color` (take from sketch theme `default.css`), icons 192, 512 and maskable 512, plus an apple-touch-icon 180 `<link>` and `<meta name="apple-mobile-web-app-capable">` for iOS Add-to-Home-Screen. [ASSUMED: iOS specifics from training; verify on an actual iPad]
- Serve `sw.js` and manifest from site root with `Cache-Control: no-cache` (nginx location), hashed assets with `immutable`, 1-year.
- Automated guard (FOUND-03 "never medical or finance data"): a Vitest/Node check that parses the built `sw.js` / `dist` precache manifest and fails if it contains anything other than `/build/assets/*`, icons, manifest and `offline.html`, plus a source check that `sw.js` contains no `registerRoute`/`runtimeCaching`. Also add `Cache-Control: no-store` (or `private, no-store`) middleware to all authenticated HTML/JSON responses as defence in depth.
- Vite config: `laravel-vite-plugin` writes to `public/build`. Set `VitePWA({ injectManifest: { globDirectory: 'public', globPatterns: ['build/assets/**/*.{js,css,woff2}'] }, outDir: 'public', ... })`; the SW must be at `/sw.js` (scope `/`). Exact option wiring with laravel-vite-plugin is a known friction point; spike it first. [ASSUMED]

### Pattern 5: Encrypted backup with proven restore (FOUND-04)
- Tool: restic on the host (installed, 0.19.1). Repository location outside the Docker volume, e.g. `/var/backups/restic/family-hub` (a `/var/backups/restic` directory already exists on the server, owner purpose not inspected; use a distinct subdirectory and do not touch existing content). Repository password in a root/deploy-only file (`/opt/family-hub/secrets/restic_password`, 0600) and the owner must also store it off-server, otherwise the backup is unrecoverable if the VPS dies. Off-site copy (rclone/S3/SFTP) is better practice but not required by FOUND-04; list as an open question.
- Backup job: `docker compose exec -T postgres pg_dump -U <owner> -Fc family_hub | restic backup --stdin --stdin-filename family_hub.dump --tag prod`; then `restic forget --keep-daily 14 --keep-weekly 8 --keep-monthly 6 --prune`; weekly `restic check`. Cron under `deploy` (same convention as mail: 03:30 backup, cert renew 04:10, task-tracker certs 03:17), logging to a file, and failure surfaced (non-zero exit logged; optional email via local Stalwart later).
- Pre-migration safety dump in `deploy.sh` (task-tracker pattern) should also go through restic or be encrypted, since the existing script leaves plain `.dump` files in `/var/backups/<stack>` (0600). For Family Hub, write the pre-migration dump encrypted or delete it after a verified restic snapshot. Do not copy the unencrypted pattern.
- Restore drill (success criterion 4): `scripts/restore-drill.sh` restores the latest snapshot (`restic dump latest family_hub.dump | pg_restore`) into a throwaway database `family_hub_restore_test` (created and dropped inside the postgres container), runs sanity queries (migrations table row count, table count equals source), prints a result line, and drops the DB. Run it for real on staging at least once and record the output in the phase verification evidence. Also schedule it monthly. A backup that has never been restored is not accepted by FOUND-04.
- Include `uploads` volume in backups later (Phase 7); design the script now with a list of paths so later phases only append. Never back up CLI credential volumes (later phases).

### Pattern 6: Staging synthetic data (FOUND-05)
- Staging data is generated, never copied from production: `php artisan staging:seed` (Faker, Slovak/Polish/English names, obviously fake) guarded by `app()->environment('production')` abort plus `deploy.sh` preflight identical in spirit to task-tracker's `preflight_reseed` (production directory/domain/project must not carry `STAGING_RESEED` or `staging.seed=true`).
- Never restore a production backup into staging; the restore drill uses staging's own backups or a scratch DB on the same stack only. Document this rule. Banner/`X-Robots-Tag: noindex` on staging, `APP_ENV=staging`.
- Phase 1 has no medical/finance tables, so the seed is minimal now (placeholder users or nothing) but the mechanism, guard and test must exist so later phases extend seeders. Add a Pest test that the seed command refuses to run in `production`.

### Anti-Patterns to Avoid
- **Terminating TLS at the edge or publishing ports from the app stack:** the edge is passthrough and sole owner of 80/443; app stacks publish nothing.
- **Putting DB passwords in `.env`:** task-tracker's deploy.sh actively rejects this; copy the split (`.env` compose-only, `app.env` runtime, `migrate.env` owner).
- **Running migrations at container start in the app/worker:** migrations only in the one-shot `migrate` container after a verified backup.
- **Runtime caching of any dynamic request in the service worker, or `skipWaiting` without user prompt.**
- **Editing edge `nginx.conf` without backup, via `mv`/`sed -i`, or without `nginx -t` first.**
- **Using Laravel `queue:work` with `QUEUE_CONNECTION=sync` or `cache` on `file`:** use `database` queue; set cache store to `database` too (no Redis).

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| Encrypted, retained, verifiable backups | openssl/gpg wrapper scripts with find-based rotation | restic (`backup --stdin`, `forget --prune`, `check`) | Encryption, dedupe, retention and integrity checks already solved and installed |
| Service worker precache manifest | Hand-built asset list/hashing | Workbox `injectManifest` via vite-plugin-pwa | Revisioning and cleanup of old caches |
| Plural rules for sk/pl | `if n % 10 ...` chains | `Intl.PluralRules` (client), Laravel `trans_choice` (server) | CLDR rules are correct and maintained |
| Date/number/currency formatting | Custom formatters | `Intl.*` + Carbon/`Number` | Locale conventions (decimal comma, nbsp thousands) |
| TLS certs | Self-managed openssl | certbot dns-cloudflare image (as task-tracker) | Renewal flow already proven on this server |
| Deploy orchestration | New bespoke scripts | Adapt task-tracker `deploy.sh`/`cert-renew.sh`/smoke | Proven preflights, rollback via `.last-good-tag` |
| Queue | Custom jobs table or Redis | Laravel `database` queue driver | Locked decision |

**Key insight:** every hard part of this phase already has a proven solution either on the owner's server (deploy, TLS, edge) or in the ecosystem (restic, Workbox, Intl). The risk is in wiring and verification, not invention.

## Runtime State Inventory

Not a rename/migration phase. Greenfield; skipped. One live-server change is involved (edge map lines) and is covered under Pitfalls and Open Questions.

## Common Pitfalls

### Pitfall 1: Edge change breaks other sites
**What goes wrong:** A bad `nginx.conf` edit or reload takes down task-tracker and mail (edge owns 80/443).
**How to avoid:** Copy to `nginx.conf.pre-family-hub`, edit in place (`cat new > nginx.conf`), `docker exec edge-edge-1 nginx -t`, then `nginx -s reload`. Add map lines only after both Family Hub nginx containers are running on network `edge` (the resolver is runtime so stopped stacks are fine, but verify). Surface to owner before executing (CONTEXT requirement). Do staging line first.
**Warning signs:** `nginx -t` errors; SNI log lines with `upstream=127.0.0.1:1`.

### Pitfall 2: Hostnames and DNS not decided
**What goes wrong:** Cert issuance (DNS-01) and edge map need final hostnames; Cloudflare DNS records must be created in the dashboard (the stored token can only edit DNS but exists for certbot, record creation may or may not be permitted).
**How to avoid:** Open question to the owner: names such as `family-hub.vostack.dev` and `family-hub-stage.vostack.dev` (pattern matches `work-tracker.vostack.dev` / `work-tracker-stage.vostack.dev`). Make hostnames variables everywhere.

### Pitfall 3: Memory and disk pressure on a 3.8 GB / 16 GB-free server
**What goes wrong:** Two more Postgres instances, php-fpm pools and workers exhaust RAM; OOM kills the mail stack. Image layers fill the disk.
**How to avoid:** Mirror task-tracker's Postgres tuning (`shared_buffers=64MB`, `max_connections=20`), set `pm = ondemand`/`max_children` small (e.g. 4) and `memory_limit`, one worker process, compose `mem_limit` per service, `docker image prune -f --filter until=168h` after deploy, backup retention counted in disk budget. Consider whether staging runs `worker`/`scheduler` (could be profile-gated, but then queue/scheduler behaviour is untested on staging; recommended to keep them, small).

### Pitfall 4: Inertia/Laravel behind a TLS proxy generating `http://` URLs or insecure cookies
**How to avoid:** `TrustProxies`, `APP_URL=https://...`, `SESSION_SECURE_COOKIE=true`, `forceScheme` not needed if forwarded-proto is set. Smoke test checks that a redirect `Location` and asset URLs are `https`.

### Pitfall 5: Service worker caches authenticated content or serves a stale shell
**How to avoid:** See Pattern 4 (no runtime routes, build-time allowlist test, prompt update, `no-store` on dynamic responses). Test in a real browser (Playwright or manual) that after login, DevTools Cache Storage holds only precache entries.

### Pitfall 6: Locale/plural bugs only visible in sk/pl
**How to avoid:** Tests with exact strings for boundary counts (0,1,2,4,5,12,14,22,25), and long-string layout check (Polish/Slovak labels longer than English). Never concatenate translated fragments.

### Pitfall 7: Backup that cannot be restored
**How to avoid:** Run the restore drill, store the restic password off-server, test that `pg_restore` version matches `pg_dump` major (both 18, run both inside the postgres container image), verify extensions/roles (dump with `--no-owner --no-privileges` or restore as owner role). Monitor backup freshness (alert if newest snapshot older than 26 h).

### Pitfall 8: Host dev environment gaps
**What goes wrong:** Host has PHP 8.3 and Composer, but `pdo_pgsql` is not listed in `php -m` output (only `intl`, `mbstring` matched the filter) and Node is 22.23 (OK for Vite 8: needs >=20.19 and vue-i18n >=22). Local tests needing Postgres will fail on host.
**How to avoid:** Run composer/pest/artisan inside the dev container (`docker compose run --rm app ...`) with a Postgres service; CI does the same.

### Pitfall 9: `laravel-vite-plugin` 3.x requires Vite 8 and `vite-plugin-pwa` 2.0 is brand new
**How to avoid:** Pin exact versions in `package.json`/lockfile, commit lockfiles, build in CI with `npm ci`, and spike the PWA build first.

## Code Examples

### Edge map lines (to be surfaced to owner, applied after backup)
```nginx
# Source: /opt/edge/nginx.conf pattern (read from live server)
map $ssl_preread_server_name $wt_upstream {
    # existing lines unchanged ...
    family-hub.vostack.dev        fh-prod-nginx:443;
    family-hub-stage.vostack.dev  fh-stage-nginx:443;
    default                       127.0.0.1:1;
}
```

### Compose nginx attached to edge (copy of task-tracker pattern)
```yaml
# Source: /opt/work-tracker/compose.server.yaml (adapted)
nginx:
  image: ${IMAGE_PREFIX:?}-nginx:${IMAGE_TAG:?}
  restart: unless-stopped
  environment:
    NGINX_PROXY_PROTOCOL: proxy_protocol
    APP_DOMAIN: ${APP_DOMAIN:?}
  volumes:
    - ./letsencrypt:/etc/letsencrypt:ro
  networks:
    default: {}
    edge:
      aliases: ["${EDGE_ALIAS:?}"]
  depends_on: [app]
networks:
  edge: { external: true, name: edge }
```

### CI deploy job (adapt from task-tracker ci.yml)
```yaml
# Source: voslarA4N/task-tracker .github/workflows/ci.yml (read via gh api)
deploy:
  needs: check
  if: github.event_name == 'push' && (github.ref == 'refs/heads/main' || github.ref == 'refs/heads/develop')
  environment: { name: ${{ github.ref_name == 'main' && 'production' || 'staging' }} }
  concurrency: { group: deploy-${{ github.ref_name }}, cancel-in-progress: false }
  permissions: { contents: read, packages: write }
  # steps: pick TARGET_DIR/APP_DOMAIN by branch; docker login ghcr; build+push -app and -nginx images
  # tagged sha-$GITHUB_SHA and branch; ssh key from secrets; scp compose+scripts; ssh 'IMAGE_TAG=sha-... sh ./deploy.sh'; smoke
```
Required GitHub secrets/environments in the new repo (`ThomasV88/family-hub`, owner differs from task-tracker's `voslarA4N`): `DEPLOY_SSH_KEY`, `DEPLOY_KNOWN_HOSTS`, `DEPLOY_HOST`, `DEPLOY_USER`; environment `production` with required reviewers. GHCR package visibility/permissions for the new repo and the server's pull login must be set up. Note the existing workflow uses `GITHUB_TOKEN` to docker login on the server per deploy, then logs out.

### Backup and restore drill
```sh
# Source: restic docs pattern (backup --stdin) [ASSUMED flags, confirm with `restic backup --help` on 0.19.1]
export RESTIC_REPOSITORY=/var/backups/restic/family-hub RESTIC_PASSWORD_FILE=/opt/family-hub/secrets/restic_password
docker compose -f compose.server.yaml exec -T postgres pg_dump -U "$DB_OWNER" -Fc family_hub \
  | restic backup --stdin --stdin-filename family_hub.dump --tag prod
restic forget --keep-daily 14 --keep-weekly 8 --keep-monthly 6 --prune
# drill
restic dump latest family_hub.dump | docker compose exec -T postgres sh -c \
  'createdb -U "$U" family_hub_restore_test && pg_restore -U "$U" -d family_hub_restore_test --no-owner'
```

### Service worker (precache only)
```js
// Source: Workbox injectManifest pattern [ASSUMED]
import { precacheAndRoute, cleanupOutdatedCaches } from 'workbox-precaching'
cleanupOutdatedCaches()
precacheAndRoute(self.__WB_MANIFEST)           // hashed assets only
// no registerRoute(), no runtime caching of HTML/JSON
self.addEventListener('message', (e) => { if (e.data?.type === 'SKIP_WAITING') self.skipWaiting() })
```

## State of the Art

| Old Approach | Current Approach | When Changed | Impact |
|--------------|------------------|--------------|--------|
| Laravel Mix / Vite 5-6 | Vite 8 (laravel-vite-plugin 3.x requires it) | current | Pin versions; Node >=20.19 |
| Inertia 1/2 | Inertia 3 (`inertia-laravel` 3.x, `@inertiajs/vue3` 3.x) | current | Check Inertia 3 upgrade guide for API changes before writing pages [ASSUMED: breaking changes not reviewed] |
| `vite-plugin-pwa` 1.x | 2.0.0 | 03 Oct (per GitHub releases page) | New major, read migration note |
| Unencrypted dump files (existing task-tracker) | Encrypted restic repo | this phase | Required by FOUND-04 |

## Assumptions Log

| # | Claim | Section | Risk if Wrong |
|---|-------|---------|---------------|
| A1 | All listed packages are legitimate (slopcheck not run) | Package Legitimacy Audit | Supply-chain risk; mitigated by human-verify checkpoint |
| A2 | vue-i18n 11 `pluralRules` option signature and Intl-based approach | Pattern 3 | Wrong plural output; mitigated by exact-string tests |
| A3 | iOS PWA install requirements (apple-touch-icon, meta tags) | Pattern 4 | Install not offered or icon missing on iPad; verify on device |
| A4 | `vite-plugin-pwa` + `laravel-vite-plugin` output wiring for `/sw.js` at root | Pattern 4 | May need custom Vite config or nginx alias |
| A5 | `restic backup --stdin` flags and retention values | Pattern 5 | Script fails; verify with `--help` |
| A6 | Retention numbers (14d/8w/6m) acceptable | Pattern 5 | Owner preference; confirm |
| A7 | `trustProxies('*')` acceptable since fpm only reachable from nginx | Pattern 2 | Spoofed forwarded headers if fpm exposed |
| A8 | Inertia 3 has no breaking changes affecting a minimal setup | State of the Art | Rework of scaffold |
| A9 | Hostnames `family-hub.vostack.dev` / `family-hub-stage.vostack.dev` | Open Questions | Owner decides |
| A10 | The Cloudflare token on the server can create/handle DNS-01 TXT records for new names (as it does for work-tracker) | Pitfall 2 | Cert issuance fails; A-record creation likely manual in dashboard |

## Open Questions (RESOLVED)

RESOLVED: all five questions are turned into owner decisions or checkpoints in plan 01-02 Task 1 (decisions recorded in docs/ops/server.md), 01-08 (GitHub setup) and 01-10 (server provisioning, RAM limits).

1. **Hostnames and DNS records**
   - Known: pattern is `<name>.vostack.dev` and `<name>-stage.vostack.dev`, proxied through Cloudflare.
   - Unclear: final names, who creates DNS records.
   - Recommendation: planner adds a `checkpoint:human-action` for owner to confirm names and create DNS records.
2. **Off-site copy of backups**
   - Known: restic repo on the same VPS protects against data corruption and mistakes, not VPS loss. Server already has `/var/backups/restic` (purpose unknown).
   - Recommendation: Phase 1 meets FOUND-04 with a local encrypted repo and a drill; ask the owner whether to add an off-site restic target (SFTP/S3/B2) and where to keep the repo password.
3. **GitHub repo setup**
   - Repo is `ThomasV88/family-hub` (private status unknown). Needs Actions secrets, `production` environment reviewers, GHCR pull permission, and a `develop` branch (currently only `main` seen locally).
4. **Server RAM budget** — confirm acceptable to add two stacks to a 3.8 GB host, or set limits.
5. **Pre-migration dump encryption** — confirm using restic for pre-migration safety dumps instead of plain files.

## Environment Availability

| Dependency | Required By | Available | Version | Fallback |
|------------|------------|-----------|---------|----------|
| Docker + Compose (server) | FOUND-01 | yes | 29.9.2 / v5.6.1 | none needed |
| Docker (dev host) | local build/test | yes | 29.9.2 | none |
| restic (server) | FOUND-04 | yes | 0.19.1 | gpg present as weaker fallback |
| gpg (server) | alt encryption | yes | system | restic |
| age (server) | alt encryption | no | none | restic |
| PHP + Composer (dev host) | scaffold | yes | 8.3.6 / 2.7.1 | run inside container |
| `pdo_pgsql` on dev host | local tests | not confirmed (`php -m` showed intl, mbstring only) | none | run tests in container |
| Node/npm (dev host) | frontend | yes | 22.23.5 / 10.9.9 | none needed |
| gh CLI | CI setup | yes (authenticated) | - | - |
| slopcheck | package audit | no (`pip` missing) | none | human-verify checkpoint |
| Edge network `edge`, edge-edge-1 | FOUND-01 | yes, running | - | none |
| Server resources | FOUND-01 | 3.8 GB RAM (~2.7 GB available), 16 GB free disk | - | set limits, prune images |
| SSH `my-server-agent` (read probes done, as `aiagent` with sudo) | ops | yes | - | - |

**Missing dependencies with no fallback:** none blocking. Hostnames/DNS and GitHub secrets need owner action.

## Validation Architecture

### Test Framework
| Property | Value |
|----------|-------|
| Framework | Pest 5.x (PHP, via pest-plugin-laravel) + Vitest 5.x (JS) |
| Config file | none yet, see Wave 0 (`phpunit.xml`/`Pest.php`, `vitest.config.js`) |
| Quick run command | `docker compose run --rm app ./vendor/bin/pest --parallel` and `npx vitest run` |
| Full suite command | `docker compose run --rm app ./vendor/bin/pest && npx vitest run && npm run build && node scripts/check-sw-precache.mjs` |

### Phase Requirements to Test Map
| Req ID | Behavior | Test Type | Automated Command | File Exists? |
|--------|----------|-----------|-------------------|-------------|
| FOUND-01 | App responds 200 over https through edge, staging noindex, prod not | smoke (post-deploy) | `sh scripts/smoke-server.sh <domain>` | Wave 0 |
| FOUND-01 | Compose files valid, no published ports, edge alias present | static | `docker compose -f compose.server.yaml config` + grep script | Wave 0 |
| FOUND-02 | Locale switch sets cookie, returns localized strings | feature (Pest) | `pest --filter=LocaleTest` | Wave 0 |
| FOUND-02 | Plurals sk/pl/en exact strings; date/number formats | unit (Vitest + Pest) | `npx vitest run tests/js/i18n.test.js` | Wave 0 |
| FOUND-02 | All three `lang/*.json` have identical key sets | unit | Pest/Vitest key-parity test | Wave 0 |
| FOUND-03 | manifest valid (name, icons, display, start_url) and served | unit/smoke | Vitest reading built `manifest.webmanifest` | Wave 0 |
| FOUND-03 | SW precache contains only allowed build assets, no runtime routes | build check | `node scripts/check-sw-precache.mjs` | Wave 0 |
| FOUND-03 | Authenticated-style responses carry `no-store` | feature (Pest) | `pest --filter=CacheHeadersTest` | Wave 0 |
| FOUND-03 | Install on phone/iPad, Lighthouse PWA check | manual | owner device check | manual-only (real device needed) |
| FOUND-04 | Backup creates a snapshot, repo is encrypted (cannot list without password) | integration | `sh scripts/backup.sh && restic snapshots` on staging | Wave 0 |
| FOUND-04 | Restore drill into scratch DB succeeds, table/row counts match | integration | `sh scripts/restore-drill.sh` on staging | Wave 0 |
| FOUND-05 | `staging:seed` refuses in production; deploy guard rejects prod reseed | feature + shell | `pest --filter=StagingSeedTest`, `sh scripts/test-deploy-guards.sh` | Wave 0 |

### Sampling Rate
- **Per task commit:** Pest quick + Vitest run
- **Per wave merge:** full suite plus `npm run build` and SW precache check
- **Phase gate:** full suite green, a real staging deploy via `develop`, a production deploy via PR to `main`, a recorded restore drill, and the manual install check

### Wave 0 Gaps
- [ ] Laravel scaffold, Pest and Vitest configuration, CI workflow
- [ ] `tests/Feature/LocaleTest.php`, `tests/js/i18n.test.js`, key-parity test
- [ ] `scripts/check-sw-precache.mjs`, `scripts/smoke-server.sh`, `scripts/restore-drill.sh`, guard test script
- [ ] Postgres service in dev compose and in CI (`postgres:18-alpine`, as task-tracker CI)

## Security Domain

### Applicable ASVS Categories (Level 1)

| ASVS Category | Applies | Standard Control |
|---------------|---------|-----------------|
| V2 Authentication | no (Phase 2) | Phase 1 only a placeholder page; no auth code |
| V3 Session Management | partial | Secure cookies config (`SESSION_SECURE_COOKIE`, `SameSite=Lax`, HttpOnly) set now |
| V4 Access Control | no (Phase 2) | n/a; but no unauthenticated data endpoints exist |
| V5 Input Validation | partial | Locale switch validated against allowlist `sk|pl|en` |
| V6 Cryptography | yes | restic (AES-256 repo encryption), Laravel `APP_KEY` generated per env and never in git; TLS via Let's Encrypt |
| V14 Configuration | yes | Secrets split across `.env`/`app.env`/`migrate.env`/`secrets/`, CI secret-in-git scan (copy task-tracker step), non-root containers, DB not published, security headers (HSTS, `X-Content-Type-Options`, CSP baseline) |

### Known Threat Patterns

| Pattern | STRIDE | Standard Mitigation |
|---------|--------|---------------------|
| Secrets committed to git | Information disclosure | CI grep step (task-tracker pattern) + `.gitignore` |
| Production data in staging | Information disclosure | Seed-only staging, deploy guards, no cross-restore |
| Plaintext backups at rest | Information disclosure | restic encryption; no plain `.dump` retained |
| Service worker caching private data | Information disclosure | Precache-only SW, `no-store`, build-time check |
| Spoofed forwarded headers | Spoofing | PROXY protocol only from edge network, fpm not exposed |
| DB credential over-privilege | Elevation | Separate owner (migrations) and rw (runtime) roles as in task-tracker |
| Backup password loss | Denial of service/availability | Store off-server; document in runbook |
| Debug mode on | Information disclosure | `APP_DEBUG=false`, smoke test asserts no stack trace on 404/500 |

## Sources

### Primary (HIGH confidence)
- Live server probes via `ssh my-server-agent` (read-only): `/opt/work-tracker/compose.server.yaml`, `deploy.sh`, `cert-renew.sh`, `/opt/edge/nginx.conf`, `/opt/mail/scripts/mail-backup.sh`, resources, installed tools
- `gh api repos/voslarA4N/task-tracker/contents/.github/workflows/ci.yml` (CI/deploy pattern)
- Composer registry (`composer show -a`) for laravel/framework 13.x (php ^8.3), inertia-laravel 3.x; npm registry for versions and peer dependencies
- laravel/framework 13.x `MessageSelector.php` (sk, pl rules present)
- github.com/vite-pwa/vite-plugin-pwa/releases (2.0.0 latest, breaking-change details not available)

### Secondary (MEDIUM confidence)
- WebSearch on Laravel 13 + Inertia 3 + Vue starter kits (third-party kits exist; not used)

### Tertiary (LOW confidence / training knowledge)
- vue-i18n plural API details, iOS PWA requirements, restic flag details, Workbox wiring with laravel-vite-plugin (marked [ASSUMED] above); Context7/ctx7 was not available in this session

## Metadata

**Confidence breakdown:**
- Standard stack: MEDIUM-HIGH, versions verified, legitimacy audit tool unavailable
- Architecture and deployment: HIGH, copied from verified live pattern
- PWA and i18n API specifics: MEDIUM, from training knowledge, need a spike
- Pitfalls: MEDIUM-HIGH

**Research date:** 2026-11-12
**Valid until:** 2026-12-12 (vite-plugin-pwa 2.0 and Inertia 3 are new; re-check versions at install)
