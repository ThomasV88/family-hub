---
phase: 06-sensitive-data-account-hardening
plan: 05
subsystem: auth
tags: [fortify, two-factor, totp, challenge, middleware, inertia]
requires: [06-01, 06-02, 06-03]
provides:
  - Fortify two-factor feature enabled headless (confirm true, confirmPassword false, no passkeys feature)
  - RedirectIfSecondFactor (challenge for authenticator or passkey members, login.at stamp)
  - GuardTwoFactorRoutes (name-keyed hardening of every two-factor route)
  - Sign-in response bindings that open the sensitive window (remember-cookie restore does not)
  - Auth/TwoFactorChallenge page with a `passkey` slot for 06-09
affects: [06-06, 06-09, 06-10, 06-11]
key-files:
  created:
    - app/Auth/RedirectIfSecondFactor.php
    - app/Http/Middleware/GuardTwoFactorRoutes.php
    - resources/js/Pages/Auth/TwoFactorChallenge.vue
    - tests/Support/EndpointMatrix/two-factor.php
    - tests/Feature/Security/ChallengeTest.php
    - tests/Feature/Security/SignInConfirmationTest.php
    - tests/Feature/Security/TwoFactorEndpointsTest.php
    - tests/js/security/TwoFactorChallenge.test.js
  modified:
    - config/fortify.php
    - app/Http/Middleware/EnsureRouteDeclaresAccess.php
    - app/Providers/FortifyServiceProvider.php
    - app/Models/User.php
    - bootstrap/app.php
    - resources/js/Pages/Auth/Login.vue
    - resources/js/Layouts/AppShell.vue
    - tests/Support/EndpointMatrix/core.php
    - tests/Feature/Auth/RegistrationAbsentTest.php
    - tests/js/shell/AppShell.test.js
decisions:
  - "A used recovery code is removed from the stored set (User::replaceRecoveryCode override) instead of Fortify's swap for a fresh unseen code, so 'N codes left' is truthful"
  - "GuardTwoFactorRoutes blanks code/recovery_code when the challenged member has no confirmed authenticator (passkey-only or pending secret), so Fortify never decrypts null and a pending secret is never a way in; the attempt still passes throttle:two-factor and fires the failure audit"
  - "Confirm throttle (5/min per member) also writes a lockout audit row, consistent with the other limiters"
metrics:
  tasks: 3
  completed: 2026-11-14
---

# Phase 6 Plan 05: Two-factor sign-in challenge and endpoint hardening Summary

Members with an authenticator app or a passkey must pass a second step after the password: headless Fortify two-factor, a custom `RedirectIfSecondFactor` using `hasSecondFactor()`, a 10-minute challenge TTL, per-member throttling, hardened management endpoints (secret/QR only while pending, 409 on re-enable, enrol/confirm/disable/regenerate gated by the 10-minute confirmation, recovery codes returned once as no-store JSON), Fortify password-confirm and plaintext recovery-code routes denied, and a two-step challenge page.

## Commits
- 33feb4c: Fortify 2FA wiring, RedirectIfSecondFactor, response bindings, FORTIFY_RULES, tests (Task 1)
- 081567c: GuardTwoFactorRoutes, matrix rows, endpoint tests (Task 2)
- 0590498: challenge page, Login status, AppShell toast count, Vitest (Task 3)
- 8ffd63b: pint formatting for the two feature tests

## Deviations from Plan

**1. [Rule 1 - Bug] Fortify swaps a used recovery code for a new unseen one**
- Found during: Task 1 (test expects n = 7 remaining)
- Issue: stored count stays 8 after use, so the toast could never be truthful.
- Fix: `User::replaceRecoveryCode` override removes the used code (still dispatches `RecoveryCodeReplaced`). app/Models/User.php.

**2. [Rule 1 - Bug] Passkey-only member or pending secret at the challenge**
- Issue: Fortify's TwoFactorLoginRequest decrypts `two_factor_secret` (null for passkey-only: 500) and would accept a code from an unconfirmed pending secret.
- Fix: guard blanks both inputs for members without a confirmed authenticator; tested.

**3. [Rule 1 - Bug] Existing test expectations**
- RegistrationAbsentTest asserted no two-factor route and only the reset feature; updated to the new contract.
- core.php `password.confirm*` rows moved to 403 for signed-in (denied rule).

**4. Test technique:** remember-cookie restore test uses `withUnencryptedCookie` with the already-encrypted login cookie (`withCookie` would double-encrypt); Inertia component existence check disabled for the challenge page in Task 1 (page arrives in Task 3, assertion kept `false` since the Vue file now exists but the flag is harmless).

## Verification
- Full PHP suite: 1471 passed. Full Vitest: 1664 passed.
- Acceptance greps: `Features::passkeys` 0, `'confirmPassword' => false` 1, `GuardTwoFactorRoutes` in bootstrap/app.php 1, enable/confirm rules contain `recent`, two-factor.php has 9 route rows.
- FortifyServiceProvider has pint style notes (anonymous class layout, same as the pre-existing bindings); left as is.

## Notes for later plans
- 06-06: setup JSON contract is `{enabled:true}` (enable), `{recoveryCodes:[...]}` (confirm and regenerate), `{disabled:true}`; stale refusals are 423 `{error:'confirm_required'}`; throttle 429 `{error:'throttle', minutes}`; already enabled 409 `{error:'already_enabled'}`.
- 06-09: render `PasskeyButton` in the `passkey` slot of TwoFactorChallenge (slot prop `locked`); routes named `two-factor.passkey.*` get the TTL guard automatically.
- Parallel plan 06-04 must not re-map `two-factor.*` rules; `recent` is evaluated by RequireAccess exactly as 06-02 shipped.

## Known Stubs
None.

## Threat Flags
None beyond the plan's threat model.

## Self-Check: PASSED
Files and the four commits verified present.
