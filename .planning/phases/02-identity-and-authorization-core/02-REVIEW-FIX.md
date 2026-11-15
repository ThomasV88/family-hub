---
phase: 02-identity-and-authorization-core
fixed_at: 2026-11-13T00:00:00Z
review_path: .planning/phases/02-identity-and-authorization-core/02-REVIEW.md
iteration: 1
findings_in_scope: 11
fixed: 11
skipped: 0
status: all_fixed
---

# Phase 2: Code Review Fix Report

**Source review:** .planning/phases/02-identity-and-authorization-core/02-REVIEW.md
**Iteration:** 1

**Summary:**
- Findings in scope: 11 (WR-01..WR-09, plus IN-02 and IN-05 as requested)
- Fixed: 11
- Skipped: 0 (IN-01, IN-03, IN-04 out of scope; part of IN-05 not done, see below)

Verification at the end: Pest 274 passed, Vitest 397 passed, `npm run build` printed SW-PRECACHE OK.
The work was done directly on `main` (not in a separate worktree): the working tree was clean, and
`vendor/` and `node_modules/` are untracked, so a worktree would not have run the container tests.

## Fixed Issues

### WR-01: "Stays signed in" flag is always false
**Files modified:** `app/Auth/SessionList.php`, `tests/Feature/Sessions/SessionListTest.php`
**Commit:** a2a1ea3
**Applied fix:** Payload is decoded with `json_decode` (matches `serialization => json`). The test helper now writes real JSON payloads.

### WR-02: Audit row written after the audited action commits
**Files modified:** `app/Http/Controllers/Settings/SessionsController.php`, `app/Auth/GoogleLoginService.php`, `app/Actions/Fortify/ResetUserPassword.php`, `app/Listeners/RecordAuthEvents.php`, `tests/Feature/Audit/AuthEventsAuditTest.php`, `tests/Feature/Sessions/SessionRevokeTest.php`
**Commit:** f7c9370
**Applied fix:** Action and audit row now share one `DB::transaction` (session revoke, Google link, password reset). The `password_reset` audit moved from the `PasswordReset` event listener into the reset action so it is atomic. New test proves a failing audit rolls the revoke back.

### WR-03: Invite page redirect loop
**Files modified:** `app/Http/Controllers/Auth/InviteAcceptController.php`, `app/Http/Middleware/HandleInertiaRequests.php`, `tests/Feature/Invites/InviteAcceptTest.php`
**Commit:** db6f599
**Applied fix:** No redirect: the page renders in the invitation locale (`setLocale`) and queues the cookie on the same response. Shared Inertia `locale`/`messages` props are now lazy so a controller can still switch locale.

### WR-04: Third party can lock a member out of password login
**Files modified:** `app/Providers/AppServiceProvider.php`, `tests/Feature/Auth/LoginTest.php`
**Commit:** 83050db
**Applied fix:** Strict limits (5/min and 10/15 min) are keyed on email+IP. The cross-IP per-email limit is now 50/hour; hitting it fires `Lockout`, which is audited. Tests cover: other IPs cannot lock the owner, the email+IP limit, and the ceiling plus audit row. Requires human verification (policy tradeoff).

### WR-05: Google sign-in auto-links without a signal to the owner
**Files modified:** `app/Auth/GoogleLoginService.php`, `app/Notifications/GoogleLinkedNotification.php` (new), `lang/{en,sk,pl}/mail.php`, `tests/Feature/Auth/GoogleLoginTest.php`
**Commit:** f5e21f7
**Applied fix:** Linking by verified email is kept (locked decision) and was already audited. The owner now gets a queued, localized (SK/PL/EN) notification email when Google is linked. No mail on a plain sign-in by Google id.

### WR-06: Audit append-only weaker than documented
**Files modified:** `database/migrations/2026_11_13_120000_create_audit_logs_table.php`, `app/Console/Commands/AuditPrune.php`, `app/Models/AuditEntry.php`, `tests/Feature/Audit/AuditTriggerTest.php`
**Commit:** f00adf5
**Applied fix:** The migration throws in production/staging when `fh_app` is missing (skips only in local/testing). New statement-level BEFORE DELETE and BEFORE TRUNCATE triggers refuse removal unless the role is the table owner and the transaction opted in with `SET LOCAL audit.prune = 'on'`, which only `audit:prune` does. This also holds if the app connects as the owner. The model docblock and migration comments now state honestly that a DB owner or superuser can still change the table and that model hooks do not cover bulk queries. Requires human verification: the migration file was edited in place (not yet applied live per DEFERRED.md); an already-migrated database needs the two triggers added by hand or by `migrate:fresh`.

### WR-07: Array-valued email input causes 500
**Files modified:** `app/Support/RequestInput.php` (new), `app/Http/Middleware/ThrottleAuthRoutes.php`, `app/Providers/AppServiceProvider.php`, `app/Providers/FortifyServiceProvider.php`, `app/Listeners/RecordAuthEvents.php`, `tests/Feature/Auth/LoginTest.php`
**Commit:** d9bb1a7
**Applied fix:** Scalar-safe `RequestInput` helper used in all four places. Fortify's own reset controller lowercases the email before validating, so the forgot/reset routes now answer 422 to non-string fields in `ThrottleAuthRoutes`.

### WR-08: Invitation creation race
**Files modified:** `app/Auth/InviteService.php`, `tests/Feature/Invites/InviteManagementTest.php`
**Commit:** dbd97b4
**Applied fix:** `pg_advisory_xact_lock(hashtext('invite:'.$email))` at the start of the create transaction. The test checks the lock is taken; a true two-connection race is not simulated.

### WR-09: staging:audit misses Phase 2 tables
**Files modified:** `app/Console/Commands/StagingAudit.php`, `tests/Feature/StagingSeedTest.php`
**Commit:** a28ac3d
**Applied fix:** Rules for `invitations.email` and for `audit_logs` (meta email, and actor/target ids that are not synthetic users).

### IN-02: Reset-password GET unthrottled
**Files modified:** `app/Http/Middleware/ThrottleAuthRoutes.php`, `tests/Feature/Auth/PasswordResetTest.php`
**Commit:** 0410379
**Applied fix:** Separate `reset-view-ip` limiter (20/min per IP), 429 beyond it.

### IN-05 (partial): explicit fillable
**Files modified:** `app/Models/Invitation.php`, `app/Models/AuditEntry.php`, `tests/Feature/Invites/InviteManagementTest.php`
**Commit:** e6ecaef
**Applied fix:** `$guarded = []` replaced by explicit `$fillable` lists. `SESSION_SECURE_COOKIE=true` was already set in `ops/env/app.env.example` (nothing to change). Not done: dropping `app.env` from shared Inertia props, because `AppShell.vue` uses it for the staging banner (would need a frontend change for a low-value gain).

## Not in scope (not attempted)

IN-01 (invite token in queue payload), IN-03 (forgot-password timing), IN-04 (audit flooding by failed logins).

---

_Fixer: Claude (gsd-code-fixer)_
_Iteration: 1_
