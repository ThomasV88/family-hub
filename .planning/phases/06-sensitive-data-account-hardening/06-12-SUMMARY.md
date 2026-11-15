---
phase: 06-sensitive-data-account-hardening
plan: 12
subsystem: security-tests
tags: [audit, canary, arch-tests, no-store, axe, service-worker, ops, validation]
requires: [06-06, 06-07, 06-08, 06-09, 06-10, 06-11]
provides:
  - Security rows in the admin audit table (11 events, danger tag for failed checks)
  - Canary scan of audit rows and logs across every Phase 6 flow
  - Architecture guarantees (hidden columns, session-key ownership, no test seam or passkeys feature in app, recency rules)
  - no-store proof on security pages, secret-bearing endpoints and 423 refusals
  - Phase-wide axe sweep and service-worker precache check
  - Ops notes and signed-off 06-VALIDATION.md
key-files:
  created:
    - tests/js/settings/AuditSecurity.test.js
    - tests/js/settings/auditSecurityFixtures.js
    - tests/Unit/Arch/SecurityArchTest.php
    - tests/Feature/Security/SecurityCacheHeadersTest.php
    - tests/Feature/Security/SecurityAuditContentTest.php
    - tests/js/security/a11y-security.test.js
    - tests/js/security/sw-security.test.js
  modified:
    - resources/js/Components/Settings/AuditTable.vue
    - bootstrap/app.php
    - docs/ops/server.md
    - .planning/phases/06-sensitive-data-account-hardening/06-VALIDATION.md
requirements-completed: []
completed: 2026-11-14
---
# Phase 6 Plan 12: Audit display, cross-cutting proofs and sign-off Summary

Security events are readable in the audit log, and the phase is proven locally: no secret, code, passkey name or credential reaches audit rows or logs, security responses (including refusals) are no-store, the service worker never caches them, every new screen passes axe in SK/PL/EN light and dark, and the validation map is signed off. AUTH-09 and AUTH-10 stay unticked until the live plan 06-13 completes.

## Commits
- 7bf6804: AuditTable security mapping, canary scan, arch test, cache-header test, NoStore ordering fix
- a2ee19c: phase-wide axe sweep and service-worker check
- d7b5941: ops notes in docs/ops/server.md
- 22f0032: 06-VALIDATION sign-off

## Behaviour
- AuditTable maps two_factor_*, passkey_*, second_factor_failed (danger tag with ShieldAlert), recovery_*, second_factor_reset (target name), security_policy_changed (requirement on/off from meta.value) and reauth_confirmed to the existing `audit.detail.*` keys; security events carry a neutral ShieldCheck tag. Unmapped meta is never printed.
- SecurityAuditContentTest drives TOTP setup/confirm/regenerate/disable, challenge success, failure and recovery use, passkey add/rename/remove/challenge/reauth (container fakes, success and failure), /confirm-access success and failure, admin policy toggle and reset, and Google re-auth failure with canaries (secrets, all recovery codes, passkey names, wrong password and codes, credential ids, Google tokens). No audit_logs column and no MessageLogged record contains any of them; nine expected security events are asserted present so the scan cannot pass on an empty flow.
- SecurityArchTest: hidden factor columns, no factor data in Inertia share, session keys only in SensitiveSession, nothing under app/ references AlwaysFreshSensitiveSession, no `Features::passkeys` in code or config, no Log:: credential/code context keys, no secret/recovery/credential Inertia props in security controllers, `recent` on two-factor.enable/confirm/regenerate (FORTIFY_RULES) and `access:recent` on passkeys.options/store/destroy, settings.security.policy and member reset, `google_reauth` defaults to false.
- Axe sweep: 49 states x 3 locales x 2 themes plus a pseudo-long (+40%) run for sk and pl; every state asserts it reached its dialog or form. No accessibility violation was found, so no component changed.

## Deviations from Plan

**1. [Rule 2 - Missing critical functionality] Access-rule refusals were not no-store**
- **Found during:** Task 1 (SecurityCacheHeadersTest)
- **Issue:** `NoStoreDynamicResponses` was appended after `EnsureRouteDeclaresAccess` in the web group, so 423 `confirm_required` and other refusals produced by the access rules answered `no-cache, private` instead of `no-store, private`. T-06-66 requires the 423 refusals to be no-store.
- **Fix:** moved `NoStoreDynamicResponses` to the front of the web append list so it wraps every later middleware.
- **Files:** bootstrap/app.php. Full PHP suite (1591 tests) still green.
- **Commit:** 7bf6804

**2. [Plan wording] `password.confirm.store` route**
- Fortify registers the route; it is mapped to the `disabled` access rule (403 for everybody) rather than being absent. SecurityArchTest asserts the mapping, so it can never become a usable password oracle.

**3. Minor**
- Arch test boots the app with `uses(Tests\TestCase::class)` because it inspects routes and config (tests/Unit does not boot by default).
- Added `tests/js/settings/auditSecurityFixtures.js` (shared fixture used by two test files).
- The plan's acceptance grep `grep -c "nyquist_compliant: true"` equals 1: the sign-off checklist line was reworded so only the frontmatter matches.

## Verification
- Full Vitest: 103 files, 2217 tests pass. `npm run build` OK (SW-PRECACHE OK). Full Pest (`-p fh0612`, memory_limit 1G): 1591 tests pass. Stack torn down with `down -v`.

## Known Stubs
None.

## Threat Flags
None beyond the plan's threat model (T-06-65..68 mitigated: canary scan, no-store tests, arch guarantees, ops notes).

## Self-Check: PASSED
