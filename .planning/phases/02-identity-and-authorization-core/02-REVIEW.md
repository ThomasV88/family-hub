---
phase: 02-identity-and-authorization-core
reviewed: 2026-11-13T00:00:00Z
depth: standard
files_reviewed: 60
files_reviewed_list:
  - app/Access/*.php
  - app/Actions/Fortify/*.php
  - app/Audit/*.php
  - app/Auth/*.php
  - app/Console/Commands/*.php
  - app/Http/Controllers/**/*.php
  - app/Http/Middleware/*.php
  - app/Http/Requests/**/*.php
  - app/Listeners/RecordAuthEvents.php
  - app/Models/*.php
  - app/Notifications/*.php
  - app/Providers/*.php
  - app/Support/*.php
  - routes/web.php
  - routes/web/*.php
  - bootstrap/app.php
  - config/fortify.php
  - config/auth.php
  - config/session.php
  - database/migrations/2026_11_13_*.php
findings:
  critical: 0
  warning: 9
  info: 5
  total: 14
status: issues_found
---

# Phase 2: Code Review Report

**Reviewed:** 2026-11-13
**Depth:** standard
**Files Reviewed:** about 60 (Vue pages not reviewed)
**Status:** issues_found

## Summary

The authorization core holds up. I found no authz bypass or IDOR.

- Every route carries an `access:` rule, and the fail-closed `EnsureRouteDeclaresAccess` backs that up.
- Admin routes resolve ids in the controller, so route-model-binding order is not an issue.
- Invite tokens are 40 random characters, stored only as a SHA-256 hash, and accepted under a row lock.
- Google linking requires a verified email.
- Session revoke rotates the remember token.
- Reset-link responses are identical for known and unknown emails.

There are no critical issues. There are 9 warnings, mostly integrity and robustness gaps:
- Audit writes are not atomic with the actions they record.
- One real functional bug (the "stays signed in" flag).
- A redirect loop on the invite page.
- A lockout DoS.
- Append-only enforcement that is weaker than the comments claim.

## Warnings

### WR-01: "Stays signed in" flag is always false (session serialization is json)

**File:** `app/Auth/SessionList.php:37-46` (with `config/session.php`, `'serialization' => 'json'`)
**Issue:** `staysSignedIn()` does `unserialize(base64_decode($payload))`. With `serialization => 'json'`, `Store::serialize` writes JSON, so `unserialize` fails and the `@` hides it. The function returns false for every session, and the admin never sees which sessions are remembered. `SessionListTest` only passes because its helper writes PHP-serialized payloads.
**Fix:**
```php
$raw = base64_decode($payload, true);
if ($raw === false) return false;
$data = json_decode($raw, true);
return is_array($data) && ($data['auth_remember'] ?? false) === true;
```
Make the test helper write the real serialization (`json_encode`).

### WR-02: Audit row is written after the audited action commits (not fail-closed)

**File:** `app/Http/Controllers/Settings/SessionsController.php:33-34, 43-44`; `app/Auth/GoogleLoginService.php:72-73`; `app/Actions/Fortify/ResetUserPassword.php:34-39` (audit comes from the `PasswordReset` event)
**Issue:** Sessions are deleted, the remember token rotated, the Google id linked or the password changed, and only then is the audit row inserted. `PermissionService` and `InviteService` do this correctly inside one transaction. If the audit insert throws, the action is committed and unaudited, and the user gets a 500. For `GoogleLinked` the account is linked but the login never completes.
**Fix:** Wrap the action and `$audit->record(...)` in a single `DB::transaction`. For `GoogleLinked`, run `forceFill->save()` and `record()` in one transaction.

### WR-03: Invite page can redirect-loop when the locale cookie is not stored

**File:** `app/Http/Controllers/Auth/InviteAcceptController.php:25-30`
**Issue:** On a valid invite with no `locale` cookie, the page queues a cookie and redirects to the same URI. If the browser rejects the cookie (cookies disabled, or `SESSION_SECURE_COOKIE=true` over plain http), every request repeats the redirect. Each loop also consumes the `throttle:10,1` budget, so the invitee ends up with a 429.
**Fix:** Do not redirect. Render the page with the locale applied directly (`app()->setLocale($invitation->locale)`) and queue the cookie on that same response. Alternatively redirect only once, using a `?l=1` marker.

### WR-04: Third party can lock any member out of password login

**File:** `app/Providers/AppServiceProvider.php:65`
**Issue:** `Limit::perMinutes(15, 10)->by('email:'.$email)` is global per email across all IPs. Anyone can send 10 bad attempts for a known family address every 15 minutes and keep the real owner from logging in. Each throttled request also writes a Lockout audit row (see IN-04).
**Fix:** Either drop the per-email-only limiter, or raise it substantially and rely on the `email|ip` limiter plus the per-IP limiter. Alternatively, on the global-email limit, add a step-up (a delay) rather than a hard block.

### WR-05: Google sign-in auto-links to an existing password account without re-authentication

**File:** `app/Auth/GoogleLoginService.php:64-75`
**Issue:** An existing user with `google_id = null` is linked and logged in on any matching Google-verified email. The link does not require the existing password or an invite. The risk is limited because Google must assert `email_verified`. It still means a Google account that controls that address, such as a recycled Workspace or custom-domain address, can take over the family account with no signal to the owner.
**Fix:** Require an authenticated session for linking (link from a Settings page after re-entering the password). Alternatively, only auto-link when the user has never logged in with a password, and send the owner a notification email on link.

### WR-06: Audit append-only guarantee is weaker than documented

**File:** `database/migrations/2026_11_13_120000_create_audit_logs_table.php:32-50`; `app/Models/AuditEntry.php:21-25`
**Issue:**
- The trigger blocks only UPDATE. DELETE and TRUNCATE rely on the `REVOKE`, which runs only `IF EXISTS (fh_app)` at migration time. If the role is created later, or the app connects as the table owner, nothing prevents deletes.
- Model-level `updating`/`deleting` hooks are bypassed by `AuditEntry::query()->delete()` or `update()` (bulk query-builder calls).
- `AuditPrune` refuses to run only as `fh_app` and runs a raw `DELETE` as any other role.

**Fix:**
- Add a `BEFORE DELETE OR TRUNCATE` trigger that allows deletes only when `current_user` is the owner role, or document and assert in a boot check that the app role is not the owner.
- Fail the migration, rather than skipping, when the `fh_app` role is missing in production.

### WR-07: Array-valued email input crashes login and reset throttles with a 500

**File:** `app/Providers/AppServiceProvider.php:61`; `app/Http/Middleware/ThrottleAuthRoutes.php:31`; `app/Providers/FortifyServiceProvider.php:86`; `app/Listeners/RecordAuthEvents.php:39`
**Issue:** `(string) $request->input('email')` with `email[]=x` raises "Array to string conversion", which Laravel turns into an ErrorException, so the response is a 500. It is unauthenticated noise rather than an exploit.
**Fix:** Coerce with `is_string($v) ? $v : ''` in a small helper, and use it in all four places.

### WR-08: Invitation creation is not race-safe at the database edge

**File:** `app/Auth/InviteService.php:35-52`
**Issue:** `lockForUpdate()` on a query that matches zero rows locks nothing. Two concurrent creates for the same email both pass, and the second hits the partial unique index `invitations_one_open`, which surfaces as an unhandled QueryException (500).
**Fix:** Catch `UniqueConstraintViolationException` and retry the transaction once, or take `pg_advisory_xact_lock(hashtext(lower($email)))` at the top of the transaction.

### WR-09: staging:audit does not cover Phase 2 tables that hold real emails

**File:** `app/Console/Commands/StagingAudit.php:20-24`
**Issue:** Only `users` is checked. `invitations.email` and `audit_logs` (`meta.email`, `actor_label`, `target_label`) can hold real addresses and names on staging, and the audit would still pass. This defeats the stated purpose of "fail if non-synthetic data".
**Fix:** Add rules `['invitations', "email not like '%@example.test'"]` and a rule on `audit_logs` for the labels. Alternatively fail when `audit_logs` or `invitations` rows exist that were not created by the seeder.

## Info

### IN-01: Invite token sits in plaintext in the queue payload and Inertia history

**File:** `app/Notifications/InviteNotification.php:12-22`; `app/Http/Controllers/Settings/InvitationsController.php:56-63`
**Issue:** The token is serialized into `jobs` and, on failure, `failed_jobs`, where it persists indefinitely. The code comment calls this an accepted risk (T-02-36). The handoff link is also placed in the Inertia page props, so it goes into the admin's history state.
**Fix:** Prune `failed_jobs` aggressively (`queue:prune-failed --hours=24`), or encrypt the token (`ShouldBeEncrypted`).

### IN-02: Reset-password GET is unthrottled and acts as a token-validity oracle

**File:** `app/Http/Middleware/ThrottleAuthRoutes.php:21-26`; `app/Providers/FortifyServiceProvider.php:68-77`
**Issue:** The `password.reset` GET returns early with no limiter and discloses `valid` for a (token, email) pair. The tokens are 256-bit, so this is not exploitable, but it should still be throttled for consistency.
**Fix:** Apply the `reset-ip` limiter to GET as well.

### IN-03: Residual timing difference on forgot-password

**File:** `app/Providers/FortifyServiceProvider.php:39-48`
**Issue:** A known email generates a token and inserts a row plus a queue job. An unknown one returns immediately. Queuing removes SMTP latency but not this difference.
**Fix:** Optional. Dispatch the whole `sendResetLink` as a job, or add a short random delay.

### IN-04: Unauthenticated audit-log flooding

**File:** `app/Listeners/RecordAuthEvents.php:28-40`
**Issue:** Each failed login and each Lockout inserts a row. The per-IP limit is 30/min, so one IP can write about 43k rows a day, and many IPs can write more. The rows push out real events and the 2-year prune window.
**Fix:** Do not log repeated Lockouts per key, or collapse them (log once per window).

### IN-05: Smaller points

**File:** `config/session.php` (`secure` defaults to null), `app/Http/Middleware/HandleInertiaRequests.php:66`, `app/Models/Invitation.php:10`, `app/Models/AuditEntry.php:14`
**Issue:**
- `SESSION_SECURE_COOKIE` is unset by default, so session and locale cookies are not marked `Secure` unless production env sets it.
- `app.env` is exposed to all users.
- `Invitation` and `AuditEntry` use `$guarded = []`. This is safe today because only internal code writes them, but it is fragile.

**Fix:** Set `SESSION_SECURE_COOKIE=true` in the production and staging env templates. Drop `app.env` from shared props outside local. Use explicit `$fillable`.

---

_Reviewed: 2026-11-13_
_Reviewer: Claude (gsd-code-reviewer)_
_Depth: standard_
