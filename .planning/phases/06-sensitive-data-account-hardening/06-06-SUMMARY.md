---
phase: 06-sensitive-data-account-hardening
plan: 06
subsystem: auth
tags: [settings, totp, recovery-codes, dialogs, inertia, vue]
requires: [06-03, 06-04, 06-05]
provides:
  - Settings > Security section for every member (GET /settings/security, POST /settings/security/continue)
  - Security page with status, authenticator card, recovery card, guidance state and an empty passkeys region
  - TwoFactorSetupDialog (confirm, scan, verify, codes), RecoveryCodesPanel, TotpDisableDialog, RegenerateCodesDialog, ReauthPrompt
affects: [06-09]
key-files:
  created:
    - app/Http/Controllers/Security/SecuritySettingsController.php
    - routes/web/settings-security.php
    - tests/Support/EndpointMatrix/settings-security.php
    - resources/js/Pages/Settings/Security.vue
    - resources/js/Components/Security/TwoFactorSetupDialog.vue
    - resources/js/Components/Security/RecoveryCodesPanel.vue
    - resources/js/Components/Security/TotpDisableDialog.vue
    - resources/js/Components/Security/RegenerateCodesDialog.vue
    - resources/js/Components/Security/ReauthPrompt.vue
    - tests/Feature/Security/SecurityPageTest.php
    - tests/js/security/SecurityPage.test.js
    - tests/js/security/TwoFactorSetupDialog.test.js
    - tests/js/security/SecurityDialogs.test.js
  modified:
    - app/Support/SettingsSections.php
    - resources/js/Layouts/SettingsLayout.vue
    - resources/js/Components/ui/UiDialog.vue
    - tests/Feature/Settings/SettingsShellTest.php
    - tests/Feature/Settings/MembersPageTest.php
    - tests/Feature/Audit/AuditPageTest.php
    - tests/Feature/Finance/AccountAdminTest.php
    - tests/Feature/Notifications/NotificationSettingsTest.php
requirements-completed: [AUTH-09]
completed: 2026-11-14
---

# Phase 6 Plan 06: Settings > Security (authenticator, recovery codes, guidance) Summary

Members can set up, use, regenerate and turn off the authenticator app from Settings > Security, confirming inside the dialog when the 10-minute security window is stale, with secrets held only in component-local state.

## Commits
- 5200f27: controller, routes, section entry, matrix rows, SecurityPageTest, section-count test updates
- 996254c: Security page, setup dialog, recovery panel, ReauthPrompt, UiDialog `dismissable`, layout icon
- e959b72: turn-off and new-codes dialogs wired into the page, SecurityDialogs test
- 8bdfb58: NotificationSettingsTest section list

## Behaviour
- Page props are explicit: booleans, a recovery count (null when TOTP off), passkey display fields (id, name, authenticator, createdAt, lastUsedAt), methods, confirmFresh. A test scans the full Inertia response for the secret, codes, `two_factor_` and credential ids.
- Setup: opens on a confirm step when `confirmFresh` is false; a 423 on enable re-shows the confirm step and retries enable; a 423 on confirm shows ReauthForm in place, keeps the typed code and retries it. No-method members see `reauth.no_method` plus Sign in again (admins also the setup link). Never navigates to /confirm-access. QR and key are v-if-removed on leaving step 1; Cancel at steps 1-2 sends DELETE; step 3 is non-dismissable with beforeunload; Finish wipes state, calls `router.clearHistory`, toasts, and the page either continues (guidance, POST /settings/security/continue) or reloads props.
- Turn-off and new-codes dialogs call the endpoint directly; a 423 shows ReauthPrompt in place and retries after `confirmed`.

## Deviations from Plan

**1. [Rule 3 - Blocking] Existing tests assumed the old section list**
- Admin section counts in MembersPageTest, AuditPageTest, AccountAdminTest and the section order in NotificationSettingsTest changed with the new section. Updated the expectations (commits 5200f27, 8bdfb58). The notifications one only surfaced in the full run.

**2. [Rule 3 - Blocking] UiDialog had no way to be non-dismissable**
- Added an optional `dismissable` prop (default true; hides the close button and ignores Esc/scrim). Additive, existing dialogs unchanged. Needed for the codes step (UI-SPEC Screen 2/3/6).

**3. Extra files beyond the plan list:** `ReauthPrompt.vue` (shared in-dialog form or no-method block, used by all three dialogs) and the `shield-check` icon mapping in SettingsLayout.vue (otherwise the new nav entry rendered no icon).

**4. Discretion:** the dialogs show the generic `error.generic.body` copy on unexpected failures (no new copy keys added, lang files untouched).

## Verification
- PHP full suite: 1575 passed, 1 failed (NotificationSettingsTest, mine, fixed; targeted re-run of Notifications, Settings and SecurityPageTest: 120 passed).
- Vitest full: first run had 3 failing files (44 tests, axe and settings pages) while parallel plans were editing; re-run once: 98 files, 1747 tests all pass.
- Acceptance greps: no `visit('/confirm-access` in Security components; `clearHistory` present in both the setup dialog and the page; `ReauthForm` reached through ReauthPrompt (the setup dialog uses ReauthPrompt, which wraps ReauthForm); `credential` count in the controller is 0.

## Known Stubs
None. The `passkeys-region` section on the page is an intentionally empty mount point for 06-09.

## Threat Flags
None beyond the plan's threat model.

## Self-Check: PASSED
