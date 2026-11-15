---
phase: 06-sensitive-data-account-hardening
plan: 11
subsystem: security-tests
tags: [idle-gate, endpoint-matrix, safety-net, import-wizard, 423]
requires: [06-04, 06-05]
provides:
  - SensitiveRoutes (enumeration by access:module,finance|health middleware, reasoned allowlist, naming safety net)
  - SensitiveGateMatrixTest (stale-session replay of every sensitive matrix row, fresh GET replay, safety net, mutation checks, Home card)
  - SignInToFinanceTest (password, TOTP, remember-cookie restore end to end)
  - 423 handling in importApi.preview and Import.vue
affects: [06-13]
key-files:
  created:
    - tests/Support/SensitiveRoutes.php
    - tests/Feature/Security/SensitiveGateMatrixTest.php
    - tests/Feature/Security/SignInToFinanceTest.php
    - tests/js/finance/importReauth.test.js
  modified:
    - resources/js/Finance/import/importApi.js
    - resources/js/Pages/Finance/Import.vue
requirements-completed: [AUTH-10]
completed: 2026-11-14
---

# Phase 6 Plan 11: Stale-session proof and import 423 handling Summary

CI now replays every route behind `access:module,finance|health` with a real stale session (302/303 to /confirm-access, 423 JSON, no audit row), a naming/URI safety net catches routes that forget the access rule, and the import wizard sends the member to /confirm-access on an idle refusal.

## Commits
- 382a1f0: SensitiveRoutes, SensitiveGateMatrixTest, SignInToFinanceTest
- 250971e: importApi 423 mapping, Import.vue visits, Vitest

## Behaviour
- Datasets cannot enumerate routes (the app is not booted when datasets resolve), so the stale and fresh replays are loops with the route name in every assertion message.
- Mutation checks: throwaway `finance.zz_probe` (access:authenticated) is reported as an offender; throwaway `health.zz_probe` (access:module,health,view) is enumerated as sensitive and gated (302 stale, no audit).
- Allowlist (all PROVISIONAL (orchestrator), owner confirms in 06-13 Task 2): `settings.finance_accounts.*` not gated; Home still-to-pay card visible when stale (asserted in the test); no import-wizard keepalive (comment in Import.vue and SensitiveRoutes).
- Import.vue: `reauth_required` -> `router.visit('/confirm-access')`, `second_factor_required` -> `/settings/security`, no file error; sets `allowLeave` so the dirty-wizard leave guard does not intercept the visit; the file stays in memory only.

## Notes for Phase 7
Every future health/medical route MUST carry `access:module,health,<level>` (that middleware puts it behind the idle gate by construction, because SensitiveGate runs inside RequireAccess for Module::sensitive()), and MUST use a URI under /health or /medical and a route name containing `health` or `medical`, so the naming safety net in SensitiveGateMatrixTest catches a route that forgets the middleware. File and JSON routes included.

## Deviations from Plan
None for production code; no finance route needed fixing. Dataset-per-route replaced by loops (see Behaviour).

## Verification
- SensitiveGateMatrixTest 9 pass, SignInToFinanceTest 3 pass; finance Vitest 575 pass; full Vitest 1673 pass.
- Full PHP suite could not run clean due to files owned by parallel plans: `addPasskey()` is declared twice (tests/Feature/Security/PolicyAndResetTest.php and SecondFactorModelTest.php, fatal for the whole run, persisted on re-run). Excluding PolicyAndResetTest: 1561 pass, 4 fail in tests/Feature/Notifications/NotificationSettingsTest (settings sections order, "lists sections in order for admins": a parallel plan added a settings section). Not touched by this plan; for the owners of those plans / 06-13.

## Known Stubs
None.

## Self-Check: PASSED
