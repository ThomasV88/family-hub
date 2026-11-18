<!-- generated-by: gsd-doc-writer -->
# FamilyHUB

FamilyHUB is a shared web app for an extended family to organize everyday life in one place. It is responsive (phone, desktop, iPad), installable as a PWA, and the interface is available in Slovak, Polish and English. Every member sees only what they are permitted to see.

## Modules

- **To-do**: shared and personal tasks, with recurrence and reminders.
- **Wishlist**: gift wishlists with link autofill (title, description and image from the first pasted URL) and hidden reservations, so the recipient never sees who reserved what.
- **Finance**: family finances, including bank CSV import.
- **Medical records**: health records and documents, with AI document reading (review before save), full-text search and bulk upload.
- **Shopping lists** and **recipes**.
- **Per-module permissions**: enforced server-side; health and finance are restricted by default.
- **Audit log** and an administration area.

## Tech stack

- Laravel (PHP) with Inertia + Vue; PWA via `vite-plugin-pwa`.
- PostgreSQL; database-backed queue (no Redis) with a `worker` and a `scheduler` container.
- AI CLIs run only in a separate, locked-down `ai-worker` container with its own credential volumes; the app uses subscription logins, not per-token API keys.
- Fortify and Socialite for authentication (email/password, Google, TOTP).
- Docker Compose services: `app`, `nginx`, `worker`, `ai-worker`, `scheduler`, `postgres`.
- Tests: Pest (PHP) and Vitest (JS); style: Pint.

## Running locally

Requires Docker with Compose and Node.js with npm.

```bash
cp .env.example .env
printf 'UID=%s\nGID=%s\n' "$(id -u)" "$(id -g)" >> .env

docker compose build app
docker compose up -d --wait postgres
docker compose run --rm app composer install
docker compose run --rm app php artisan key:generate --force

npm ci
npm run build

docker compose up -d --build --wait
docker compose run --rm app php artisan migrate
```

Open <http://localhost:8080/>. Create the first administrator with:

```bash
docker compose run --rm app php artisan family:create-admin you@example.com --name="Your Name" --locale=en
```

`scripts/smoke-local.sh` checks that the dev stack answers through nginx. For frontend work, `npm run dev` starts the Vite dev server. `composer setup` and `composer dev` are also defined in `composer.json` for running without Docker (you then need your own PostgreSQL configured in `.env`).

## Tests and checks

These are the commands CI runs (`.github/workflows/ci.yml`, job `check`):

```bash
bash scripts/check-secrets.sh                      # secret scan
npx vitest run                                     # JS unit tests
npm run build                                      # Vite build + service-worker precache check
docker compose run --rm app ./vendor/bin/pint --test
docker compose run --rm app ./vendor/bin/pest      # Pest on PostgreSQL
bash scripts/test-deploy-guards.sh                 # deploy guard tests
bash scripts/test-backup-local.sh                  # backup and restore drill
```

CI also runs compose and nginx template checks (`scripts/check-compose.sh`, `scripts/check-nginx-template.sh`), a server PostgreSQL init test and a provisioning dry run. The build step runs `scripts/check-sw-precache.mjs` after `vite build`.

## Deployment

Deployment is automated by CI and runs on a single VPS with Docker Compose behind an edge proxy.

- Push or merge to `develop` deploys to **staging**.
- A pull request from `develop` into `main`, merged with a merge commit, deploys to **production** (the `production` environment requires owner approval).
- Other branches and pull requests only run the `check` job.
- Each deploy builds and pushes the app, ai-worker and nginx images, deploys over SSH with `scripts/deploy.sh`, then runs `scripts/smoke-server.sh`.
- Test on staging only; production is reached only through the PR into `main`.

Server layout, hostnames, memory limits, backups and the restore drill are described in `docs/ops/server.md`.

## Documentation

- `docs/ops/`: operations runbook (`server.md`) and staging data notes (`staging-data.md`).
- In-app user manual at `/manual`: topic files in `resources/manual/<locale>/<topic>.json` for `en`, `sk` and `pl`.
- Contextual help (the "?" tips): registry in `resources/help/topics.json`, texts in `resources/help/en.json`, `sk.json`, `pl.json`.
- `.planning/`: project requirements, roadmap, state and phase plans.

## Contributing

Project rules are in `CLAUDE.md`:

- **GSD workflow**: file-changing work starts through a GSD command (`/gsd-quick`, `/gsd-debug`, `/gsd-execute-phase`) so planning artifacts stay in sync.
- **User documentation**: any user-visible change must update the matching manual topic and the contextual help in all three languages (English is the source; Slovak and Polish drafts need native review), and must pass `tests/js/manual/ManualCoverage.test.js`, `tests/js/help/HelpCoverage.test.js` and the Pest tests in `tests/Feature/Manual`.
- **Issue tracking**: every bug or feature request is recorded as a GitHub issue (`bug` or `enhancement`) before work starts; commits that resolve one carry `Fixes #N`.
- The repository is public: never commit secrets, real data or private keys. CI runs a secret scan.
