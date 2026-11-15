# Stack Research

> **SUPERSEDED (2026-11-12) for the application stack.** The owner chose Laravel (PHP) with Inertia + Vue, PostgreSQL, a Postgres-backed queue, Fortify + Socialite and a custom policy layer (see PROJECT.md Constraints). Everything below about TypeScript, Next.js, Drizzle, pg-boss, Better Auth, next-intl and execa is NOT the chosen stack. **PART 1 (AI provider verdicts) remains valid** and applies unchanged: Codex CLI primary, Copilot CLI fallback, Claude Code and Antigravity excluded from v1. Any library mentioned for PHP must be verified when planning each phase.

**Domain:** Self-hosted multi-user family organization PWA (to-do, wishlist, finance, medical records) with subscription-CLI-driven AI OCR
**Researched:** 2026-11-12
**Confidence:** MEDIUM overall. AI-provider facts are HIGH where taken from vendor docs and LOW where only community sources exist (flagged per item). Library versions were read from the npm registry on the research date. Framework recommendations rest on ecosystem knowledge and were not re-verified against Context7.

---

## PART 1: AI PROVIDER VERDICTS (the critical question)

The owner's constraint is that AI runs on subscriptions, with no per-token API billing. In this research the CLIs are the only machine path, so their terms matter as much as their flags.

### Verdict summary

| Provider | Headless CLI | Image/PDF input | Enforced JSON | Subscription on headless server | ToS for server/automated use | Verdict |
|---|---|---|---|---|---|---|
| **OpenAI Codex CLI** (ChatGPT plan) | Yes, `codex exec` | Images via `-i`. PDF is not documented, so rasterize first | Yes, `--output-schema` | Yes, `codex login --device-auth` (beta), or copy `auth.json`. Refreshes in place | Most permissive. Docs describe ChatGPT-managed auth for automation as an advanced option for trusted runners | **INCLUDE: primary** |
| **GitHub Copilot CLI** (Copilot plan) | Yes, `copilot -p` | Images and documents via `--attachment` | **No** schema enforcement. `--output-format json` is a JSONL event stream | Yes, device flow or fine-grained PAT with "Copilot Requests" | Not stated in the CLI docs. AUP prohibits "excessive automated bulk activity". Suspension reports are anecdotal | **INCLUDE: secondary fallback**, with validation and repair loop |
| **Claude Code CLI** (Claude plan) | Yes, `claude -p` | Images and PDFs via the Read tool on file paths (MEDIUM) | Yes, `--json-schema` | Technically yes (`claude setup-token` gives a 1-year `CLAUDE_CODE_OAUTH_TOKEN`) | **Restricted.** Anthropic's legal page: developers must not "route requests through Free, Pro, Max, or Ultra plan credentials on behalf of their users" and must not "collect, store, or intermediate Claude.ai credentials" | **EXCLUDE from v1** (ToS, not technical) |
| **Google Antigravity CLI** (`agy`, Google AI Pro/Ultra) | Yes, `agy -p` | **Not documented** for headless (LOW) | Yes, `--json-schema` | Cached login from one interactive `agy auth login`. Headless runs reuse it | **Ambiguous to restrictive.** Terms Section 6 bans "third party software, tools, or services to access the Service". Only unofficial forum replies tolerate single-user local use | **EXCLUDE from v1** (ToS ambiguity, undocumented image input, stdout bug) |

**The stated exclusion rule is "no machine-usable subscription path".** Under it, no candidate is excluded for technical reasons. Claude and Antigravity are excluded from v1 on terms-of-service grounds. Both were technically workable.

Keep a provider-adapter interface so Claude and Antigravity can be added later if the vendors clarify their terms. Do not ship adapters for them in v1.

---

### 1. OpenAI Codex CLI: INCLUDE (primary), confidence MEDIUM-HIGH

| Question | Finding | Source / confidence |
|---|---|---|
| (a) Headless + subscription login | `codex exec` runs non-interactively. Progress goes to stderr and only the final message to stdout. It reuses saved CLI auth, including ChatGPT login. | Official non-interactive docs (HIGH) |
| (b) Image/PDF | `--image/-i path[,path...]` attaches images to the first message. PDFs are not mentioned in the reference, so rasterize with `pdftoppm -r 200 -png` and pass PNGs. | CLI reference (HIGH for images, PDF gap noted) |
| (c) Structured JSON | `--output-schema <file>` forces the final response to match a JSON Schema. `-o <file>` writes the final message to a file. `--json` gives a JSONL event stream. | CLI reference (HIGH) |
| (d) Provisioning and refresh | `codex login --device-auth` (beta) is the preferred headless flow. Fallbacks are copying `~/.codex/auth.json` from a browser machine, or SSH-forwarding port 1455. Credentials are cached in `~/.codex/auth.json` or the OS credential store (`cli_auth_credentials_store` = `file`/`keyring`/`auto`/`ephemeral`). Tokens refresh automatically in use. OpenAI's CI guidance is to seed `auth.json`, let Codex refresh it in place, and persist the updated file between runs. | Official auth docs (HIGH) |
| (e) ToS | OpenAI's docs call ChatGPT-managed auth in automation an "advanced option" for trusted runners or for users who need ChatGPT/Codex rate limits instead of API usage. They say "Treat `~/.codex/auth.json` like a password" and "Do not use this workflow for public or open-source repositories." API keys are the recommended default. Secondary coverage says OpenAI has allowed ChatGPT plan usage in third-party tools. That coverage was not confirmed against an OpenAI primary page, and third-party OAuth-proxy integrations broke when OpenAI changed a backend check. | Official docs (HIGH) for the first part. Third-party coverage (LOW-MEDIUM) |
| (f) Limit exhaustion | OpenAI does not document the exit code. A third-party source reports a non-zero exit (probably 1) with the message "You've hit your usage limit" plus a retry time. Another source warns `codex exec` can exit 0 without producing a response. Plans use a rolling 5-hour window plus weekly caps. | Third-party (LOW). Verify empirically. |

**Our use fits within the documented path.** The worker spawns the unmodified official CLI under the owner's own login. The app never reads, stores or proxies OAuth tokens. Login happens on the host with `docker compose exec worker codex login --device-auth`, not through the app's settings UI.

Operational notes:
- Codex requires a git repo unless `--skip-git-repo-check` is passed. Use a throwaway temp dir per job, `--ephemeral`, and `--sandbox read-only`.
- Do not parse stderr or exit codes alone. Success means the `-o` file exists, is non-empty and validates against the zod schema.
- `auth.json` must live on a persistent Docker volume that the worker can write, because refresh is in place.
- Usage limits are now shared across ChatGPT and Codex (reported for December). Bulk re-generation can use up the owner's regular ChatGPT allowance.

### 2. GitHub Copilot CLI: INCLUDE (secondary fallback), confidence MEDIUM-LOW

| Question | Finding | Source / confidence |
|---|---|---|
| (a) Headless + subscription | `copilot -p "PROMPT"` runs non-interactively and exits. `-s` gives clean output. Billing is against the Copilot plan, not per token. | Official programmatic reference (HIGH) |
| (b) Image/PDF | `--attachment=PATH` attaches an image or native document. It is repeatable and valid only in non-interactive mode. | Official reference (HIGH) |
| (c) Structured JSON | **No schema enforcement is documented.** `--output-format json` emits JSONL, one object per line, with no fixed schema in the docs. The prompt must demand JSON, then the app validates with zod and retries once with a repair prompt. | Official reference (HIGH for the gap) |
| (d) Provisioning and refresh | Three options: (1) `copilot login`, which uses device flow on headless machines and stores a `gho_` OAuth token. On a server without libsecret it falls back to plaintext in `~/.copilot/config.json`. (2) A fine-grained PAT (`github_pat_`) owned by a personal account with the "Copilot Requests" permission, via `COPILOT_GITHUB_TOKEN`. (3) `ghu_` tokens. Classic `ghp_` PATs are not supported and are silently ignored. An environment variable silently overrides a stored login. Fine-grained PATs expire on a schedule you set, so a rotation reminder is needed. | Official auth docs (HIGH) |
| (e) ToS | The CLI docs say nothing about restrictions on automated use or sharing. GitHub's AUP prohibits "excessive automated bulk activity". One Reddit thread reports an account suspension citing it. This is unverified and anecdotal. No numeric limits are published. | Official docs (HIGH for the silence). Reddit (LOW) |
| (f) Limit exhaustion | GitHub documents only "limit errors... wait and retry". It does not document `-p` exit codes or message text. Plans include an AI-credit allowance. When it runs out you can upgrade or set an "Additional usage" budget. | Official usage-limits page (HIGH for the gap) |

**Must-do:** Set the Copilot "Additional usage" budget to $0. Otherwise overage would turn into pay-per-use billing and break the owner's no-per-token rule. Throttle jobs (concurrency 1, spacing between runs) because the AUP language is about bulk automation. Use Copilot only as the fallback, not for bulk re-generation.

### 3. Claude Code CLI: EXCLUDE from v1, confidence HIGH on the ToS reading

Technically it is the best fit:
- `claude -p --output-format json --json-schema '{...}'` returns the object in `structured_output`. If the model fails to produce valid output, the field is missing.
- `claude setup-token` makes a one-year `CLAUDE_CODE_OAUTH_TOKEN` for CI.
- Stored login on Linux is `~/.claude/.credentials.json`, mode 0600.
- Failures print on stdout with a non-zero exit. Retry events carry `error` values such as `rate_limit`, `authentication_failed`, `billing_error`. The error page lists "You've hit your session limit" and "weekly limit".
- `--bare` does not read the OAuth token or login, so a subscription run must omit it.

The legal page (https://code.claude.com/docs/en/legal-and-compliance) is what blocks it:
1. "Anthropic does not permit third-party developers to offer Claude.ai login into their own applications, or to route requests through Free, Pro, Max, or Ultra plan credentials on behalf of their users."
2. "developers may not collect, store, or intermediate Claude.ai credentials or session tokens."
3. "Advertised usage limits for Pro, Max, and Ultra plans assume ordinary, individual usage of Claude Code and the Agent SDK."
4. "Developers building products or services... should use API key authentication."
5. Running Claude Code inside a product or service requires the Commercial Terms, and "each end user must authenticate with their own" credentials.

The project requires "subscription OAuth tokens/logins configured in app settings" and a multi-user family app that processes documents for other people. That collides with points 1 and 2. Bulk re-generation of documents collides with point 3. Anthropic says it may enforce "without prior notice". The risk is losing the owner's Claude account.

The only defensible variant is an operator running `claude auth login` on the host for personal use. It is still ambiguous under point 1 because family members trigger the runs, so it is not worth shipping. Recorded for later:
- Reconsider if Anthropic publishes an explicit allowance for self-hosted personal use.
- Or use a Console API key. That is per-token billing and is out of scope here.

### 4. Google Antigravity CLI (`agy`): EXCLUDE from v1, confidence MEDIUM

Technical facts from the official headless page (https://antigravity.google/docs/cli/headless):
- `-p` runs one prompt.
- `--output-format json|stream-json` is supported.
- `--json-schema` is supported, with the result in `structured_output`.
- Headless uses cached credentials from one prior interactive login. Without them it exits with "authentication required".
- Exit is non-zero on failure and the JSON has `status` and `error` fields.
- Image or file attachments are **not documented** for headless. Quota exhaustion is **not documented**.
- GitHub issue google-antigravity/antigravity-cli#76 reports that `agy -p` can silently drop stdout when stdout is not a TTY (pipe or subprocess). That is exactly how a worker would run it. Whether it is fixed is unknown.

Terms (https://antigravity.google/terms, Section 6): "Using third party software, tools, or services to access the Service" is a breach, with the OpenClaw-via-OAuth example. Suspension of Antigravity and Gemini CLI accounts is named as a consequence. The terms say nothing about headless mode. Forum replies, mostly from non-staff users, say launching the unmodified `agy` locally for a single user is fine. Others say personal subscription quota cannot power external cloud agents. A reported account suspension followed OpenClaw OAuth use.

Verdict: a server-side app that makes a family's requests under one personal Google login sits exactly in the ambiguous zone. Image input is unverified and the stdout bug sits on the critical path. Skip it for v1.

### Fallback behavior (provider-independent design)

Ordered provider list in app settings (default: Codex first, Copilot second). For each job:
1. Run the first enabled provider.
2. On quota exhaustion, mark the provider "cooling down until T" (parse the retry time if present, otherwise back off 1h to 5h).
3. Fall to the next provider.
4. If all are cooling down, set the job to `waiting_for_quota` and let pg-boss retry at the earliest reset.

Detect exhaustion by pattern-matching stderr/stdout for `usage limit|rate limit|quota|too many requests|credits`, not by exit code. Vendor exit codes are undocumented. Keep the patterns in one adapter-owned table so they can be updated without a redeploy. Validate every result against the shared zod schema, accept only on pass, and store `provider`, `model`, `prompt_version` and the raw output with the extraction for review.

**Open item for the first AI phase:** run each CLI against a real limit, a revoked login and a malformed document. Record the actual exit codes and messages in the adapter tests, because nothing here is documented.

### AI safety constraints that follow from the facts

- Medical documents leave the server for OpenAI and GitHub. Check each plan's data-controls and training settings before enabling. Require an explicit per-document "Run AI" action, with a review-before-save screen.
- Documents are untrusted input to an agentic CLI, so a prompt-injection risk exists. Run with the narrowest permissions: Codex `--sandbox read-only` and an empty temp dir. For Copilot do not pass `--allow-all-tools`, and use `--no-ask-user`. Disable network tools and MCP. Run the worker as a non-root user with no access to app secrets, and keep the CLI credential volume separate from the web container.

---

## PART 2: APPLICATION STACK

### Backend language/runtime recommendation: TypeScript on Node.js

**Recommendation: one TypeScript codebase on Node.js 24 LTS (MEDIUM, confirm current LTS), with a separate worker process.** Do not use PHP.

Why:
- Subprocess supervision in Node is mature: `child_process.spawn`/`execa` give timeouts, `AbortSignal` kill, process-group kill, stream capture and stdin piping. The worker needs all of these.
- The three target CLIs (Codex, Copilot, and Claude if added later) are published as npm packages (`@openai/codex` 0.167.2, `@github/copilot` 1.0.109). They install in the same image.
- One language end to end: shared zod schemas for the AI JSON contract, forms, DB and API.
- PHP `exec` has no good story for long jobs that last minutes, must survive restarts, retry and cool down on quota. PHP-FPM request timeouts and worker lifecycles make a supervisor awkward, and you would end up bolting on a queue anyway.
- Go would supervise processes best, but costs a two-language stack (Go API plus TS frontend) for a small family app. Python (FastAPI plus arq/Celery) is also fine, but adds the same split.

### Core Technologies

| Technology | Version | Purpose | Why Recommended | Confidence |
|---|---|---|---|---|
| Node.js | 24 LTS | Runtime for web and worker | Matches Next 16 (needs >=20.9) and pg-boss 12 (needs >=22.12). Run only inside Docker; the host dev machine has Node 18, so use the container or nvm. | MEDIUM |
| TypeScript | 5.9.x pinned (7.0.2 is latest on npm) | Language | TS 7 is the native-compiler major. Confirm Next, Drizzle and eslint tooling work with it before adopting. Pin 5.9 until then. | LOW (tooling compatibility unverified) |
| Next.js (App Router) | 16.4.x | Web UI + API route handlers + server actions | One deployable for UI and API, SSR for fast first load on phones, file-based routing, built-in manifest support for PWA. Large ecosystem for i18n, auth, UI. | HIGH (version), MEDIUM (fit) |
| React | 19.3.x | UI | Required by Next 16 | HIGH |
| PostgreSQL | 17 (18 acceptable) | Primary DB and job queue | One datastore for app data and jobs. No Redis to run or back up. | MEDIUM |
| pg-boss | 12.38.x | Job queue and scheduler on Postgres | Retries with backoff, delayed jobs (`startAfter`) for quota cooldown, cron schedules, singleton keys, concurrency control. Fits a single-server family app. | HIGH (version), MEDIUM (fit) |
| Drizzle ORM + drizzle-kit | 0.45.x / 0.31.x | Schema, migrations, queries | TypeScript-first, SQL-like, migrations are plain SQL files that suit the staging-to-prod flow. | MEDIUM |
| Better Auth | 1.7.x | Email+password, Google sign-in, sessions, invites | Native TypeScript, first-class Drizzle adapter, social providers and email+password built in, with plugins for admin and organization. Actively developed. Auth.js is the older alternative. | MEDIUM (not verified against Context7) |
| next-intl | 4.15.x | i18n for SK/PL/EN | Standard for App Router. It handles ICU plural rules, which matter for Slovak and Polish (multiple plural forms). | HIGH |
| zod | 4.6.x | Validation, AI output contract | One schema serves forms, API, and the AI JSON Schema (zod 4 can emit JSON Schema, which feeds Codex `--output-schema`). | HIGH |
| Tailwind CSS + shadcn/ui | 4.3.x / shadcn CLI 4.23 | Styling and components | Accessible primitives, responsive by default, easy to make touch-friendly for iPad. | HIGH (versions) |

### Supporting Libraries

| Library | Version | Purpose | When to Use |
|---|---|---|---|
| execa | 10.2.x | Spawn and supervise CLI subprocesses | Worker AI adapters. Set `timeout`, `cancelSignal`, `forceKillAfterDelay`, `cleanup`, and capture stdout/stderr separately. |
| Serwist (`@serwist/next` or `@serwist/turbopack`) | 9.5.x | Service worker, offline shell, install support | PWA. Next 16 uses Turbopack by default. Check which Serwist integration matches your bundler before committing. If it does not work, ship a minimal hand-written service worker (app shell only). Do not cache authenticated medical or finance API responses. |
| web-push | 4.0.x | Web Push notifications (reminders) | Wishlist occasion and recurring-task reminders. iOS delivers push only to an installed (Add to Home Screen) PWA. |
| nodemailer | 10.5.x | Invites, password reset, reminders by email | Send through the owner's existing Stalwart SMTP (587) on the `mail` stack. |
| rrule | 2.8.x | Recurrence rules (RFC 5545) | Recurring tasks and expenses stored as RRULE strings. Lets "every 2nd Tuesday" and "monthly on the 1st" work. |
| Recharts | 3.10.x | Charts | Finance overviews. Fine for this data volume. |
| papaparse | 5.7.x | CSV parsing | Bank CSV import with a column-mapping UI and preview. Bank formats differ (delimiter `;`, `,`, encodings like windows-1250), so detect encoding and let the user map columns. |
| cheerio | 1.2.x | Parse HTML for og:image / twitter:image / JSON-LD | Link preview. Fetch in the worker with a timeout, size cap and SSRF guard (see PITFALLS). |
| sharp | 0.35.x | Image resize/thumbnail, strip EXIF, normalize to PNG | Upload thumbnails and OCR input. Strip GPS EXIF from photos. |
| file-type | 22.3.x | Sniff real MIME type from bytes | Upload validation. Never trust the client Content-Type. |
| poppler-utils (`pdftoppm`) | OS package in image | PDF to PNG pages | Rasterize PDFs for Codex, which documents only image input. |
| pino | 10.4.x | Structured logging | Worker and web. Never log document content or extracted medical data. |
| TanStack Query | 5.107.x | Client data fetching/cache | Interactive modules (to-do, finance). Optional if you stay with server components and actions. |
| pg | 8.23.x | Postgres driver | Used by Drizzle and pg-boss. |

### Permissions (RBAC)

**Do not add CASL.** Three levels (none, view, edit) per member per module are a table: `module_permissions(user_id, module, level)`. Add:
- one `requireAccess(user, module, level)` helper in the data-access layer, called by every server action and route handler. Pages must not rely on hiding UI;
- record-level rules (personal tasks, wishlist reservation hidden from the recipient) written as explicit query filters, with tests;
- defaults of `none` for health and finance, and an admin role that can edit the matrix.

Add Postgres row-level security only if you later want defense in depth. It complicates connection handling with pooled Drizzle sessions.

### Development Tools

| Tool | Purpose | Notes |
|---|---|---|
| Docker Compose | Local and server runtime | Services: `web`, `worker`, `postgres`, `nginx`. The nginx joins the existing `edge` network with an alias (`fh-prod-nginx`, `fh-stage-nginx`) and gets a row in `/opt/edge/nginx.conf` per the owner's existing pattern. |
| Vitest + Playwright | Unit and end-to-end tests | Test permission boundaries with E2E per role. |
| ESLint + Prettier | Lint | Standard. |
| Biome (optional) | Single-tool lint/format | Fewer config files than ESLint+Prettier, if the team prefers. |

### Deployment shape

- **Image:** one Dockerfile, two entrypoints (`web`, `worker`). The worker image adds `@openai/codex`, `@github/copilot`, `poppler-utils`, and runs as a non-root user.
- **Volumes:** `pgdata`, `uploads` (not under the web root, served by an authenticated route handler), `codex-home` (`~/.codex`, writable, for refreshed `auth.json`), `copilot-home` (`~/.copilot`). Mount CLI home volumes only into the worker.
- **One-time login:** `docker compose exec worker codex login --device-auth`. Re-run when a refresh fails, and surface "login expired" in the admin settings page by running a cheap health check (for example `codex login status`; confirm it exists in the installed version).
- **Backups:** include `pgdata` and `uploads`. Do not include the CLI credential volumes in plain backups.
- **Environments:** staging (`develop`) and production (`main`) as separate stacks and separate CLI logins, same as the work-tracker flow. Test AI only on staging until confirmed.

## Installation

```bash
# Core
npm install next@16 react@19 react-dom@19 drizzle-orm pg better-auth next-intl zod pg-boss

# Supporting
npm install execa serwist @serwist/next web-push nodemailer rrule recharts papaparse cheerio sharp file-type pino

# Dev dependencies
npm install -D typescript@5.9 drizzle-kit tailwindcss shadcn vitest @playwright/test @types/node @types/papaparse @types/pg

# In the worker image only (Dockerfile)
# npm install -g @openai/codex @github/copilot   (pin versions, upgrade deliberately)
# apt-get install -y poppler-utils
```

## Alternatives Considered

| Recommended | Alternative | When to Use Alternative |
|-------------|-------------|-------------------------|
| TypeScript/Node | Go API + separate frontend | If subprocess supervision or throughput becomes the bottleneck. It is not at family scale. |
| TypeScript/Node | Python (FastAPI + arq) | If the team prefers Python and accepts a separate TS frontend. |
| TypeScript/Node | PHP (Laravel) | Laravel has queues and scheduler built in and is a legitimate full-stack choice. It is the weaker fit for supervising multi-minute CLI children and sharing schemas with the UI. Reasonable only if the owner insists on PHP. |
| pg-boss | BullMQ + Redis | If you need very high throughput or already run Redis. Adds a service to run and back up. |
| Better Auth | Auth.js (NextAuth) | If the team already knows it. Verify its current maintenance status first. |
| Better Auth | Hand-rolled sessions | Not recommended. Password hashing, reset flows and OAuth state are easy to get wrong. |
| Drizzle | Prisma | Prisma is fine if the team prefers its DX. Drizzle's SQL-file migrations are simpler for the ops flow. |
| Next.js | SvelteKit / Nuxt | Equivalent. Chosen Next for the i18n, auth and UI ecosystem. |
| Recharts | Chart.js / ECharts | ECharts for heavy or large charts. Not needed here. |
| Codex primary | Claude/Antigravity primary | Only if terms change. See Part 1. |

## What NOT to Use

| Avoid | Why | Use Instead |
|-------|-----|-------------|
| Storing or pasting subscription OAuth tokens in the app's DB/settings UI | Claude's terms forbid collecting or storing credentials. It is also a security liability, since the web tier would hold the keys to the owner's AI accounts. | Log in on the host or in the worker container. The app only stores which provider is enabled and cooldown state. |
| Extracting tokens to build a custom proxy that calls vendor model endpoints directly | This is the pattern vendors ban (Anthropic) or break without notice (OpenAI backend checks). | Spawn the unmodified official CLI. |
| Any per-token API key path (`ANTHROPIC_API_KEY`, `OPENAI_API_KEY`, `CODEX_API_KEY`) in the worker environment | CLIs silently prefer API keys over stored subscription logins, which would bill per token. Copilot's "Additional usage" budget has the same effect. | Do not set these variables. Assert at worker startup that they are unset. Set Copilot's additional-usage budget to $0. |
| `--bare` with Claude, `--dangerously-skip-permissions`, `--allow-all-tools`, `danger-full-access` | Removes the guardrails around a CLI that reads untrusted medical documents. | Read-only sandbox, empty temp dir, no network or MCP tools. |
| Trusting exit codes or "exit 0" alone | Exit codes for quota errors are undocumented, and `codex exec` can exit 0 with no response. | Validate the output file or `structured_output` against zod. |
| PHP `exec`/`shell_exec` from a web request to run the CLIs | No supervision, timeouts bound to the request, no retry or cooldown, orphaned processes on restart. | Queue a job, run it in a long-lived worker. |
| Redis only for a queue | A second stateful service to run, secure and back up for a family-scale app. | pg-boss. |
| Caching authenticated API responses in the service worker | Medical and finance data would persist on shared family devices. | Cache only the static shell. |
| Public static serving of the uploads directory | Medical files would be reachable by URL. | Authenticated route handler that checks permission, then streams the file. |
| CASL for three permission levels | Extra abstraction for a lookup table. | A table plus one helper. |

## Stack Patterns by Variant

**If Codex hits its limit often because of bulk re-generation:**
- Throttle the bulk job (one document at a time, spaced out) and run it off-peak.
- Park the remainder as `waiting_for_quota` until the reset time.

**If Copilot is not wanted (AUP concern):**
- Run Codex-only. The adapter interface keeps a second provider optional. The app simply surfaces "AI unavailable until HH:MM" and lets the user fill fields manually.

**If Anthropic later permits self-hosted personal use:**
- Add a Claude adapter (`claude -p --output-format json --json-schema`). `--bare` must be omitted for subscription auth.

## Version Compatibility

| Package A | Compatible With | Notes |
|-----------|-----------------|-------|
| next@16.4 | Node >=20.9 | Use Node 24 LTS in the image |
| pg-boss@12 | Node >=22.12 | Rules out Node 20 |
| @types/node@26 | Node 24 runtime | Match types to the runtime major, so pin `@types/node@24` |
| @serwist/next vs @serwist/turbopack | Next 16 bundler (Turbopack default) | Check which integration to use before wiring up. LOW confidence. |
| zod@4 | Better Auth, Drizzle zod helpers | Confirm helper packages support zod 4 |
| typescript@7 | Toolchain | Unverified with Next, Drizzle and lint tooling, so pin 5.9 |

## Sources

- https://code.claude.com/docs/en/legal-and-compliance: Claude Code terms, credential-use restrictions (HIGH)
- https://code.claude.com/docs/en/headless: `claude -p`, `--json-schema`, `--bare`, exit codes, retry events (HIGH)
- https://code.claude.com/docs/en/authentication: `setup-token`, `CLAUDE_CODE_OAUTH_TOKEN`, credential storage, precedence (HIGH)
- https://code.claude.com/docs/en/errors: usage-limit messages and retry watchdog (HIGH)
- https://learn.chatgpt.com/docs/non-interactive-mode (redirected from developers.openai.com/codex/noninteractive): `codex exec`, `--output-schema`, ChatGPT-managed auth guidance (HIGH)
- https://learn.chatgpt.com/docs/auth: `codex login`, `--device-auth`, `auth.json`, refresh, headless guidance (HIGH)
- https://learn.chatgpt.com/docs/developer-commands?surface=cli: `codex exec` flags incl. `--image`, `--output-schema`, `-o`, `--ephemeral` (HIGH)
- https://codex.danielvaughan.com/2026/06/12/codex-cli-exit-codes-error-handling-resilient-shell-scripts-ci-pipeline-automation/: Codex exit codes (LOW, blog)
- https://thenewstack.io/?p=22838568 and https://manifest.build/blog/chatgpt-plus-tokens-third-party-harnesses/: OpenAI third-party subscription use (LOW-MEDIUM, secondary)
- https://docs.github.com/en/copilot/reference/copilot-cli-reference/cli-programmatic-reference: `copilot -p`, `--attachment`, JSONL output (HIGH)
- https://docs.github.com/en/copilot/how-tos/copilot-cli/set-up-copilot-cli/authenticate-copilot-cli: token types, headless auth (HIGH)
- https://docs.github.com/en/copilot/concepts/usage-limits: limits and AI credits (HIGH)
- Reddit Copilot CLI suspension thread (Feb 2026, via search results): AUP enforcement report (LOW, anecdotal)
- https://antigravity.google/docs/cli/headless: `agy -p`, `--json-schema`, cached credentials (HIGH)
- https://antigravity.google/terms: Section 6, third-party tools (HIGH)
- https://discuss.ai.google.dev/ threads on headless `agy` use: community answers, not Google policy (LOW)
- https://github.com/google-antigravity/antigravity-cli/issues/76: stdout dropped when not a TTY (MEDIUM, issue report)
- npm registry (`npm view`), 2026-11-12: all library versions above (HIGH for existence and version, MEDIUM for fit)

---
*Stack research for: self-hosted family organization PWA with subscription-CLI AI extraction*
*Researched: 2026-11-12*
