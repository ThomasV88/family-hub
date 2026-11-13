---
phase: 06-sensitive-data-account-hardening
plan: 02
subsystem: auth
tags: [session, rate-limiting, open-redirect, middleware, test-seam]
requires: []
provides:
  - SensitiveSession (idle window and security confirm window, sole owner of the two session keys)
  - SafeIntended (open-redirect-safe return path)
  - access:recent route rule (423 JSON or redirect to /confirm-access)
  - two-factor and reauth named limiters keyed by member id, audited lockouts
  - AlwaysFreshSensitiveSession test seam and TestCase::useRealSensitiveSession()
affects: [06-03, 06-12, 06-13]
key-files:
  created:
    - config/security.php
    - app/Security/SensitiveSession.php
    - app/Security/SafeIntended.php
    - app/Providers/SecurityServiceProvider.php
    - tests/Support/AlwaysFreshSensitiveSession.php
    - tests/Unit/Security/SafeIntendedTest.php
    - tests/Feature/Security/SensitiveSessionTest.php
    - tests/Feature/Security/RecentRuleTest.php
    - tests/Feature/Security/SecurityLimitersTest.php
  modified:
    - app/Http/Middleware/RequireAccess.php
    - bootstrap/providers.php
    - tests/TestCase.php
key-decisions:
  - "access:recent stores the return path through SafeIntended::remember and redirects to /confirm-access (instead of redirect()->guest) so the stored value is always validated; non-GET requests return to the previous page"
  - "Limiter lockouts write AuditEvent::Lockout directly with the member resolved by id"
metrics:
  tasks: 2
  completed: 2026-11-13
---

# Phase 6 Plan 02: Session windows, safe return path, recent rule and limiters Summary

Server-session idle and confirm windows (SensitiveSession), a same-origin-only return path (SafeIntended), the `access:recent` rule, per-member `two-factor` and `reauth` limiters with audited lockouts, and a test seam that keeps existing tests fresh by default.

## Commits
- b5c5b8f: session windows, SafeIntended, config, test seam (Task 1)
- 40bb570: recent rule, SecurityServiceProvider, limiters (Task 2)

## Deviations from Plan
- Minor: `recent` redirect uses `SafeIntended::remember` + `redirect(url('/confirm-access'))` rather than `redirect()->guest(...)`; same observable behaviour (302 to /confirm-access, `url.intended` set) with write validation.
- The SecurityServiceProvider registers scoped bindings for 06-01's AppSettings and SecondFactorPolicy by class constant only.

## Verification
Full PHP suite: 1405 passed (a first run had 3 failures in 06-01's in-flight files, gone on re-run). Session keys are referenced only in app/Security/SensitiveSession.php; the test seam is referenced only from tests/.

## Known Stubs
None.

## Self-Check: PASSED
