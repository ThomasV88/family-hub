---
phase: 06-sensitive-data-account-hardening
plan: 01
subsystem: auth
tags: [fortify, passkeys, totp, audit, settings]
requires: []
provides:
  - users two-factor columns, passkeys table, app_settings table, users.password_set (with audit-derived backfill)
  - User::hasSecondFactor(), PasskeyUser contract, googleOnly() factory state
  - 11 security AuditEvent cases (group 'security') and RecordSecurityEvents listener
  - App\Security\AppSettings, SecondFactorPolicy, PasswordSetBackfill
  - tests/Support/Totp helper
affects: [06-02, 06-03, 06-05, 06-10, 06-13]
tech-stack:
  added: []
  patterns: [auto-discovered handle* listeners, per-instance memoised settings store]
key-files:
  created:
    - database/migrations/2026_11_17_100000_add_two_factor_columns_to_users_table.php
    - database/migrations/2026_11_17_100001_create_passkeys_table.php
    - database/migrations/2026_11_17_100002_create_app_settings_table.php
    - database/migrations/2026_11_17_100003_add_password_set_to_users_table.php
    - app/Listeners/RecordSecurityEvents.php
    - app/Security/AppSettings.php
    - app/Security/SecondFactorPolicy.php
    - app/Security/PasswordSetBackfill.php
    - tests/Support/Totp.php
    - tests/Feature/Security/SecondFactorModelTest.php
    - tests/Feature/Security/SecurityAuditEventsTest.php
    - tests/Feature/Security/SecondFactorPolicyTest.php
  modified:
    - app/Models/User.php
    - database/factories/UserFactory.php
    - app/Audit/AuditEvent.php
decisions:
  - hasSecondFactor() checks secret AND confirmed_at explicitly instead of Fortify's hasEnabledTwoFactorAuthentication(), because the latter ignores confirmed_at while the Fortify confirm option is off (features are enabled only in 06-05)
metrics:
  tasks: 3
  completed: 2026-11-13
---

# Phase 6 Plan 01: Second-factor data and audit foundation Summary

Two-factor and passkey storage, an app_settings table, a password_set flag with audit-derived backfill, 11 content-free security audit events with an auto-discovered Fortify/passkey listener, and the second-factor policy service; no Fortify feature enabled, so behaviour is unchanged.

## Commits
- 3b92a7b: migrations, User contracts, factory, Totp helper, model tests
- 764c80f: audit events and RecordSecurityEvents listener
- 7da6fd5: AppSettings, SecondFactorPolicy and tests

## Deviations from Plan

**1. [Rule 1 - Bug] hasSecondFactor() independent of Fortify confirm option**
- **Issue:** Fortify's hasEnabledTwoFactorAuthentication() counts a pending secret as enabled when the confirm feature is off (currently off), failing the pending-secret requirement and T-06-05.
- **Fix:** hasSecondFactor() tests `two_factor_secret` and `two_factor_confirmed_at` directly.
- **Files:** app/Models/User.php

**2. Test adjustments (no production impact)**
- audit_logs is append-only, so tests compare against the latest row id instead of deleting rows.
- Fortify rejects replayed TOTP codes, so Totp::code() needs a different `$offset` for a second verify in the same 30s window (documented in the helper).
- Test helper named secondFactorPolicy() because Pest/Laravel already define policy().

## Notes for later plans
- AppSettings and SecondFactorPolicy rely on auto-resolution; the `scoped` bindings belong in 06-02's SecurityServiceProvider as planned.
- PasswordSetBackfill assumes audit_logs has never been pruned (owner confirms in 06-13).

## Known Stubs
None.

## Self-Check: PASSED
Full PHP suite: 1405 passed. Files and commits verified present.
