---
phase: 06-sensitive-data-account-hardening
plan: 07
subsystem: auth
tags: [admin, two-factor, policy, reset, members-panel, inertia]
requires: [06-01, 06-04, 06-05]
provides:
  - PUT settings.security.policy (access:admin + access:recent), audited requirement switch
  - DELETE settings.members.second_factor.destroy (transactional reset with session revoke and audit)
  - Members page props security / securityPolicy / confirmFresh / methods
  - SecurityRequirementCard, MemberSecurityTab, factor tags in MemberRow, Security tab in MemberPanel
requirements-completed: [AUTH-09]
key-files:
  created:
    - app/Http/Controllers/Settings/SecurityPolicyController.php
    - app/Http/Controllers/Settings/MemberSecondFactorController.php
    - routes/web/settings-security-admin.php
    - tests/Support/EndpointMatrix/settings-security-admin.php
    - resources/js/Components/Settings/SecurityRequirementCard.vue
    - resources/js/Components/Settings/MemberSecurityTab.vue
    - tests/Feature/Security/PolicyAndResetTest.php
    - tests/Feature/Security/RequirementGuidanceTest.php
    - tests/js/settings/MembersSecurity.test.js
  modified:
    - app/Http/Controllers/Settings/MembersController.php
    - resources/js/Pages/Settings/Members.vue
    - resources/js/Components/Settings/MemberRow.vue
    - resources/js/Components/Settings/MemberPanel.vue
completed: 2026-11-14
---

# Phase 6 Plan 07: Admin requirement switch, factor status and reset Summary

The admin can require two-step verification for Health/Finances members (fresh-window gated, confirmed in place when stale, audited), sees per-member factor status, and can reset a member's authenticator and passkeys in one transaction that also revokes the member's sessions; the whole guidance loop is proven through the real sensitive gate.

## Commits
- b6ce3cc: policy and reset endpoints, routes, matrix rows, members props, PolicyAndResetTest
- 6693854: RequirementGuidanceTest (end to end incl. stale-session path)
- b39d4d7: requirement card, member tags, Security tab, reset dialog, Vitest
- 9410854: unique Pest helper names (a global-function clash with SecondFactorModelTest broke the full run)

## Behaviour
- Policy: strict boolean, setting plus `security_policy_changed` row in one transaction; stale JSON caller gets 423 `confirm_required` with nothing saved (both directions tested).
- Reset: own row 403, no factor 422 `no_factor`, non-numeric id 404; transaction nulls two_factor_* columns, deletes passkey rows by query, `SessionRevoker::revokeAll`, audits `second_factor_reset` (actor and target, no meta). A throwing revoker rolls everything back (tested).
- Members props are booleans and counts only (test scans for `two_factor_` and `credential_id`); passkey counts come from one grouped query.
- UI: the switch reverts and opens a "Confirm it's you" dialog on a stale window or a 423, then retries after `confirmed`; reset dialog shows `ReauthForm` only when stale, re-shows it after a stale refusal, safe button has initial focus; no-method admins get the sign-in-again button plus setup link. Nothing navigates to /confirm-access.

## Deviations from Plan
- **Test technique:** the array session driver cannot drop a revoked browser session, so "old session is gone" is modelled by asserting the sessions rows are deleted and then issuing a request without a session (redirects to /login).
- **Own copy of the no-method block:** 06-06's `ReauthPrompt.vue` exists in the tree but belongs to a parallel plan, so both new components render `ReauthForm` plus their own no-method markup instead of depending on it. A later cleanup could switch to `ReauthPrompt`.
- **"Needs two-step" tag replaces the on/off tag** (instead of sitting beside it) since needs implies off.
- No production change was needed for Task 2.

## Verification
- Task tests: PolicyAndResetTest (11), RequirementGuidanceTest (8), MembersPageTest, EndpointMatrixTest, MembersSecurity Vitest (21) pass.
- Full Vitest: 97 files, 1730 tests pass.
- Full PHP: 1575 pass, 1 failure, not in my files: `NotificationSettingsTest` "lists sections in order for admins" expects `sections.4` = appearance but 06-06's uncommitted `SettingsSections` change inserts `security`. Re-run once, same result. 06-06 (or whoever owns SettingsSections/that test) must update the expectation.

## Known Stubs
None.

## Threat Flags
None beyond the plan's threat model (T-06-36..40 mitigated and covered by tests).

## Self-Check: PASSED
Files and the four commits verified present.
