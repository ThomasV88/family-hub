---
phase: 06-sensitive-data-account-hardening
plan: 04
subsystem: auth
tags: [idle-gate, reauth, middleware, inertia, totp]
requires: [06-01, 06-02, 06-03]
provides:
  - SensitiveGate inside RequireAccess for Module::sensitive() (Finance, Health)
  - ReauthMethods (single source of the methods shape, googleAvailable)
  - /confirm-access page, POST (password or authenticator code), sign-in-again
  - ReauthForm (page and dialog modes, alternatives slot)
affects: [06-05, 06-06, 06-07, 06-09, 06-10]
key-files:
  created:
    - app/Security/SensitiveGate.php
    - app/Security/ReauthMethods.php
    - app/Http/Controllers/Security/ReauthController.php
    - app/Http/Requests/Security/ReauthRequest.php
    - routes/web/reauth.php
    - tests/Support/EndpointMatrix/reauth.php
    - resources/js/Pages/Auth/ConfirmAccess.vue
    - resources/js/Components/Security/ReauthForm.vue
    - tests/Feature/Security/SensitiveGateTest.php
    - tests/Feature/Security/IdleTimeoutTest.php
    - tests/Feature/Security/ReauthTest.php
    - tests/js/security/ConfirmAccess.test.js
    - tests/js/security/ReauthForm.test.js
  modified:
    - app/Access/Module.php
    - app/Security/SensitiveSession.php
    - app/Http/Middleware/RequireAccess.php
requirements-completed: [AUTH-10]
completed: 2026-11-14
---

# Phase 6 Plan 04: Idle gate and Confirm it's you Summary

Every health and finance route is gated server-side after the allow decision and before the audit write; stale members confirm with password or authenticator code and return to the page they asked for.

## Commits
- 013b3c7: Module::sensitive(), SensitiveGate, SensitiveSession::clear(), RequireAccess wiring, gate and idle tests
- 6e6f151: ReauthMethods, ReauthController/Request, routes, matrix rows, ReauthTest
- fca6309: ConfirmAccess page, ReauthForm, Vitest files

## Behaviour
- Order in the gate: second-factor requirement redirect first, then idle check, then slide. Passive requests (Purpose: prefetch, X-Fh-Passive: 1) never slide and get 423 when stale. JSON and non-navigational loads get 423; Inertia and navigation get 302 (GET) or 303 with flash reauth_interrupted (writes). No finance audit row is written for a bounced request; no-access members still get 403 plus access_denied.
- /confirm-access accepts exactly one string `password` or `code`; recovery codes, arrays and both fields give 422. JSON-mode success also opens the 10-minute window (SensitiveSession::confirm()). Failures and successes are audited; the existing `reauth` limiter throttles.

## Deviations from Plan
- **[Rule 1 - Bug, in plan text]** ReauthMethods totp uses secret plus confirmed_at directly (via `hasTotp()`), not Fortify's hasEnabledTwoFactorAuthentication(), per the 06-01 finding.
- Switch link uses the existing key `twofactor.challenge.use_totp` ("Use authenticator code").
- Replay test uses TOTP offset -1: Fortify stores the current slot as replay marker, so a future-slot code would be accepted twice (Fortify quirk, no production impact beyond clock drift window).
- Task 3's "no-method" Google link is intentionally absent (06-10 owns it); passkey button comes through the `alternatives` slot (06-09).

## Verification
- Vitest full: 92 files, 1650 tests pass. PHP full: 1470 pass; one failure in 06-05's TwoFactorEndpointsTest (in-flight file, not mine) passed on re-run (tests/Feature/Security 86 pass). Matrix failures seen mid-run were 06-05's unregistered routes, gone once it added its rows.

## Known Stubs
None.

## Self-Check: PASSED
