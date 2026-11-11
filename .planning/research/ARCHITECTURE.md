# Architecture Research

**Domain:** Self-hosted multi-module family web app (PWA) with a background AI-agent OCR job system
**Researched:** 2026-11-11
**Confidence:** MEDIUM. The structural patterns (modular monolith, DB-backed queue, policy layer) are well established and come from training knowledge. Nothing was verified against live docs in this pass. Vendor CLI headless and subscription behaviour is LOW confidence and must be verified in a phase-specific spike.

## Standard Architecture

### System Overview

```
 Browser / PWA (phone, desktop, iPad)
   SPA or SSR UI + service worker (app-shell cache, i18n bundles)
        │ HTTPS (Cloudflare → Hetzner FW → edge nginx SNI → stack nginx)
┌───────▼────────────────────────────────────────────────────────┐
│ Docker Compose stack (one per env: staging / prod)             │
│                                                                │
│  nginx (stack) ── static assets + reverse proxy to app         │
│        │                                                       │
│  ┌─────▼───────────────────── APP (modular monolith) ───────┐  │
│  │ HTTP layer: auth/session, CSRF, validation, i18n         │  │
│  │ ┌──────────────────────────────────────────────────────┐ │  │
│  │ │ Authorization core (membership, module grants,       │ │  │
│  │ │ policy checks, row-level rules) - ONE choke point    │ │  │
│  │ └──────────────────────────────────────────────────────┘ │  │
│  │ Modules: todo | wishlist | finance | medical | settings  │  │
│  │ Shared: files/documents, notifications/reminders,        │  │
│  │         audit log, export, scheduler                     │  │
│  │ AI module (API side): enqueue, review, result schemas    │  │
│  └─────┬───────────────────────────────┬────────────────────┘  │
│        │ SQL                           │ enqueue (DB rows)     │
│  ┌─────▼─────┐   ┌─────────────┐  ┌────▼─────────────────────┐ │
│  │ Postgres  │   │ Files volume│  │ WORKER container         │ │
│  │ (data +   │   │ (outside    │  │ same codebase, no ports  │ │
│  │  job queue│   │ web root,   │  │ ┌──────────────────────┐ │ │
│  │  + audit) │   │ private)    │  │ │ Agent router         │ │ │
│  └───────────┘   └──────▲──────┘  │ │ + quota/fallback     │ │ │
│                         │ read    │ ├──────────────────────┤ │ │
│                         └─────────┤ │ Agent adapters:      │ │ │
│                                   │ │ codex|claude|gemini  │ │ │
│                                   │ │ |copilot (CLI exec)  │ │ │
│                                   │ ├──────────────────────┤ │ │
│                                   │ │ Per-agent auth home  │ │ │
│                                   │ │ (CLI config dirs on  │ │ │
│                                   │ │ volume)              │ │ │
│                                   │ └──────────────────────┘ │ │
│                                   └───────────┬──────────────┘ │
└───────────────────────────────────────────────┼────────────────┘
                                                │ outbound HTTPS only
                                          Vendor AI services
```

### Component Responsibilities

| Component | Responsibility | Typical Implementation |
|-----------|----------------|------------------------|
| Edge/stack nginx | TLS passthrough, host routing, static files, upload size limit | Existing edge plus a stack nginx, new host line in `/opt/edge/nginx.conf`, alias on the `edge` network |
| App (modular monolith) | HTTP API/UI, auth, all business logic, authorization | One deployable. Modules are directories with explicit public interfaces. |
| Auth and identity | Email+password, Google OIDC, invite flow, sessions | Server-side sessions (httpOnly cookie) or a mature auth library. Invite token creates the membership. |
| Authorization core | Decides "may member M do action A (none/view/edit) on module X, and on this row" | Central policy service called by every handler. Grants table `(member, module) -> level`. Row rules per module (private tasks, hidden reservations, medical subject). |
| Modules (todo, wishlist, finance, medical) | Own tables, services, routes, UI | Vertical slices. No cross-module table access. Cross-module needs go through a service interface or an event. |
| Document/file store | Store uploads, serve them only via authorized endpoint | Files on a private Docker volume, DB row holds metadata, path, sha256, owner module, subject. Streamed through the app (or `X-Accel-Redirect` after an auth check). Never in a public web root. |
| Job queue | Durable AI jobs, retries, scheduling, bulk fan-out | Postgres table with `FOR UPDATE SKIP LOCKED` claiming. No Redis needed at family scale. |
| Worker | Claims jobs, runs agent adapters, validates output, writes results | Separate container, same image and codebase, different entrypoint. |
| Agent router | Picks agent/model per job, applies quota state, falls back | Ordered preference list per job type, in settings. Reads and writes `agent_state`. |
| Agent adapters | One per vendor CLI: build argv, set env/home, spawn, parse output, classify errors | Common interface `run(job, prompt, files) -> {raw, usage?} or ErrorKind`. Spawn with an argv array (never a shell string), a timeout, and a scrubbed environment. |
| Credential vault | Encrypted storage of OAuth tokens/CLI auth entered in settings | App-level envelope encryption (AES-256-GCM, key from a Docker secret or env file outside the DB). Admin-only, write-only UI (show status, never the secret). |
| Result validator | Enforces the JSON schema on AI output | JSON Schema (Ajv or the equivalent for the chosen language). Schema versioned per document type. Invalid output means a failed attempt, never stored as data. |
| Review layer | AI output is a proposal, the human confirms | `ai_extraction` rows in state `proposed`. The user reviews and applies them to the medical record fields. |
| Scheduler | Recurring tasks, occasion reminders, quota-reset probes, backups | A scheduler loop in the worker, or cron in a container. Idempotent tick. |
| Notifications | Reminders (in-app, email, optional Web Push) | Outbox table plus worker. The existing mail stack is available for SMTP. |
| Export | Medical export by category/all, AI-friendly format | Read-only service in the medical module that applies the same authorization. Output is PDF/Markdown/JSON. |
| i18n | SK/PL/EN UI strings, dates, currency, plural rules | Message catalogs, per-user locale. Mind the Slovak/Polish plural forms (3 forms). |
| Audit log | Who viewed or changed health/finance data, who ran AI on what | Append-only table. Written by the authorization core and the AI module. |

## Recommended Project Structure

Stack-agnostic, since the stack is chosen in STACK.md. The shape matters more than the language:

```
app/
├── core/                  # cross-cutting, no business modules
│   ├── auth/              # sessions, Google OIDC, invites
│   ├── authz/             # grants, policies, guards (the ONLY place rules live)
│   ├── db/                # connection, migrations runner
│   ├── files/             # storage driver, authorized streaming
│   ├── i18n/              # catalogs sk/pl/en, formatters
│   ├── audit/
│   ├── notify/            # outbox, email, push
│   └── jobs/              # queue API: enqueue, claim, complete, retry
├── modules/
│   ├── todo/              # routes, service, repo, policy, ui
│   ├── wishlist/          # incl. link-metadata fetcher (SSRF-guarded)
│   ├── finance/           # incl. CSV import, recurring detection
│   ├── medical/           # records, documents, export
│   └── ai/                # extraction orchestration, schemas/, prompts/
├── settings/              # members, grants UI, agent config, vault UI
├── worker/
│   ├── main               # claim loop, scheduler tick
│   ├── router             # agent selection, quota/fallback
│   ├── adapters/          # codex, claude, gemini/antigravity, copilot
│   └── sandbox            # spawn helper: argv, timeout, env scrub, temp dir
├── web/                   # frontend, service worker, manifest, locale bundles
deploy/
├── compose.yml            # app, worker, db, nginx
├── compose.staging.yml    # env overrides
└── nginx/
migrations/
```

### Structure Rationale

- **core/authz isolated:** the single audit target for the "private stays private" requirement. Modules declare policies, but evaluation goes through one engine.
- **worker is the same codebase:** it shares models, schemas and the vault, but runs as a different container with different privileges (CLI binaries, outbound network, vendor auth homes). The web container then never holds vendor credentials or executes CLIs.
- **adapters behind an interface:** adding or removing an agent (Antigravity and Copilot are unverified) is a new file, not a schema change.
- **schemas/ and prompts/ versioned in the repo:** reproducible bulk re-generation (see below).

## Architectural Patterns

### Pattern 1: Modular monolith with enforced boundaries

**What:** One deployable, one database, modules in separate directories that talk through public service interfaces. A lint or import rule forbids reaching into another module's tables.
**When to use:** Always here. A handful of modules, a few dozen users, one VPS.
**Trade-offs:** Simple deploys and transactions, a single staging/prod pair. Discipline is needed to keep boundaries. Microservices would add operational cost with no benefit.

### Pattern 2: Central policy enforcement (deny by default)

**What:** Every request resolves `Principal(member)` and goes through `authorize(principal, module, level, resource?)`. Grants are `none|view|edit` per (member, module). Row-level rules sit on top of them. Data access goes through repositories that take the principal, so a forgotten check fails closed. Health and finance start with no grants for non-admins.
**When to use:** Always. UI hiding is cosmetic only.
**Trade-offs:** Some boilerplate. It is far safer than scattered `if` checks. Consider Postgres RLS as a second layer for medical/finance (LOW-MEDIUM: it adds real defence in depth but needs a per-request `SET LOCAL` of the member id).

**Row-level cases to design explicitly:**
- Wishlist reservations: query a different projection for the recipient. The reservation fields are omitted at the query level, not filtered in the UI.
- Private vs shared tasks.
- Medical records have a subject member (child, parent). A grant should say whose records can be seen, not just "medical". Finance may need household vs personal accounts.
- Files and exports inherit the parent record's policy, and file download endpoints re-check it.

**Example:**
```typescript
// handler
const p = await requirePrincipal(req);
await authz.require(p, { module: 'medical', level: 'view', subjectId });
const docs = await medicalRepo.listDocs(p, subjectId); // repo also scopes by principal
```

### Pattern 3: Postgres-backed job queue with attempt records

**What:** `ai_job` (document, kind, schema_version, status, priority, batch_id, run_after) plus `ai_attempt` (job, agent, model, started/finished, outcome, error_kind, raw_output_ref). The worker claims with `SELECT ... FOR UPDATE SKIP LOCKED`. Lease/heartbeat columns recover jobs from a crashed worker.
**When to use:** Low volume and a need for durability and observability without another service.
**Trade-offs:** No Redis or broker to operate, and the queue shares the DB transaction with the results. Throughput is limited, which is irrelevant here, because the vendor CLIs are the bottleneck. Run workers with concurrency 1 per agent, since subscription CLIs rate-limit and parallel logins can collide.

### Pattern 4: Agent adapter plus error taxonomy plus router

**What:** Each adapter maps vendor-specific failures to a small shared set: `QUOTA_EXHAUSTED(reset_at?)`, `AUTH_EXPIRED`, `TRANSIENT`, `BAD_OUTPUT`, `UNSUPPORTED_INPUT`, `TIMEOUT`. The router then applies rules:
- `QUOTA_EXHAUSTED`: mark the agent `cooling_down_until` (from the parsed reset time, or a default backoff), re-queue the job against the next agent in the preference list. If no agent is available, set the job to `waiting_quota` with `run_after = earliest reset`.
- `AUTH_EXPIRED`: disable the agent, raise an admin alert, and fall back.
- `TRANSIENT`: exponential backoff on the same agent, then fall back.
- `BAD_OUTPUT`: retry once with a repair prompt, then fall back to another agent, then `failed` for manual entry.
**When to use:** Always, since quota handling is an explicit requirement.
**Trade-offs:** Quota text from CLIs is not a stable API, so the parsers are fragile. Keep the raw stderr and exit code in the attempt record and make the patterns configurable. An unclassified failure should default to "treat as transient, then fall back", not to a crash.

### Pattern 5: Schema-validated, versioned, reviewable AI output

**What:**
1. Prompt template and JSON Schema live together, versioned (`medical_report.v1`).
2. Ask the agent for JSON only. Extract it, validate it with JSON Schema, and run a semantic sanity check (dates plausible, units known).
3. Store the validated payload in `ai_extraction` with `agent`, `model`, `prompt_version`, `schema_version`, `source_file_sha256`, `status=proposed`.
4. The user reviews it side by side with the document, then applies it to the record fields (`applied`) or rejects it. Manual fields are never silently overwritten.
5. Keep a rejected/invalid raw output reference for debugging.
**Trade-offs:** The review step adds friction, but it is required by the privacy and correctness goals. Where a CLI supports structured output or a schema flag (verify per vendor), use it as a first line, never as the only validation.

### Pattern 6: Bulk re-generation as a batch of ordinary jobs

**What:** "Re-generate" creates a `batch` row and one job per document, with low priority, a throttled start rate, and a cap on how many are in flight. New results land as new `ai_extraction` rows (history kept), never overwriting applied data. Progress is derived from job statuses. Cancelling a batch cancels its pending jobs. Quota exhaustion simply pauses the batch via `waiting_quota`.
**Trade-offs:** More rows, but safe, resumable and auditable. Offer a dry-run count before launching, because a large batch can consume a whole subscription window.

### Pattern 7: Envelope-encrypted vault for CLI credentials

**What:** Subscription logins are really state in a CLI's config dir (auth.json, a keychain, or a browser-based OAuth device flow), not a plain API key. Two workable models:
- **A. Opaque secret blob:** the admin pastes a token/auth file in settings. The app encrypts it (AES-256-GCM, per-record nonce, master key from a Docker secret, not in the DB or repo) and the worker materialises it into a per-agent ephemeral `HOME` before each run, then scrubs it.
- **B. Login on the server:** the CLI is logged in once (device-code flow) and the auth home lives on a worker-only volume. The app only stores status and metadata.
Model A matches the "configured in settings" requirement. Refreshed tokens written back by the CLI must be re-encrypted and saved, otherwise the stored token goes stale, so the adapter must read the auth home back after each run (MEDIUM: an inference from how these CLIs behave, needs a spike).
**Trade-offs:** The master key is the weak point, so back it up separately from the DB backup. Never log the secret or put it in argv (it would be visible via `ps`). Pass it by env or a file in a tmpfs.

### Pattern 8: Offline-light PWA (online-first for sensitive data)

**What:** The service worker caches the app shell, i18n bundles and static assets so it installs and opens quickly. Data is online-first. Optionally cache the to-do list read-only or queue to-do edits (background sync is not available on iOS Safari, so do not depend on it).
**Rules:** Do not cache medical or finance API responses or documents in the service worker or IndexedDB by default (shared tablets, lost phones). Clear caches on logout. Version the cache and ship an update prompt.
**Trade-offs:** Full offline-first sync (CRDTs, conflict resolution) is overkill for a family app and a major complexity source. Web Push on iOS works only for an installed PWA and needs a user gesture, so treat push as optional and use email as the reliable channel (MEDIUM).

## Data Flow

### Request Flow

```
User action
   ↓
UI → API route → authn (session) → authz.require(module, level, row)
                                         ↓ deny → 403 + audit
                                  module service → repo(principal) → Postgres
   ↑                                      ↓
JSON response (locale-aware) ← DTO mapper (strips hidden fields)
```

### AI extraction flow

```
Upload doc (medical:edit) → file store (hash, private volume) → medical_document row
   ↓ user clicks "Read with AI" (explicit consent per doc, shows which agent/vendor)
ai_job(queued) ───────────────┐
                              ▼
   Worker claims job (SKIP LOCKED, lease)
     → router picks agent (skip cooling_down / disabled)
     → vault decrypts credentials into ephemeral HOME (tmpfs)
     → adapter spawns CLI (argv array, timeout, temp dir with the file, scrubbed env)
     → classify result
         ok      → extract JSON → validate schema → ai_extraction(proposed) → notify user
         quota   → agent cooling_down; re-route or job waiting_quota(run_after)
         auth    → agent disabled + admin alert; re-route
         bad out → repair retry → fallback → failed
     → wipe temp dir, record ai_attempt
   ↓
User reviews proposal beside the document → apply / edit / reject → medical record fields
```

### State Management

Server state is authoritative. The client uses a query cache (fetch/mutate/invalidate) per module. UI state is local. Job progress is polled (every few seconds while a job is visible) or pushed via SSE. Polling is enough here.

### Key Data Flows

1. **Invite and grant:** admin invites by email → token → the member signs up with a password or Google → default grants are none for health/finance → admin edits the grants matrix.
2. **Wishlist metadata:** the user pastes a URL → the server fetches og:image/title/price in a job (SSRF-guarded: block private/loopback IPs and redirects to them, size and time limits) → the image is downloaded and stored locally, not hotlinked.
3. **Recurring items:** the scheduler materialises recurring tasks and expected recurring expenses from rules, idempotently (unique key on rule+period).
4. **Export:** the medical module queries with the requester's principal → category filter → render PDF/Markdown/JSON. The AI-friendly export is a plain structured file the user chooses to hand over, with no automatic outbound send.
5. **Backup:** DB dump plus files volume plus (separately) the vault master key, on the existing 03:30-style cron, encrypted offsite if possible.

## Scaling Considerations

| Scale | Architecture Adjustments |
|-------|--------------------------|
| Family (5-30 users) | Single VPS, one Postgres, one app container, one worker. This is the target. |
| Heavy bulk OCR | Jobs run serially per agent, so the bottleneck is the vendor quota and CLI latency. Throttle batches and widen the agent list. |
| Beyond (not planned) | Split the worker onto another host, move the files to object storage. Do not design for it. |

### Scaling Priorities

1. **First bottleneck:** AI subscription quota and CLI latency. Fix with throttled batches, fallback agents, and the `waiting_quota` state.
2. **Second bottleneck:** the worker's memory and CPU from headless CLIs (Node-based CLIs are heavy). Fix with concurrency 1 and container resource limits.

## Anti-Patterns

### Anti-Pattern 1: Authorization in the UI or in scattered handlers

**What people do:** hide menu items and sprinkle `if (isAdmin)` checks.
**Why it's wrong:** one forgotten endpoint leaks health or finance data, as do files, exports and search.
**Do this instead:** deny-by-default central policy, principal-scoped repositories, and tests that iterate over every route with an unprivileged member.

### Anti-Pattern 2: Running CLIs inline in the web request

**What people do:** `exec()` the CLI from the request handler (the PHP-exec idea).
**Why it's wrong:** requests time out, there is no retry or quota state, a web-tier compromise gets the vendor credentials and a shell, and a restart loses the work.
**Do this instead:** enqueue, with a separate worker container as the only place where CLIs and credentials exist. The language runtime does not matter for this. PHP is not required.

### Anti-Pattern 3: Shell-string spawning with user-influenced content

**What people do:** build `sh -c "cli ... {filename}"`.
**Why it's wrong:** command injection through file names or prompt text, and prompt injection from the document content.
**Do this instead:** an argv array, generated file names (uuid), no tools or file-write permissions granted to the agent where the CLI allows it, a read-only mount, and a temp working dir. Treat the output as untrusted: schema-validate, and never execute or render it as HTML. The review step is the safeguard against prompt injection in uploaded documents.

### Anti-Pattern 4: Storing agent credentials in plaintext, or in the DB without a separate key

**What people do:** put tokens in `.env` or a settings table.
**Why it's wrong:** a DB dump or backup leaks the subscription accounts.
**Do this instead:** envelope encryption with the key outside the DB (Docker secret), write-only UI, redaction in the logs.

### Anti-Pattern 5: Treating AI output as data

**What people do:** save the model's JSON straight into the record.
**Why it's wrong:** hallucinated dosages and dates in a medical record.
**Do this instead:** schema validation, then a `proposed` state, then human confirmation, with the provenance (agent, model, prompt version) stored.

### Anti-Pattern 6: Full offline-first sync and public file URLs

**What people do:** build a CRDT sync layer, or serve uploads from a static directory or a guessable URL.
**Why it's wrong:** big complexity and leaked documents.
**Do this instead:** online-first with an app-shell cache, and authorized file streaming only.

## Integration Points

### External Services

| Service | Integration Pattern | Notes |
|---------|---------------------|-------|
| Vendor AI CLIs (Codex, Claude, Antigravity, Copilot) | Subprocess in the worker, per-agent auth home | LOW confidence on terms. The subscription's terms may forbid or restrict automated or headless use (Claude subscription terms are flagged in PROJECT.md, and Antigravity and Copilot may have no CLI). Run a verification spike BEFORE building adapters. The architecture holds for any subset, even one agent. |
| Google OAuth | OIDC authorization-code flow in the app | Add the staging and prod redirect URIs. Link by verified email only, to prevent account takeover through unverified emails. |
| Stalwart mail (existing `/opt/mail`) | SMTP for invites and reminders | An existing capability, so use a dedicated sender account. |
| Cloudflare / edge proxy | Existing | New stack needs an `edge` network alias plus a line in `map $ssl_preread_server_name`. PROXY protocol is in use, so the stack nginx must accept it for the real client IP. Cloudflare Access could add an extra layer for the admin or settings area (optional). |
| Wishlist target sites | HTTP fetch of meta tags | SSRF guard, timeouts, a size cap, local image copy. |

### Internal Boundaries

| Boundary | Communication | Notes |
|----------|---------------|-------|
| module ↔ module | Service interface or domain event (outbox) | No cross-module SQL joins. For example, reminders consume wishlist occasions via an interface. |
| app ↔ worker | DB tables only (`ai_job`, outbox) | No HTTP between them. The worker must not receive web sessions. |
| app/worker ↔ files | Shared volume, mounted read-write in the app and read-only in the worker | The worker copies a file into its temp dir for the CLI. |
| worker ↔ vault | Library call with the master key as a Docker secret | The web container may also hold the key (to write credentials), but only the worker needs to decrypt. A split with public-key encryption (the app encrypts, the worker decrypts) is a possible hardening. |
| staging ↔ prod | Fully separate stacks, DBs, volumes, vault keys, and separate Google OAuth clients | Staging must not use real medical data. Test with synthetic documents, and preferably give staging its own agent login or none. |

## Suggested Build Order (dependencies)

```
1 Foundation: stack skeleton, Compose + edge wiring, CI to staging/prod, DB+migrations,
              i18n scaffolding, PWA shell
        ↓
2 Identity + Authorization core: accounts, Google, invites, members, grants matrix,
              policy engine, audit log, route-level authz test harness
        ↓
3 To-do module (first module; proves authz, recurring + scheduler, i18n, PWA)
        ↓
4 Shared services as needed: files store, notification/outbox (email), scheduler
        ↓
5 Wishlist (reservation hiding = first row-level policy test; metadata fetch job; occasions/reminders)
        ↓
6 Finance (CSV import, recurring expenses, budgets, charts; finance grants default-deny)
        ↓
7 Medical records (manual): visits, meds, allergies, vaccinations, documents, subject-scoped grants
        ↓
8 AI platform: [spike on vendor terms/CLI headless first] → job queue + worker container
              → vault → one adapter end-to-end → schema validation + review UI
        ↓
9 Multi-agent: more adapters, router, quota/fallback, agent settings UI, health checks
        ↓
10 Bulk re-generation, medical exports (category / all / AI-friendly)
```

**Ordering rationale:**
- The authorization core must exist before any data module, because it is hard to retrofit (anti-pattern 1). To-do goes first since it is low-sensitivity and exercises the whole stack.
- Files and notifications are pulled forward only when the first consumer needs them (wishlist images and reminders), and medical documents then reuse the same store.
- Manual medical records come before AI, so the AI feature only fills fields that already work, and manual entry remains the fallback.
- The queue, worker and vault do not depend on the product modules, so they could be built in parallel after phase 2. They are best kept after medical (7) because they need a consumer, but the vendor-terms spike should start early, since a negative answer reshapes phases 8-9.
- Bulk re-generation and the exports depend on stored `ai_extraction` history and the category taxonomy of the medical module.

## Sources

- Training-knowledge synthesis of established patterns (modular monolith, DB-backed queues with SKIP LOCKED, deny-by-default policy engines, envelope encryption, offline-light PWAs). Not re-verified against live docs in this pass (MEDIUM).
- `/home/voslar/projects/family-hub/.planning/PROJECT.md` (constraints, existing infrastructure)
- Existing infra notes in the owner's global instructions (edge nginx SNI + PROXY protocol, `/opt` Compose stacks, staging/prod via branches).
- To verify later (LOW so far): vendor CLI headless modes, structured-output flags, quota error messages, and subscription terms for automated use. iOS PWA push and background-sync limits.

---
*Architecture research for: self-hosted multi-module family app with AI-agent job system*
*Researched: 2026-11-11*
