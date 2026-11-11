# Project Research Summary

**Project:** Family Hub
**Domain:** Self-hosted multi-module family organization PWA (to-do, wishlist, finance, medical records, subscription-based AI OCR)
**Researched:** 2026-11-12
**Confidence:** MEDIUM

## Executive Summary

Family Hub is a modular-monolith PWA for a family of roughly 5-30 users on one VPS, in SK/PL/EN. Its core value is server-enforced privacy: per-module none/view/edit grants, per-subject medical access, per-account finance visibility, and gift reservations hidden from the recipient.

The recommended build is one TypeScript codebase on Node 24 with two entrypoints (web and worker): Next.js 16, Drizzle, Better Auth, next-intl, zod and pg-boss. Postgres is both datastore and job queue, so there is no Redis. A single deny-by-default authorization layer sits under all modules. PHP is rejected because supervising multi-minute CLI children with retries and quota cooldown needs a long-lived worker, not request-scoped exec.

AI OCR verdict under the "subscriptions only, no per-token billing" rule:

- **Codex CLI** (ChatGPT plan): INCLUDE, primary. Best documented headless path (`codex exec`, `--output-schema`, images via `-i`; PDFs rasterized with `pdftoppm`).
- **GitHub Copilot CLI**: INCLUDE, fallback. No schema enforcement, so zod validate-and-repair loop; set "Additional usage" budget to $0.
- **Claude Code CLI**: EXCLUDE from v1 on terms-of-service grounds (Anthropic forbids routing requests through plan credentials on behalf of users and storing credentials). Technically workable.
- **Antigravity CLI**: EXCLUDE from v1 (terms ban third-party tool access, headless image input undocumented, stdout-in-pipe bug).
- A provider-adapter interface keeps excluded providers addable later.
- CLI login is done once on the host/worker (`docker compose exec worker codex login --device-auth`). The app does NOT store subscription tokens. This **supersedes ARCHITECTURE.md's encrypted credential vault (Pattern 7) and its four-adapter plan**, and narrows the PROJECT.md wish for "tokens configured in app settings" (settings show provider/model selection and auth health only).

## Key Findings

### Recommended Stack

TypeScript on Node 24 LTS (TS pinned to 5.9 until ecosystem compatibility with 7.x is verified), Next.js 16.4 / React 19.3 / Tailwind 4 / shadcn/ui, PostgreSQL 17 with Drizzle ORM, pg-boss 12 for jobs and cron, Better Auth 1.7 (email+password and Google), next-intl 4.15, zod 4.6, execa 10 for subprocesses. Docker Compose with `web`, `worker`, `postgres` and an nginx joined to the existing `edge` network.

**Core technologies:**
- Next.js + React: web app and PWA shell — one codebase, SSR, good i18n support
- PostgreSQL + Drizzle: data and relations — also queue backend via pg-boss (`SKIP LOCKED`)
- pg-boss: background jobs, recurring schedules — avoids Redis
- Better Auth: accounts, Google sign-in, sessions — permissions kept custom (`module_permissions` + `requireAccess()`), CASL not used
- next-intl + ICU: SK/PL/EN with correct plurals
- execa + Codex/Copilot CLIs in a locked-down worker container: AI OCR from subscriptions
- Serwist (fallback: minimal hand-written service worker), web-push, nodemailer via existing Stalwart SMTP, rrule, Recharts, papaparse, cheerio, sharp, file-type, poppler

### Expected Features

**Must have (table stakes):**
- Invite-only accounts, Google sign-in, per-module per-member permissions with presets (Parent, Teen, Grandparent, Guest)
- To-do: assignment, due dates, recurrence, personal and shared tasks, reminders
- Wishlist: multiple URLs, price, notes, og:image auto-fetch with manual fallback, reservation hidden from recipient, occasions and name days
- Finance: manual ledger, categories, budgets, charts, CSV bank import (CP1250, decimal comma, per-bank profiles, dedup), recurring-expense tracking
- Medical: visits, meds/allergies, vaccinations, documents/photos, specialty taxonomy, per-subject access, export by specialty and AI-friendly export
- SK/PL/EN UI, PWA on phone/desktop/iPad

**Should have (competitive):**
- Recurring-expense expected-vs-actual matching and upcoming-bills view
- AI OCR into structured proposals with human review, bulk re-generation, multi-agent routing with quota fallback
- Per-account finance visibility, per-subject medical grants

**Defer (v2+):**
- Emergency card, co-buying, rotating chores, TOTP/passkeys, multi-currency reporting
- Anti-features (do not build): per-token AI APIs, silent auto-trust of AI output, live bank connections (PSD2), envelope budgeting, public share links, chat/social feed, diagnosis engines, native apps

### Architecture Approach

Modular monolith plus a separate worker container from the same codebase; app and worker communicate only through DB tables. One deny-by-default policy engine with (member, module) grants and row rules (reservation hiding, medical subject scoping, files/exports inheriting parent policy); repositories take the principal so forgotten checks fail closed. Postgres job table with per-agent concurrency of 1; adapters map vendor failures to a shared taxonomy (quota, auth expired, transient, bad output, timeout), with a router doing cooldowns, fallback or `waiting_for_quota`. AI output is validated against versioned JSON Schema and stored as `proposed` with provenance until a human applies it. PWA caches shell and i18n only; no medical/finance data in the service worker.

**Major components:**
1. Web app (Next.js) — UI and API behind the policy layer
2. Policy/authorization core — grants, subjects, audit log
3. Postgres — data, jobs, schedules
4. Worker — AI jobs, link-preview fetch, notifications, recurrence materialization
5. Agent adapters and router — Codex, Copilot (others later)
6. Upload pipeline and notification outbox — shared services

### Critical Pitfalls

1. **Permission leaks** — one policy layer, subject-aware data model decided early, role x endpoint test matrix in CI
2. **Reservation secrecy leaks** — separate reservation table and recipient-scoped serializers; no counts/badges/notifications for the recipient
3. **Medical data sent to third-party AI** — per-document consent, per-subject AI consent flag (default off), AI call log, output always a reviewable draft
4. **Prompt injection / runaway CLI processes** — separate low-privilege worker, read-only sandbox, no network/MCP tools, timeouts, closed stdin; never set `*_API_KEY` in the worker (CLIs would bill per token)
5. **Retrofit-costly basics** — ICU plurals for SK/PL, RRULE recurrence with DST and month-end, integer money with currency, SSRF-hardened fetching, iOS PWA limits

## Implications for Roadmap

Suggested phase structure (10 phases; user chose fine granularity):

### Phase 1: Foundation and Infrastructure
**Rationale:** Cross-cutting basics are costly to retrofit.
**Delivers:** Compose and edge wiring, CI, DB migrations, next-intl with CI lint, PWA shell, encrypted backups with tested restore, synthetic staging data, Money/Clock helpers.
**Avoids:** i18n, money, backup/privacy pitfalls.

### Phase 2: Identity and Authorization Core
**Rationale:** Must precede every data module.
**Delivers:** Invite-only registration, Google linked by verified `sub`, grants matrix with presets, policy engine, audit log, session revocation, authz test harness; subject/account model decided here.
**Avoids:** Permission leaks.

### Phase 3: To-Do
**Rationale:** Owner's first module; builds the recurrence engine reused later.
**Delivers:** Tasks, assignment, due dates, recurrence (RRULE, schedule vs after-completion, rolling window), reminders (email baseline).
**Avoids:** Recurrence/DST pitfalls.

### Phase 4: Shared Services
**Rationale:** Needed by wishlist, finance and medical (can fold into Phase 5).
**Delivers:** Private upload pipeline, notification outbox, SSRF-hardened fetcher.

### Phase 5: Wishlist
**Delivers:** Wishes with multiple URLs, og:image fetch job, reservation hiding, occasions and SK/PL name days, giver-only reminders.
**Avoids:** Reservation leaks, SSRF.

### Phase 6: Finance
**Delivers:** Ledger with integer money, per-account visibility, budgets and charts, CSV import with per-bank profiles and dedup/undo, recurring expense templates with expected-vs-actual matching.

### Phase 7: Medical Records (manual)
**Delivers:** Person profiles (subject is not a user), specialty taxonomy, per-subject grants, documents, exports by specialty and AI-friendly, per-subject AI consent flag. Fully usable without AI.

### Phase 8: AI Platform
**Rationale:** Riskiest dependency; start with a hands-on spike on real CLIs.
**Delivers:** pg-boss queue, locked-down worker, Codex adapter end to end, zod validation, proposed/applied/rejected extraction states, side-by-side review UI, AI call log, auth probe.

### Phase 9: Multi-Agent, Quota and Bulk Re-generation
**Delivers:** Copilot adapter, router and error taxonomy, `waiting_for_quota`, bulk batch with dry-run and daily cap that never overwrites applied data, golden-set regression.

### Phase 10: Hardening and v1.x extras
**Delivers:** Audit-log UI, emergency card, optional TOTP/passkeys, re-auth for health/finance after idle.

### Research Flags
- **Phase 8:** spike for exit codes, limit text, token lifetime, plan data-use settings
- **Phase 9:** Copilot terms and quota fixtures
- **Phase 6:** collect real SK/PL bank CSV samples first
- **Phases 1 and 3:** iOS PWA push/OAuth/invite flow on physical devices; Serwist vs Turbopack
- **Phase 7:** GDPR household exemption and children's data
- **Phase 5:** name-day dataset source and licence

## Confidence Assessment

| Area | Confidence | Notes |
|------|------------|-------|
| Stack | MEDIUM | Versions and vendor docs checked, framework fit not verified against Context7 |
| Features | MEDIUM | No live competitor check |
| Architecture | MEDIUM | Patterns standard, not verified against live docs |
| Pitfalls | MEDIUM | Security/money/dates solid; AI-CLI terms and quota behaviour rest on secondary sources (LOW-MEDIUM) |

**Overall confidence:** MEDIUM

### Gaps to Address
- CLI exit codes, limit text and auth-failure behaviour are undocumented; `codex exec` can exit 0 with no response, so only valid non-empty zod-valid output counts as success
- Codex refresh-token lifetime and `auth.json` refresh race unknown
- ChatGPT/Codex shared allowance and Copilot AUP "bulk automation" wording
- Per-plan training and retention settings for consumer plans
- TS 7, Better Auth, zod 4 helpers, Serwist compatibility
- Real bank CSV samples and name-day datasets needed from the owner
- Stalwart SPF/DKIM/DMARC deliverability for reminder emails
- Multi-currency deferred: per-account currency and per-currency reports only

---
*Research completed: 2026-11-12*
*Ready for roadmap: yes*
