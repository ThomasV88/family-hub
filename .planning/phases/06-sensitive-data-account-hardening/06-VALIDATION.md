---
phase: 06
slug: sensitive-data-account-hardening
status: signed-off (local); 06-13 manual rows pending
nyquist_compliant: true
wave_0_complete: true
created: 2026-11-13
---

# Phase 06 — Validation Strategy

> Per-phase validation contract for feedback sampling during execution.

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | Pest 4 (PHP, in app container) + Vitest (frontend) |
| **Config file** | phpunit.xml, vitest.config.js |
| **Quick run command** | `docker compose run --rm app ./vendor/bin/pest --compact` |
| **Full suite command** | `docker compose run --rm app php -d memory_limit=1G ./vendor/bin/pest && npx vitest run` |
| **Estimated runtime** | ~06 seconds |

---

## Sampling Rate

- **After every task commit:** Run `docker compose run --rm app ./vendor/bin/pest --compact`
- **After every plan wave:** Run `docker compose run --rm app php -d memory_limit=1G ./vendor/bin/pest && npx vitest run`
- **Before `/gsd-verify-work`:** Full suite must be green
- **Max feedback latency:** 06 seconds

---

## Per-Task Verification Map

Pre-filled at planning time (plan revision 2026-11-13) from each task's `<verify>` command. Commands run from the repo root; `dc` = `docker compose -p fh06NN run --rm app` with the plan's project name; "+ full" = the task also runs the full Vitest and/or PHP suite and then `docker compose -p fh06NN down -v`, exactly as written in the plan. 06-12 Task 3 updates the statuses and signs off.

| Task ID | Plan | Wave | Requirement | Threat Ref | Secure Behavior | Test Type | Automated Command | File Exists | Status |
|---------|------|------|-------------|------------|-----------------|-----------|-------------------|-------------|--------|
| 06-01-01 | 01 | 1 | AUTH-09 | T-06-01, T-06-02, T-06-05, T-06-05b | factor columns hidden and encrypted; pending TOTP not a factor; Google-created accounts backfilled to password_set false | feature | `dc ./vendor/bin/pest tests/Feature/Security/SecondFactorModelTest.php` | ✅ | ✅ green |
| 06-01-02 | 01 | 1 | AUTH-09, AUTH-10 | T-06-03, T-06-04 | security events audited with allowlisted, content-free meta | feature | `dc ./vendor/bin/pest tests/Feature/Security/SecurityAuditEventsTest.php tests/Feature/Audit` | ✅ | ✅ green |
| 06-01-03 | 01 | 1 | AUTH-09 | T-06-04 | requirement off by default, applies only to Health/Finance members without factor | feature | `dc ./vendor/bin/pest tests/Feature/Security/SecondFactorPolicyTest.php` + full | ✅ | ✅ green |
| 06-02-01 | 02 | 1 | AUTH-10 | T-06-06, T-06-10 | safe intended path; idle/confirm windows; google_reauth defaults off | unit + feature | `dc ./vendor/bin/pest tests/Unit/Security/SafeIntendedTest.php tests/Feature/Security/SensitiveSessionTest.php` | ✅ | ✅ green |
| 06-02-02 | 02 | 1 | AUTH-09, AUTH-10 | T-06-07, T-06-08, T-06-09 | access:recent refuses stale sessions (423); per-member limiters with lockout audit | feature | `dc ./vendor/bin/pest tests/Feature/Security/RecentRuleTest.php tests/Feature/Security/SecurityLimitersTest.php tests/Feature/Access` + full | ✅ | ✅ green |
| 06-03-01 | 03 | 1 | AUTH-09, AUTH-10 | T-06-14 | SK/PL/EN parity, plurals, generic error copy, amendment keys | unit (Vitest) | `npx vitest run tests/js/i18n-phase6.test.js tests/js/i18n-phase5.test.js tests/js/i18n-phase4.test.js tests/js/i18n-phase3.test.js tests/js/i18n-phase2.test.js tests/js/i18n.test.js` | ✅ | ✅ green |
| 06-03-02 | 03 | 1 | AUTH-09 | T-06-11, T-06-12, T-06-13 | securityFetch CSRF/same-origin and 423/429/422 mapping; WebAuthn helper; code inputs | unit (Vitest) | `npx vitest run tests/js/security` + full | ✅ | ✅ green |
| 06-04-01 | 04 | 2 | AUTH-10 | T-06-15, T-06-16, T-06-17, T-06-20 | idle gate on every finance/health route; passive requests never slide; no audit on bounce | feature | `dc ./vendor/bin/pest tests/Feature/Security/SensitiveGateTest.php tests/Feature/Security/IdleTimeoutTest.php tests/Feature/Audit/AuditMiddlewareTest.php tests/Feature/Access` | ✅ | ✅ green |
| 06-04-02 | 04 | 2 | AUTH-10 | T-06-18, T-06-19, T-06-21, T-06-21b | confirm with password/TOTP, throttled, audited, safe return; Google method only when flag on and no stronger method | feature | `dc ./vendor/bin/pest tests/Feature/Security/ReauthTest.php tests/Feature/Access/EndpointMatrixTest.php` | ✅ | ✅ green |
| 06-04-03 | 04 | 2 | AUTH-10 | T-06-19 | confirm page and ReauthForm (no recovery codes offered) | component (Vitest) | `npx vitest run tests/js/security` + full | ✅ | ✅ green |
| 06-05-01 | 05 | 2 | AUTH-09, AUTH-10 | T-06-22, T-06-28, T-06-29, T-06-30 | any factor forces the challenge; sign-in confirms; remember-cookie does not | feature | `dc ./vendor/bin/pest tests/Feature/Security/ChallengeTest.php tests/Feature/Security/SignInConfirmationTest.php tests/Feature/Auth` | ✅ | ✅ green |
| 06-05-02 | 05 | 2 | AUTH-09 | T-06-23, T-06-24, T-06-25, T-06-26, T-06-27, T-06-27b | hardened 2FA endpoints; enable/confirm need the 10-minute confirmation (stale session cannot enrol) | feature | `dc ./vendor/bin/pest tests/Feature/Security/TwoFactorEndpointsTest.php tests/Feature/Access/EndpointMatrixTest.php` | ✅ | ✅ green |
| 06-05-03 | 05 | 2 | AUTH-09 | T-06-30 | challenge page generic errors, throttle, a11y | component (Vitest) | `npx vitest run tests/js/security tests/js/auth` + full | ✅ | ✅ green |
| 06-06-01 | 06 | 3 | AUTH-09 | T-06-31, T-06-34 | Security page props content-free; continue uses SafeIntended | feature | `dc ./vendor/bin/pest tests/Feature/Security/SecurityPageTest.php tests/Feature/Settings tests/Feature/Access/EndpointMatrixTest.php` | ✅ | ✅ green |
| 06-06-02 | 06 | 3 | AUTH-09 | T-06-32, T-06-33b, T-06-35 | no secrets left in DOM/history; setup asks to confirm in the dialog when stale | component (Vitest) | `npx vitest run tests/js/security/SecurityPage.test.js tests/js/security/TwoFactorSetupDialog.test.js` | ✅ | ✅ green |
| 06-06-03 | 06 | 3 | AUTH-09 | T-06-33 | turn-off and new codes confirm in the dialog, never navigate | component (Vitest) | `npx vitest run tests/js/security` + full | ✅ | ✅ green |
| 06-07-01 | 07 | 3 | AUTH-09 | T-06-36, T-06-37, T-06-37b, T-06-38, T-06-39, T-06-40 | requirement switch and reset need admin + recent; transactional reset revokes sessions | feature | `dc ./vendor/bin/pest tests/Feature/Security/PolicyAndResetTest.php tests/Feature/Settings/MembersPageTest.php tests/Feature/Access/EndpointMatrixTest.php` | ✅ | ✅ green |
| 06-07-02 | 07 | 3 | AUTH-09, AUTH-10 | T-06-41 | requirement guides to setup; stale enrolment works only after in-place confirm | feature (integration) | `dc ./vendor/bin/pest tests/Feature/Security/RequirementGuidanceTest.php` | ✅ | ✅ green |
| 06-07-03 | 07 | 3 | AUTH-09 | T-06-36, T-06-37b | members UI: switch and reset confirm in place when stale | component (Vitest) | `npx vitest run tests/js/settings` + full | ✅ | ✅ green |
| 06-08-01 | 08 | 3 | AUTH-09 | T-06-44, T-06-45, T-06-46, T-06-47, T-06-48, T-06-48b, T-06-50 | passkey registration needs recent confirmation; safe failures; name validation; rp binding | unit + feature | `dc ./vendor/bin/pest tests/Unit/Security/PasskeyCeremonyTest.php tests/Feature/Security/PasskeyManagementTest.php` | ✅ | ✅ green |
| 06-08-02 | 08 | 3 | AUTH-09, AUTH-10 | T-06-42, T-06-43, T-06-49 | passkey challenge bound to login.id; reauth passkey; throttled | feature | `dc ./vendor/bin/pest tests/Feature/Security/PasskeyChallengeTest.php tests/Feature/Security/PasskeyReauthTest.php tests/Feature/Access/EndpointMatrixTest.php` + full | ✅ | ✅ green |
| 06-09-01 | 09 | 4 | AUTH-09 | T-06-61, T-06-64, T-06-64b | passkey names escaped; add/remove confirm in place when stale | component (Vitest) | `npx vitest run tests/js/security/PasskeysCard.test.js tests/js/security/PasskeyDialogs.test.js tests/js/security/SecurityPage.test.js` | ✅ | ✅ green |
| 06-09-02 | 09 | 4 | AUTH-09, AUTH-10 | T-06-62, T-06-63 | passkey on challenge and ReauthForm, generic errors | component (Vitest) | `npx vitest run tests/js/security` + full | ✅ | ✅ green |
| 06-10-01 | 10 | 3 | AUTH-09, AUTH-10 | T-06-51, T-06-52, T-06-53, T-06-54, T-06-55 | Google sign-in goes through the challenge; Google re-auth only flag-on and eligible, same subject | feature | `dc ./vendor/bin/pest tests/Feature/Security/GoogleSecondFactorTest.php tests/Feature/Security/GoogleReauthTest.php tests/Feature/Auth tests/Feature/Invites tests/Feature/Access/EndpointMatrixTest.php` | ✅ | ✅ green |
| 06-10-02 | 10 | 3 | AUTH-10 | T-06-54 | Google button only for eligible members with the flag on | component (Vitest) | `npx vitest run tests/js/security/ConfirmAccess.test.js` + full | ✅ | ✅ green |
| 06-11-01 | 11 | 3 | AUTH-10 | T-06-56, T-06-59 | every sensitive route gated (matrix replay); naming safety net with mutation check | feature | `dc php -d memory_limit=1G ./vendor/bin/pest tests/Feature/Security/SensitiveGateMatrixTest.php tests/Feature/Security/SignInToFinanceTest.php` | ✅ | ✅ green |
| 06-11-02 | 11 | 3 | AUTH-10 | T-06-60 | import wizard handles 423 without keeping the file | component (Vitest) | `npx vitest run tests/js/finance` + full | ✅ | ✅ green |
| 06-12-01 | 12 | 5 | AUTH-09, AUTH-10 | T-06-65, T-06-66, T-06-67 | canary scan of audit/logs; arch guarantees (incl. recent on enrolment and policy); no-store | arch + feature + component | `npx vitest run tests/js/settings/AuditSecurity.test.js tests/js/settings/AuditPage.test.js && dc ./vendor/bin/pest tests/Unit/Arch/SecurityArchTest.php tests/Feature/Security/SecurityCacheHeadersTest.php tests/Feature/Security/SecurityAuditContentTest.php` | ✅ | ✅ green |
| 06-12-02 | 12 | 5 | AUTH-09, AUTH-10 | T-06-66 | axe sweep SK/PL/EN light/dark; SW never caches security pages | component (Vitest) | `npx vitest run tests/js/security/a11y-security.test.js tests/js/security/sw-security.test.js` | ✅ | ✅ green |
| 06-12-03 | 12 | 5 | AUTH-09, AUTH-10 | T-06-68 | full run, build, ops notes, sign-off | full | `npx vitest run && npm run build && dc php -d memory_limit=1G ./vendor/bin/pest` + down -v | ✅ | ✅ green |
| 06-13-01 | 13 | 6 | AUTH-09, AUTH-10 | T-06-69, T-06-70, T-06-71, T-06-73 | staging release, rp id per environment, backfill counts | manual | manual (live server with owner) | n/a | ⬜ pending (deferred) |
| 06-13-02 | 13 | 6 | AUTH-09, AUTH-10 | T-06-70 | real-device factors, Google flag observation, owner confirmations | manual | manual (devices with owner) | n/a | ⬜ pending (deferred) |
| 06-13-03 | 13 | 6 | AUTH-09, AUTH-10 | T-06-72 | production via PR to main | manual | manual (PR, CI deploy) | n/a | ⬜ pending (deferred) |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

---

## Wave 0 Requirements

Test infrastructure (Pest, Vitest, axe helper, pseudoLong helper, endpoint matrix harness) already exists from Phases 1-5. Each task writes its own test file first (tdd="true"), so no separate Wave 0 plan is needed. Shared fixtures created inside the phase:

- [x] `tests/Support/Totp.php` (06-01 Task 1)
- [x] `tests/Support/AlwaysFreshSensitiveSession.php` and `TestCase::useRealSensitiveSession()` (06-02 Task 1)
- [x] `tests/Support/FakePasskeys.php` (06-08 Task 1)
- [x] `tests/Support/SensitiveRoutes.php` (06-11 Task 1)

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| Authenticator app setup and sign-in on a real phone | AUTH-09 | real authenticator app and device | 06-13 Task 2 step 1 |
| Passkeys on desktop, iPhone/iPad (incl. installed PWA), Android | AUTH-09 | real WebAuthn authenticators; tests use container fakes | 06-13 Task 2 step 2 |
| Idle expiry after 15 minutes on a real device | AUTH-10 | wall-clock wait on a real browser | 06-13 Task 2 step 3 |
| Google re-auth prompt/max_age behaviour (flag on in staging) | AUTH-10 | live Google behaviour [ASSUMED] | 06-13 Task 2 step 4 |
| Admin requirement and reset across devices | AUTH-09 | multi-device session revocation | 06-13 Task 2 step 5 |
| Enrolment re-auth after 11 minutes on a real device | AUTH-09 | wall-clock wait | 06-13 Task 2 step 7 |
| Owner confirmations (provisional allowlist, keepalive, Google flag, requirement, password_set backfill) | AUTH-09, AUTH-10 | owner decision | 06-13 Task 2 step 8 |

---

## Validation Sign-Off

- [x] All tasks have `<automated>` verify or Wave 0 dependencies (06-13 tasks are manual by design)
- [x] Sampling continuity: no 3 consecutive tasks without automated verify
- [x] Wave 0 covers all MISSING references
- [x] No watch-mode flags
- [x] Feedback latency < 06s
- [x] nyquist_compliant flag set in the frontmatter (06-12 Task 3)

**Approval:** signed off by 06-12 Task 3 (2026-11-14) for everything automatable. The 06-13 rows and the manual-only list stay pending until the live plan runs; AUTH-09 and AUTH-10 are ticked only after 06-13.

## Execution Notes (06-12 sign-off)

Final local run (06-12 Task 3): full Vitest 103 files / 2217 tests green, `npm run build` OK (SW-PRECACHE OK), full Pest 1591 tests green, stack torn down with `down -v`.

- All automated commands ran as written in the plans. Mid-phase red runs reported in the 06-04 to 06-11 summaries (duplicate `addPasskey()` helper, NotificationSettingsTest section order, axe flake while parallel plans edited files) were fixed by their owners; none remain.
- Files added or changed by deviations (all covered by the commands above): `app/Http/Middleware/EnsurePasskeysEnabled.php` (06-08), `resources/js/Components/Security/ReauthPrompt.vue` (06-06, covered by SecurityDialogs/PasskeyDialogs/PasskeysCard tests), `tests/js/settings/auditSecurityFixtures.js` (06-12), `bootstrap/app.php` (06-12: `NoStoreDynamicResponses` moved to the front of the web stack so 403/423/redirect refusals from the access rules are `no-store`; asserted by `SecurityCacheHeadersTest`).
- 06-12-02 coverage: 49 states x en/sk/pl x light/dark plus a pseudo-long run (393 tests), each state asserted to have reached its dialog or form.
- `password.confirm.store` is registered by Fortify but mapped to the `disabled` access rule (asserted in `SecurityArchTest` and the endpoint matrix), so no usable password-confirm route exists.
- Manual-only list (real passkeys on desktop and iPhone/iPad incl. installed PWA, authenticator app, Google re-auth with the flag on, idle expiry on a real device, admin reset across devices, owner confirmations) is unchanged and owned by 06-13.
