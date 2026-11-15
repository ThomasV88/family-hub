---
phase: 02-identity-and-authorization-core
plan: 05
subsystem: auth
tags: [laravel, audit, postgres, append-only, inertia, vue]
requires: [02-04]
provides:
  - audit_logs table (no FKs), REVOKE UPDATE/DELETE/TRUNCATE from fh_app, BEFORE UPDATE trigger
  - App\Audit\AuditEvent (+ group()), AuditLogger::record with per-event meta allowlist, AuditEntry model guard
  - RequireAccess audits health/finance reads, writes and denials before the controller (fail closed)
  - RecordAuthEvents listener (Login, Failed, Lockout, PasswordReset), session auth_remember flag
  - audit:prune (owner role only) with monthly cron lines
  - GET /settings/audit (admin only) with AuditQuery filters and Settings/Audit page
affects: [02-06, 02-07, 02-08, 02-09]
tech-stack:
  added: []
  patterns: [append-only table by privilege plus trigger, meta allowlist per event, audit write in access middleware without catch]
key-files:
  created:
    - database/migrations/2026_11_13_120000_create_audit_logs_table.php
    - app/Audit/{AuditEvent,AuditLogger,AuditQuery}.php
    - app/Models/AuditEntry.php
    - app/Listeners/RecordAuthEvents.php
    - app/Console/Commands/AuditPrune.php
    - app/Http/Controllers/Settings/AuditController.php
    - routes/web/settings-audit.php
    - resources/js/Pages/Settings/Audit.vue
    - resources/js/Components/Settings/AuditTable.vue
    - tests/Support/EndpointMatrix/settings-audit.php
    - tests/Feature/Audit/{AuditLog,AuditAppendOnly,AuditTrigger,AuditMiddleware,AuthEventsAudit,AuditPrune,AuditPage}Test.php
    - tests/js/settings/AuditPage.test.js
  modified:
    - app/Http/Middleware/RequireAccess.php
    - app/Console/Commands/CreateAdmin.php
    - ops/cron/family-hub.cron.example
    - tests/Feature/Auth/CreateAdminTest.php
decisions:
  - "Failed listener resolves the actor by lowercased typed email and stores only the user id, because Fortify's custom authenticator fires Failed with no user"
  - "Reversed date range is ignored and reported as error audit.error.range (danger alert); other invalid filters are silently dropped"
  - "audit:prune role check goes through an overridable currentRole() so the refusal is testable as fh_owner"
metrics:
  tasks: 2
  completed: 2026-11-13
---

# Phase 2 Plan 05: Append-only audit log and admin page Summary

Health and finance access (allowed and denied) is written to an append-only, content-free audit log by the access middleware, account events are recorded by listeners, and the admin reads and filters it on a read-only Settings page.

## Tasks

| Task | Commit | Notes |
|------|--------|-------|
| 1 Table, logger, middleware audit, listeners, prune | 53195aa | Pest 161 green |
| 2 Audit page, filters, pagination | 141ba4b | Pest 171, Vitest 339, build SW-PRECACHE OK |

## Verification

- Pest 171 passed; Vitest 339 passed; `npm run build` prints SW-PRECACHE OK; Pint clean on all files of this plan (only pre-existing `routes/console.php` flags); compose project `fh0205` removed with `down -v`.
- Privilege tests were not skipped: role `fh_app` exists in the test DB, `has_table_privilege` is false for UPDATE, DELETE, TRUNCATE and true for INSERT, SELECT.
- `php artisan event:list` lists RecordAuthEvents@handleLogin/handleFailed/handleLockout/handlePasswordReset (auto-discovered).
- Acceptance greps: REVOKE line 1, "foreign" 0, audit:prune cron lines 2, no catch around the audit call in RequireAccess, no raw SQL in AuditQuery, no delete/export/checkbox in the Vue files. Table uses UiTable with `caption` prop (visually hidden), `scope="col"` headers.
- Human check (fail a sign-in, filter, no edit/delete controls) not performed here; left for end of phase.

## Deviations from Plan

**1. [Rule 1 - Bug] Failed event carries no user**
- Fortify's `authenticateUsing` returns null on failure, so `Failed->user` is null even for a known email. The listener now looks up the user from the typed email (id only stored). Commit 53195aa.

**2. [Rule 1 - Bug] Migration not re-runnable with migrate:fresh**
- Functions survive `DROP TABLE`; used `CREATE OR REPLACE FUNCTION`. Commit 53195aa.

**3. [Minor] Beyond plan**
- Added `target_label` column (contract mentions a target name snapshot); `AuditEvent::GROUPS`, `forModule`, `metaKeys`, `eventsForGroup` helpers; `AuditPrune::currentRole()` seam; Audit empty state uses heading level 2 and the range error uses a danger alert (role alert) for axe and live announcement.

## Auth gates

None.

## Known Stubs

None. Contract events not yet emitted by any code (invite_*, permission_changed, session_revoked*, google_linked) are written by plans 06 to 09 via `AuditLogger::record`; the page already renders all of them.

## Threat Flags

None beyond the plan's threat model (T-02-23 to T-02-27, T-02-29 mitigated and tested; T-02-28 accepted).

## Requirements

AUTH-08 delivered in full is not yet true: its permission-change, invite and session-revoke events are emitted by plans 06 to 09, so AUTH-08 is not ticked. AUTH-07 was already complete (this plan adds fail-closed auditing to it).

## Self-Check: PASSED

All files exist; commits 53195aa and 141ba4b present.
