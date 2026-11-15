---
phase: 06-sensitive-data-account-hardening
reviewed: 2026-11-14T00:00:00Z
depth: deep
files_reviewed: 38
files_reviewed_list:
  - app/Security/AppSettings.php
  - app/Security/SensitiveGate.php
  - app/Security/SecondFactorPolicy.php
  - app/Security/SensitiveSession.php
  - app/Security/PasswordSetBackfill.php
  - app/Security/SafeIntended.php
  - app/Security/ReauthMethods.php
  - app/Security/Passkeys/PasskeyCeremony.php
  - app/Http/Middleware/RequireAccess.php
  - app/Http/Middleware/EnsureRouteDeclaresAccess.php
  - app/Http/Middleware/GuardTwoFactorRoutes.php
  - app/Http/Middleware/ThrottleAuthRoutes.php
  - app/Http/Middleware/NoStoreDynamicResponses.php
  - app/Http/Middleware/EnsurePasskeysEnabled.php
  - app/Http/Middleware/HandleInertiaRequests.php
  - app/Providers/FortifyServiceProvider.php
  - app/Providers/SecurityServiceProvider.php
  - app/Auth/RedirectIfSecondFactor.php
  - app/Auth/SessionRevoker.php
  - app/Auth/GoogleLoginService.php
  - app/Actions/Fortify/ResetUserPassword.php
  - app/Http/Controllers/Security/ChallengePasskeyController.php
  - app/Http/Controllers/Security/PasskeyController.php
  - app/Http/Controllers/Security/ReauthController.php
  - app/Http/Controllers/Security/ReauthPasskeyController.php
  - app/Http/Controllers/Security/SecuritySettingsController.php
  - app/Http/Controllers/Auth/GoogleController.php
  - app/Http/Controllers/Settings/MemberSecondFactorController.php
  - app/Http/Controllers/Settings/SecurityPolicyController.php
  - app/Http/Requests/Security/ReauthRequest.php
  - app/Http/Requests/Security/PasskeyNameRequest.php
  - app/Models/User.php
  - bootstrap/app.php
  - config/security.php
  - config/fortify.php
  - database/migrations/2026_11_17_100003_add_password_set_to_users_table.php
  - routes/web/*.php (reauth, security-passkeys, settings-security, settings-security-admin, google, settings-members, invitations, settings-finance-accounts)
  - resources/js (TwoFactorSetupDialog, ReauthForm, TwoFactorChallenge, ConfirmAccess, PasskeysCard, webauthn.js, securityFetch.js, importApi.js), tests/Support/SensitiveRoutes.php, tests/Feature/Security/SensitiveGateMatrixTest.php
findings:
  critical: 3
  warning: 9
  info: 5
  total: 17
status: issues_found
---

# Phase 6: Code Review Report

**Reviewed:** 2026-11-14
**Depth:** deep
**Files Reviewed:** 38 (groups listed above)
**Status:** issues_found

## Summary

The core mechanics are mostly sound. The allow decision runs before the idle check, and the idle check runs before the audit write. Timestamps live only in the server session. Remember-cookie restores never call `confirm()`. The challenge TTL, the pending-secret-is-not-a-factor rule, the dummy-hash timing path, the `password.confirm*` denial, the SafeIntended filter and the passkey ownership checks all hold up. `AlwaysFreshSensitiveSession` cannot be bound in production: it is only bound in `tests/TestCase.php`, and the `Tests\` namespace is dev-only autoload.

I could not run PHP here (the host has 8.3, the lockfile needs >= 8.4), so everything below comes from static tracing.

The weaknesses are mostly on the edges of the gate:

- Privilege-granting admin routes sit outside both the idle gate and `recent`.
- Re-authentication and factor removal are weaker than sign-in.
- The Google re-auth freshness is never verified.
- A few state and configuration holes could lock people out or leave a pending TOTP seed readable.

## Critical Issues

### CR-01: Idle gate bypass by granting access, with no `recent` or gate on admin grant routes

**File:** `routes/web/settings-members.php:8-10`, `routes/web/invitations.php:7-15`, `routes/web/settings-finance-accounts.php:6-10`
**Issue:** The idle gate protects reading and writing finance data, but a stale admin session can create the access without ever touching the gate:
- `PUT /settings/members/{user}/permissions` has only `access:admin`.
- `POST /settings/invitations` (with a finance or health preset) and `POST /settings/invitations/{id}/link` have only `access:admin`.
- `POST` and `PATCH /settings/finance-accounts` can add members to accounts.

An attacker at an unattended, idle admin browser can issue an invite link with a Finance Edit preset and accept it with their own password. They then have permanent finance access on their own account and never face the confirm screen. Only `settings.security.policy` and the second-factor reset carry `access:recent`, so the phase protects the factor controls but not the grants that make the factor controls pointless. The `SensitiveRoutes` allowlist for `settings.finance_accounts.` is marked "metadata only", but the `store` and `update` routes mutate membership.
**Fix:** Add `access:recent` to every route that grants, changes or issues Finance/Health access:
```php
Route::put('/settings/members/{user}/permissions', ...)->middleware(['access:admin', 'access:recent']);
Route::middleware(['access:admin', 'access:recent', 'throttle:30,1'])->group(/* invitation store/resend/link */);
// settings.finance_accounts.store/update: ['access:admin', 'access:recent']
```
Also add a matrix test: every route that changes a `ModulePermission`, an invitation or finance-account membership must be gated by the gate or `recent`.

### CR-02: Google re-authentication does not verify that the sign-in was fresh

**File:** `app/Http/Controllers/Auth/GoogleController.php:55-65, 118-137`, `app/Security/ReauthMethods.php:22-28`
**Issue:** `reauthCallback()` accepts any Google identity whose `sub` equals the stored `google_id` and treats it as proof of fresh identity. It sends `prompt=select_account&max_age=0`, which the code itself marks `[ASSUMED]`, and it never checks the `auth_time` claim of the ID token. Socialite's `user()` does not expose that claim.
- If Google ignores the parameters, or auto-selects the only signed-in account, the confirmation is a one-click action on an unattended browser. This is exactly the stolen-idle-session scenario the gate exists for.
- The flag only gates the feature (`SECURITY_GOOGLE_REAUTH`, default off). Flipping it on in `.env` enables a hole.
- `max_age` is not verified server-side, which the OIDC spec requires for a relying party that sends it.

**Fix:** Do not enable the flag until the freshness can be proven. Decode the ID token and require `auth_time >= flag['at'] - skew`, and reject if the claim is absent:
```php
$claims = $this->idTokenClaims($identity); // verify signature + iss + aud
$ok = $ok && isset($claims['auth_time']) && (int) $claims['auth_time'] >= (int) $flag['at'] - 5;
```
Otherwise remove the Google fallback and leave "sign in again" (password) as the only fallback.

### CR-03: Password-only re-authentication can remove or replace a second factor

**File:** `app/Http/Controllers/Security/ReauthController.php:30-55`, `app/Http/Middleware/GuardTwoFactorRoutes.php:101-117`, `routes/web/security-passkeys.php:12-19`
**Issue:** Sign-in for a member with TOTP or a passkey requires the second factor. The `recent` window that guards disabling TOTP, regenerating recovery codes, removing passkeys and adding passkeys can be opened with the password alone, because `ReauthMethods::password` is offered to every member with `password_set`.

An attacker with a stolen session or remember cookie plus a known password can therefore strip or replace the second factor without ever presenting it, and then re-authenticate with only the password. The idle gate for Finance and Health has the same shape (see WR-01), but here the stakes are the factor itself. The `recent` window is also refreshed by any re-auth method, so it does not record which method was used.
**Fix:** Record the strongest method used (for example `auth.confirmed_method` and `auth.confirmed_with_factor`) and require, for factor management routes, that the confirmation included a second factor when the member has one. Alternatively, make `access:recent` accept only TOTP or a passkey when `hasSecondFactor()` is true:
```php
'recent' => $user !== null  // plus: if ($user->hasSecondFactor()) require session('auth.confirmed_factor') === true
```

## Warnings

### WR-01: Idle-gate re-auth is weaker than sign-in for second-factor members

**File:** `app/Security/ReauthMethods.php:14-20`, `app/Http/Controllers/Security/ReauthController.php:79`
**Issue:** Related to CR-03. Every `password_set` member can confirm for the sensitive modules with the password alone, including members with TOTP or passkeys, and including a remember-cookie session that skipped the second factor entirely at "login". The second factor that `SecondFactorPolicy` forces members to enrol is never required to open Finance or Health. A phished password plus a stolen remember cookie is enough.
**Fix:** For a member with `hasSecondFactor()`, make the confirm form require the factor (TOTP or passkey). Alternatively require the password plus the factor when the session arrived via the remember cookie, which is detectable through `Auth::viaRemember()`.

### WR-02: QR and secret-key endpoints do not require `recent`, and pending secrets never expire

**File:** `app/Http/Middleware/EnsureRouteDeclaresAccess.php:34-35`, `app/Http/Middleware/GuardTwoFactorRoutes.php:67`
**Issue:** `two-factor.qr-code` and `two-factor.secret-key` are mapped to `authenticated` (and 404 unless a secret is pending). A pending TOTP seed has no TTL. A member who abandons the setup dialog without Cancel (closed tab) leaves a pending seed in the database. Later, any stale or stolen session of that member can read the seed. If the owner then confirms in a later session, the attacker holds a working authenticator. Confirm requires `recent`, but the seed read does not.
**Fix:** Map both endpoints to `['recent']` and expire the pending secret. Do this by also checking `updated_at` (or a new `two_factor_pending_at` column) in `pending()`, or by purging unconfirmed secrets on the scheduler after N minutes.

### WR-03: Passkey-only members are locked out when `SECURITY_PASSKEYS_ENABLED=false`

**File:** `app/Models/User.php:46-50`, `app/Auth/RedirectIfSecondFactor.php:21`, `app/Providers/FortifyServiceProvider.php:196-198`, `app/Http/Controllers/Security/ChallengePasskeyController.php:68-76`
**Issue:** `hasSecondFactor()` counts passkeys regardless of the flag. With the flag off:
- A passkey-only member is still challenged after the password.
- The challenge page shows `totp=false, passkey=false`, and the passkey routes return 404.
- That member cannot sign in at all, and the policy still treats them as covered.
- `ReauthMethods::for` hides the passkey option, so they also cannot re-auth.

It is the documented kill switch, so flipping it in an incident locks out exactly the members who chose a passkey.
**Fix:** Make `hasSecondFactor()` and `RedirectIfSecondFactor` count passkeys only when `config('security.passkeys_enabled')`. Better, fall back to "the member must enrol TOTP" (so `mustEnrol` is true) and log a warning.

### WR-04: Audit write after the state change can fail the request after the factor is disabled

**File:** `app/Http/Middleware/GuardTwoFactorRoutes.php:111-115`, `app/Http/Controllers/Security/ReauthController.php:47-48`
**Issue:** `disable()` calls `$next($request)` (Fortify has already nulled the secret and recovery codes) and only then writes the audit row. If the write throws (the logger deliberately fails closed), the client gets a 500 although the factor is already gone, and no row exists. The same pattern applies in `ReauthController::store`, where `confirm()` has already stored the timestamps when `record()` throws. The session still persists, so the member is confirmed and the audit is missing.
**Fix:** Record the audit in the same DB transaction as the change. For Fortify, wrap `$next` in `DB::transaction` plus the audit. In `ReauthController`, write the audit first and call `confirm()` after it succeeds.

### WR-05: Audit amplification from throttled requests

**File:** `app/Providers/SecurityServiceProvider.php:65-80`
**Issue:** The `response()` closure on both limiters runs for every request made while locked out and writes a `lockout` row each time. An attacker holding a pending login id (or a stolen session at `/confirm-access`) can fill the audit table at line rate. This also drowns the real events the admin looks for.
**Fix:** Write the lockout row once per window, for example `Cache::add('lockout-audited:'.$key, 1, $retryAfter)` before `record()`.

### WR-06: TOTP replay cache and recovery codes have races and a shared key

**File:** `app/Http/Controllers/Security/ReauthController.php:81-90`, `app/Models/User.php:52-61`, vendor `TwoFactorAuthenticationProvider::verify`
**Issue:**
- The replay guard is keyed `fortify.2fa_codes.md5($code)` globally. Two members who enter the same six-digit code within the window collide, and the second is rejected.
- `replaceRecoveryCode()` reads, filters and writes without a lock. Two parallel requests with the same recovery code both pass `validRecoveryCode()` before either save, so one code logs in twice.

**Fix:** Add `lockForUpdate` inside a transaction in `replaceRecoveryCode` and re-check that the code is still present. Bind a decorating `TwoFactorAuthenticationProvider` that namespaces the cache key by user id.

### WR-07: Session ID is not rotated when privilege is elevated (re-auth, enrol)

**File:** `app/Http/Controllers/Security/ReauthController.php:46`, `ReauthPasskeyController.php:36`, `GoogleController.php:134`
**Issue:** `SensitiveSession::confirm()` upgrades the session's trust but keeps the session ID. A session ID captured before the confirmation (for example by an XSS-free but shoulder-surfed or logged cookie) acquires the confirmed state for the next 10 and 15 minutes. Login paths do regenerate the ID, so this inconsistency only affects the re-auth paths.
**Fix:** Call `$request->session()->regenerate()` inside `confirm()` callers (not `invalidate()`, so the intended URL survives).

### WR-08: Config knobs are unclamped and can silently disable the gate

**File:** `config/security.php:5-12`
**Issue:** `SECURITY_IDLE_SECONDS` and `SECURITY_CONFIRM_WINDOW_SECONDS` are cast with `(int)` and used as-is. A typo (`15m` casts to 15, `0`, or a huge number such as a milliseconds value) leaves the gate effectively off or permanently locked, with no startup check. The `SENSITIVE_...` knobs are the only thing between a stale session and the data.
**Fix:** Clamp in `config/security.php`, for example `max(60, min(86400, (int) env(...)))`. Fail boot in production if the values fall outside the clamp.

### WR-09: The stale-session matrix test does not prove the gate on every sensitive path

**File:** `tests/Support/SensitiveRoutes.php:73-83`, `tests/Feature/Security/SensitiveGateMatrixTest.php:70-95`
**Issue:**
- Sensitive routes are discovered only by the middleware string `access:module,(finance|health),`. The naming net (`offenders()`) only catches URIs and names containing `finance`, `health` or `medical`. A route that serves money data under any other name (an export, a notification or push endpoint, a settings or audit view) is invisible to the test.
- The `access:authenticated` Home card (`HomeSummary`) is knowingly allowlisted.
- Staleness is simulated by a missing key, not an expired timestamp, so the `idle_seconds` boundary is never exercised in the matrix.
- Only plain HTML and JSON requests are covered. There is no case for an Inertia `X-Inertia` request, HEAD, prefetch, `_method` override or a multipart upload.
- Fresh-session coverage is GET only.
- The test cannot catch CR-01 at all, because grant routes are not "sensitive" by its definition.

**Fix:** Add a request-level matrix over each sensitive route: Inertia request, HEAD, `Purpose: prefetch`, expired timestamp (`now()-901`). Add an arch test that every route calling a finance or health query class carries the gate. Add the grant routes from CR-01 to the "must be recent" list.

## Info

### IN-01: Home "still to pay" card and finance-account settings are visible on an idle session

**File:** `app/Http/Controllers/HomeController.php:40-45`, `routes/web/settings-finance-accounts.php`
**Issue:** The counts (payments and overdue) and account names are served on `access:authenticated` routes with no idle check. This is a recorded provisional decision, but it still leaks financial state to an unattended screen. Keep it only with owner sign-off.
**Fix:** Hide the card when `SensitiveSession::fresh()` is false, or keep it and document the exposure in the 06-13 sign-off.

### IN-02: Route model binding runs before `access:*` for passkey routes

**File:** `routes/web/security-passkeys.php:19-24`
**Issue:** `{passkey}` is implicitly bound in `SubstituteBindings` (web group), which runs before the route-level `access:*` middleware. An anonymous caller with a CSRF token gets 404 for a missing id but a redirect or 401 for an existing one, which enumerates passkey ids. The members and invitation routes avoid this with explicit comments.
**Fix:** Resolve the passkey in the controller (`Passkey::find($id)` after the access rules), as done for members and invitations.

### IN-03: Passkey ceremony swallows all Throwables into one generic failure

**File:** `app/Security/PasskeyCeremony.php:41-60`
**Issue:** It fails closed, so this is not an open door, and the log keeps only the exception class. But a misconfiguration (a missing `allowed_origins`, a wrong RP id, a decryption problem, `Passkeys::allowedOrigins()` throwing `RuntimeException`) is indistinguishable from a bad assertion. Every passkey sign-in for every member silently fails, and the only symptom is `second_factor_failed` audit rows.
**Fix:** Log (with the class only) at `error` level for non-`InvalidPasskeyException` throwables, and add a boot-time health check that `Passkeys::allowedOrigins()` and `relyingPartyId()` resolve.

### IN-04: Reauth limiter allows hundreds of guesses a day per account

**File:** `app/Providers/SecurityServiceProvider.php:56-61`
**Issue:** 5 per minute and 20 per hour per user means up to 480 password or TOTP guesses per day through a stolen session. The login limiter is 10 per 15 minutes. The key is per user, so two parallel stolen sessions share it. Acceptable for TOTP, thin for the password, and the lockout is not escalated into a session logout.
**Fix:** After N failures in the hour, call `Auth::logout()` and invalidate the session. Drop the hourly limit to 10.

### IN-05: Failed-audit and policy details

**File:** `app/Http/Controllers/Settings/SecurityPolicyController.php:16`, `app/Models/User.php:46-50`
**Issue:**
- `boolean:strict` rejects `"1"` and `"true"`, so a plain form post fails with a validation error. This is fine for JSON clients, but the frontend must keep sending JSON.
- `hasSecondFactor()` runs a query on each call, and `pendingCount()` iterates every user, so the admin screen is O(users) queries. This is out of scope for performance but trivial to fix with an `exists` subquery.

**Fix:** Keep the JSON-only contract documented. Move the passkey check into a `withExists` subquery for the member list.

## Verified clean (no finding)

- **Gate order:** allow decision, then `SensitiveGate` (no-access members get 403, never a redirect), then the audit write.
- **Gate request shapes:** JSON, Inertia, passive, prefetch, HEAD, OPTIONS (403 by `EnsureRouteDeclaresAccess`) and `_method` override.
- **Session and sign-in:** no way to forge the session timestamps from the client, and remember-cookie restores never confirm.
- **Test seam:** no production binding of the test seam.
- **Challenge:** `login.id` and `login.at` TTL is enforced before the throttle and is user-keyed. The pending secret does not count as a factor, and `stripUnusableInput` stops null-decrypt for passkey-only members.
- **`password.confirm*` and recovery codes:** `password.confirm.store` and the recovery-codes GET are denied. Recovery codes appear only in the `Cache-Control: no-store` confirm and regenerate JSON.
- **SafeIntended:** it covers protocol-relative URLs, backslashes, control characters, userinfo, foreign hosts, scheme-only values and the auth URLs. Residual redirect risk is limited to harmless self-redirects.
- **Admin reset:** it blocks the admin's own row, requires `access:admin` and `recent`, revokes all sessions and rotates the remember token in one transaction.
- **Passkey verification:** user verification is required, the credential must belong to the pending or authenticated user, counters are updated after each use, and options are single-use (`pull`).
- **Passkey names:** control characters are rejected and there is no `v-html` on names. The only `v-html` is the server-generated QR SVG.
- **PasswordSetBackfill:** it defaults uncertain rows to `false`. Because `authenticateUsing` ignores `password_set` and the `confirm-access/sign-in-again` path exists, a mis-flagged member can still sign in and re-confirm through login, so there is no hard lockout.

---

_Reviewed: 2026-11-14_
_Reviewer: Claude (gsd-code-reviewer)_
_Depth: deep_
