---
phase: 02-identity-and-authorization-core
plan: 08
subsystem: auth
tags: [sessions, revoke, remember-me, vue, inertia, audit]
requires:
  - phase: 02-04
    provides: SessionRevoker (revokeOne/revokeAll, remember token rotation)
  - phase: 02-06
    provides: MemberPanel tabs contract, audit events
provides:
  - Admin session list and revoke (one / all) per member, audited
  - Sessions tab in the member panel
affects: [phase-verification]
tech-stack:
  added: []
  patterns: [admin-only JSON endpoint fetched by a tab, manual member lookup (no route binding before access:admin)]
key-files:
  created:
    - app/Auth/SessionList.php
    - app/Support/UserAgentLabel.php
    - app/Http/Controllers/Settings/SessionsController.php
    - routes/web/sessions.php
    - resources/js/Components/Settings/SessionsTab.vue
    - tests/Support/EndpointMatrix/sessions.php
    - tests/Unit/UserAgentLabelTest.php
    - tests/Feature/Sessions/SessionListTest.php
    - tests/Feature/Sessions/SessionRevokeTest.php
    - tests/js/settings/SessionsTab.test.js
  modified:
    - resources/js/Components/Settings/MemberPanel.vue
key-decisions:
  - "revokeOne rotates remember_token, so the member's other devices lose their remember cookie (live sessions continue until expiry). Accepted: security over convenience."
  - "Member resolved with findOrFail in the controller, same as PermissionsController, so non-admins get 403 not 404."
requirements-completed: [AUTH-04]
duration: 40min
completed: 2026-11-13
---

# Phase 2 Plan 08: Sessions tab Summary

Admins can list a member's signed-in devices and revoke one or all sessions; revoke deletes session rows and rotates the remember token, and every revoke is audited.

## Commits
- 56b06d2 feat(02-08): session listing and audited revoke endpoints
- acd5f32 feat(02-08): Sessions tab in the member panel

## What was built
- `SessionList` maps id, browser, os, mobile, ip, lastActivity (ISO), isCurrent, staysSignedIn; payload is decoded with `allowed_classes => false` only for the `auth_remember` flag and never returned. Always scoped `where('user_id', ...)`.
- `UserAgentLabel` regex parser (no package).
- `SessionsController`: index (JSON), destroy (404 when the id does not belong to the member), destroyAll; audit `session_revoked` (meta device) and `session_revoked_all`; 303 back with toast `sessions.revoked_toast`.
- `SessionsTab.vue` per UI-SPEC screen 8: skeleton, error state with retry, empty state, rows with icon/tags/relative time/mono IP, per-row Revoke with aria-label, confirm dialogs, Revoke all hidden when only the current session exists. Added as second tab in `MemberPanel`.

## Tests
- Pest: UserAgentLabel, SessionList, SessionRevoke (incl. old remember cookie cannot restore a revoked member), matrix rows for the three routes (51 targeted tests pass).
- Vitest: SessionsTab (9 tests, axe in en/sk/pl, light and dark). Build prints SW-PRECACHE OK.

## Deviations from Plan
- **[Test adaptation]** The plan's end-to-end case "old session cookie lands on /login with session_ended" is not duplicated: with the array session driver forced by phpunit.xml, `config(['session.driver'=>'database'])` after boot has no effect, and plan 03's `ForbiddenPageTest` already covers the flash. The remember-cookie half is tested (recaller cookie via `withCookie`, before and after revoke).
- **[Matrix]** Matrix expectations: destroy as admin is 404 (fixture has no such session row), destroy_all as admin is 303 (back redirect), guest 302.
- Current-session test uses `get()` with Accept header because `getJson()` does not send cookies.

## Issues / out-of-scope
At the final full run, failures came from plan 07's in-progress files, not this plan: `tests/js/settings/Invitations.test.js` and `tests/js/ui/overlays.test.js` (caused by plan 07's uncommitted edit to `UiDialog.vue`), and Pint style issues in plan 07 test files plus `routes/console.php`. Full Pest (225) and the build pass. My files are Pint clean. The final full run should be repeated after plan 07 lands.

Human check still open: revoke a "Stay signed in" phone session from the desktop and confirm the phone lands on sign-in with "You were signed out".

## Self-Check: PASSED
