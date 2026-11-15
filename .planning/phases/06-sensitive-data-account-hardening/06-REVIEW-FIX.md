---
phase: 06-sensitive-data-account-hardening
fixed_at: 2026-11-14T00:00:00Z
review_path: .planning/phases/06-sensitive-data-account-hardening/06-REVIEW.md
iteration: 1
findings_in_scope: 13
fixed: 13
skipped: 0
status: all_fixed
---

# Phase 6: Code Review Fix Report

**Fixed at:** 2026-11-14
**Source review:** .planning/phases/06-sensitive-data-account-hardening/06-REVIEW.md
**Iteration:** 1

**Summary:**
- Findings in scope: 13 (CR-01..CR-03, WR-01..WR-09, plus IN-02 as requested)
- Fixed: 13 (WR-01 shares the CR-03 commit)
- Skipped: 0
- Other Info (IN-01, IN-03, IN-04, IN-05) left alone as instructed.

Verification at the end: full Pest 1614 passed (in the container), full Vitest 2199 passed, `npm run build` with `SW-PRECACHE OK`.
The security proofs (SensitiveGateMatrixTest, stale-session proofs, audit canary, arch tests) stayed green and were only extended.
Work was done directly on `main` rather than in a separate worktree, because the Docker test stack mounts the checkout. Each finding is one commit and only its own files were staged. `.planning/config.json` was left untouched.

## Fixed Issues

### CR-01: Admin routes that grant access skipped the recent window

**Files modified:** `routes/web/settings-members.php`, `routes/web/invitations.php`, `routes/web/settings-finance-accounts.php`, `tests/Support/SensitiveRoutes.php`, `tests/Feature/Security/SensitiveGateMatrixTest.php`
**Commit:** 125ed8d
**Applied fix:** `access:recent` added after `access:admin` on the permissions update, all four invitation routes (create, resend, link, revoke) and finance account create/update. The finance-account index stays admin-only and is the only allowlisted entry. `SensitiveRoutes::accessGranting()` lists the routes by name. The matrix replays them with a stale session (302/303/423, nothing changed in permissions, invitations, accounts or memberships, no audit row), and a non-admin still gets 403. A safety net fails if a new route under `settings.members.permissions.`, `settings.invitations.` or `settings.finance_accounts.` is neither listed nor allowlisted, with a mutation check.
Wishlist manager updates were left alone as decided. There is no separate member role or preset route.
Status: fixed (logic: requires human verification of the route list).

### CR-02: Google re-auth fallback removed

**Files modified:** `app/Http/Controllers/Auth/GoogleController.php`, `app/Security/ReauthMethods.php`, `config/security.php`, `routes/web/google.php`, `.env.example`, `lang/{en,sk,pl}.json`, `resources/js/Pages/Auth/ConfirmAccess.vue`, `docs/ops/server.md`, `.planning/phases/06-sensitive-data-account-hardening/DEFERRED.md`, `.planning/phases/06-sensitive-data-account-hardening/06-UI-SPEC.md`, tests (GoogleReauthTest deleted, ReauthTest, SensitiveSessionTest, SecurityAuditContentTest, SecurityArchTest, endpoint matrix, JS tests)
**Commit:** c0eaedb
**Applied fix:** Removed the route, `reauthRedirect`, the callback re-auth branch and session flag, `ReauthMethods::googleAvailable` and the `google` key, config flag and `.env.example` entry, the matrix row, the UI button and failure alert, and the `reauth.google*` copy in all 3 locales. A signed-in member hitting the Google callback is sent to `/`. Google-only members without a factor use the single "Sign in again" action. A new arch test asserts no Google re-auth surface remains. docs/ops/server.md and DEFERRED.md document that Google-only sign-in without 2FA is effectively one click on a device already signed in to Google, with the recommendation to enrol a factor and turn on the requirement. UI-SPEC Amendment A1 is marked withdrawn.

### CR-03 and WR-01: Password alone opened the windows for members with a second factor

**Files modified:** `app/Security/ReauthMethods.php`, `app/Http/Controllers/Security/ReauthController.php`, `tests/Feature/Security/ReauthTest.php`, `tests/Feature/Security/SecurityAuditContentTest.php`, `tests/js/security/ReauthForm.test.js`
**Commit:** 8c2ca26
**Applied fix:** `ReauthMethods::passwordAllowed()` is true only for a member with `password_set` and no second factor. `for()` reports `password: false` otherwise, so `ReauthForm`, `ConfirmAccess` and every dialog show only the authenticator code and the passkey. `ReauthController` rejects a password-only confirm for such a member with the normal 422 and a `second_factor_failed` audit row, and opens no window. No new copy was needed. Tests cover these cases:
- TOTP member, password refused (422): the windows stay closed and the gate still answers 423.
- TOTP member, code works.
- Passkey member, password refused. The passkey confirm was already covered.
- Member with no factor: password works.
- Vitest: ReauthForm shows no password input for a factor member.
Status: fixed (logic: requires human verification).

### WR-02: QR and secret-key endpoints and pending-secret lifetime

**Files modified:** `app/Http/Middleware/EnsureRouteDeclaresAccess.php`, `app/Http/Middleware/GuardTwoFactorRoutes.php`, `resources/js/Components/Security/TwoFactorSetupDialog.vue`, `tests/Feature/Security/TwoFactorEndpointsTest.php`, `tests/Support/EndpointMatrix/two-factor.php`
**Commit:** 6325ff5
**Applied fix:** `two-factor.qr-code` and `two-factor.secret-key` now need `recent`. A pending secret lives 15 minutes (`GuardTwoFactorRoutes::PENDING_TTL_MINUTES`, measured from the row's `updated_at`, which Fortify writes on enable) and is wiped lazily on read. Confirming an expired or absent pending secret is a 404 (the matrix row for `two-factor.confirm` was updated to match). The setup dialog asks to confirm again if the window lapses while loading the seed. No scheduler prune was added, because there is no existing security prune and the lazy wipe already makes an expired seed unreadable. Tests cover stale 423, the 14 and 16 minute boundaries, and an expired confirm.
Status: fixed (logic: requires human verification).

### WR-03: Passkey kill switch locked out passkey members

**Files modified:** `app/Http/Middleware/EnsurePasskeysEnabled.php`, `app/Http/Controllers/Security/ChallengePasskeyController.php`, `app/Providers/FortifyServiceProvider.php`, `app/Security/ReauthMethods.php`, `routes/web/security-passkeys.php`, `docs/ops/server.md`, tests
**Commit:** 607a9c5
**Applied fix:** With `SECURITY_PASSKEYS_ENABLED=false` only management (options, store, rename, remove) is a 404. The challenge and confirm-access passkey routes, the challenge page props and `ReauthMethods` no longer check the flag, and `hasSecondFactor()` is unchanged. Tests prove both directions: with the flag off, an existing passkey still signs in and confirms and the page offers it, enrolment is 404, and a member with no passkey gets no passkey endpoints. The old test that expected 404 on challenge and reauth was narrowed to the management routes.
Status: fixed (logic: requires human verification).

### WR-04: Audit write after the state change

**Files modified:** `app/Http/Controllers/Security/ReauthController.php`, `app/Http/Controllers/Security/ReauthPasskeyController.php`, `app/Http/Middleware/GuardTwoFactorRoutes.php`, `tests/Support/FailingAuditLogger.php` (new test double), tests
**Commit:** 22f07d1
**Applied fix:** Confirm (password, code and passkey) now records the audit row first and opens the windows afterwards. A failing audit write leaves the member unconfirmed. Disabling a factor runs Fortify's change and the audit row inside one `DB::transaction`, so a failing audit write rolls the disable back. Tests use a failing audit logger for both paths.
Status: fixed (logic: requires human verification).

### WR-05: Lockout audit amplification

**Files modified:** `app/Providers/SecurityServiceProvider.php`, `app/Http/Middleware/GuardTwoFactorRoutes.php`, `tests/Feature/Security/SecurityLimitersTest.php`
**Commit:** 4adb926
**Applied fix:** A lockout row is written at most once per limiter subject and lockout window, using `Cache::add('lockout-audited:…', ttl = Retry-After)`. This covers the `two-factor` and `reauth` limiters and the TOTP-confirm throttle. Tests: 12 hammering requests give one row, a new window gives a second row, and the challenge limiter is deduped too.
Status: fixed.

### WR-06: Shared TOTP replay key and racy recovery codes

**Files modified:** `app/Security/UserScopedTwoFactorProvider.php` (new), `app/Providers/SecurityServiceProvider.php`, `app/Models/User.php`, `tests/Feature/Security/SecondFactorModelTest.php`
**Commit:** f6030ca
**Applied fix:** A decorated Fortify provider keys the replay marker by a hash of the member's own secret plus the code, so members no longer collide on the same six digits. Same-member replay is still refused. `replaceRecoveryCode` now runs in a transaction, takes a `lockForUpdate` on the user row and re-reads the stored codes. If the code is already gone it throws an `HttpResponseException` carrying Fortify's normal failed-login response, so the loser of a race fails like any wrong code. Tests: a stale in-memory copy cannot reuse a consumed code, two sequential challenge uses (second fails), and the key scoping. A true parallel race is covered by the row lock, not by a test, because the suite is single-process.
Status: fixed (logic: requires human verification).

### WR-07: Session id not rotated on privilege elevation

**Files modified:** `app/Security/SensitiveSession.php`, `app/Http/Controllers/Security/ReauthController.php`, `app/Http/Controllers/Security/ReauthPasskeyController.php`, `tests/Feature/Security/ReauthTest.php`
**Commit:** b6b7d62
**Applied fix:** `SensitiveSession::confirmRotating()` regenerates the session id (keeping the data, so `url.intended` and the timestamps survive) and then confirms. Both interactive confirm controllers use it. The Google re-auth path the review also named no longer exists (CR-02). Tests check the id changes for password and passkey confirms and that the intended redirect and the timestamps survive.
Status: fixed.

### WR-08: Unclamped config knobs

**Files modified:** `app/Security/ClampedSeconds.php` (new), `config/security.php`, `docs/ops/server.md`, `tests/Unit/Security/ClampedSecondsTest.php` (new)
**Commit:** b6075cd
**Applied fix:** `SECURITY_IDLE_SECONDS` is clamped to 60..86400 and `SECURITY_CONFIRM_WINDOW_SECONDS` to 60..3600. A value that is not a plain positive integer (`15m`, `0`, empty, negative, `1e3`) falls back to the default (900 and 600). Tests cover the helper and the real config file with putenv. Boot-time failure in production was not added, since fallback and clamp already prevent a disabled gate.
Status: fixed.

### WR-09: Stale-session proof coverage

**Files modified:** `tests/Support/SensitiveRoutes.php`, `tests/Support/EndpointMatrix.php` (memoised), `tests/Feature/Security/SensitiveGateMatrixTest.php`
**Commits:** ef5eaa9, 88b3b98 (memoising the matrix so the heavier replays fit the suite's 256M test budget)
**Applied fix:** Every sensitive route is now replayed in these shapes, each with no audit row:
- Page (html), JSON, Inertia (`X-Inertia`), HEAD (GET routes) and `Purpose: prefetch`.
- Missing key and an expired timestamp (`idle_seconds + 1` old).

Further proofs:
- A prefetch never extends the window.
- The exact idle boundary on every sensitive GET (at `idle_seconds` passes, one second more refuses).
- Fresh sessions pass on every route including writes (the html shape).

`offenders()` now also finds routes by controller namespace (`App\Http\Controllers\Finance|Health|Medical`), with a mutation check and a "none outside the gate today" test. The Phase 7 reminder in `SensitiveRoutes` mentions it. The fresh-session replay covers only the html shape, because the write routes made the JSON and Inertia variants too heavy for the memory budget. Those shapes are proven on the stale side.
Status: fixed.

### IN-02: Passkey id resolved before access rules

**Files modified:** `app/Http/Controllers/Security/PasskeyController.php`, `routes/web/security-passkeys.php`, `tests/Support/EndpointMatrix/security-passkeys.php`, `tests/Feature/Security/PasskeyManagementTest.php`
**Commit:** aae21fd
**Applied fix:** The route parameter is now a plain id (`{passkeyId}`) resolved in the controller with an ownership-scoped query, so route model binding cannot answer differently for existing and missing ids. An anonymous caller now gets 401 for any id (JSON) or the same 302 (page). A signed-in member still gets 404 for a foreign or missing id. The matrix rows for `passkeys.update` and `passkeys.destroy` no longer expect a guest 404.
Status: fixed.

## Skipped Issues

None in scope. IN-01, IN-03, IN-04 and IN-05 were not in the requested scope.

---

_Fixed: 2026-11-14_
_Fixer: Claude (gsd-code-fixer)_
_Iteration: 1_
