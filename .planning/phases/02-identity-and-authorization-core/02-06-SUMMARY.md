---
phase: 02-identity-and-authorization-core
plan: 06
subsystem: auth
tags: [laravel, permissions, audit, inertia, vue]
requires: [02-05]
provides:
  - PermissionService::save (one transaction, one permission_changed audit row per changed module, AccessGate::forget)
  - GET /settings/members and PUT /settings/members/{user}/permissions (access:admin)
  - Settings/Members page, MemberRow, MemberPanel (tabs array in one computed), PermissionsTab, PermissionSelect, PermissionMatrix
  - SettingsLayout forwards a `panel` slot to AppShell
affects: [02-07, 02-08, 02-09]
tech-stack:
  added: []
  patterns: [grant table single-sourced on the server and passed as props, audit write inside the grant transaction]
key-files:
  created:
    - app/Access/PermissionService.php
    - app/Http/Requests/Settings/UpdatePermissionsRequest.php
    - app/Http/Controllers/Settings/MembersController.php
    - app/Http/Controllers/Settings/PermissionsController.php
    - routes/web/settings-members.php
    - tests/Support/EndpointMatrix/settings-members.php
    - tests/Feature/Settings/{MembersPageTest,PermissionSaveTest}.php
    - resources/js/Pages/Settings/Members.vue
    - resources/js/Components/Settings/{MemberRow,MemberPanel,PermissionsTab,PermissionSelect,PermissionMatrix}.vue
    - resources/js/Settings/presets.js
    - tests/js/settings/{MembersPage,PermissionsTab}.test.js
  modified:
    - resources/js/Layouts/SettingsLayout.vue
    - tests/Feature/Audit/AuditPageTest.php
decisions:
  - "PUT route takes a numeric {user} resolved in the controller (findOrFail), not by route model binding, because binding runs before access:admin and would 404 non-admins instead of 403"
  - "Matrix PUT row expects 302 for admin (a non-Inertia PUT redirect is 302; Inertia clients get 303)"
metrics:
  tasks: 2
  completed: 2026-11-13
---

# Phase 2 Plan 06: Members, permissions editor and matrix Summary

The admin lists members, applies a preset into the selects (nothing saved), adjusts any module to None/View/Edit and saves in one atomic request that writes one audited row per changed module, with a read-only matrix tab.

## Tasks

| Task | Commit | Notes |
|------|--------|-------|
| 1 Atomic audited save and members endpoints | e59542f | Pest 39 filtered tests green incl. rollback on audit failure |
| 2 Members page, panel, Permissions tab, matrix | b14f3e7 | Vitest 360, Pest 188, build SW-PRECACHE OK |

## Verification

- Pest 188 passed; Vitest 360 passed; `npm run build` prints SW-PRECACHE OK; Pint clean except pre-existing `routes/console.php`; compose project `fh0206` removed with `down -v`.
- Acceptance greps: `DB::transaction` 1, `forget(` present, secrets in MembersController 0, `permissions.apply_label` 2 in PermissionsTab, `manage_aria` 1 in MemberRow, preset keys in presets.js 0.
- Tests cover: only changed modules audited, unchanged writes nothing, missing/unknown/extra keys rejected, audit-failure rollback, next request 403 after downgrade, admin may grant self health view, 404 for admin and 403 for non-admins on unknown id, axe in en/sk/pl light and dark, pseudo-long names.
- Human check at end of phase (apply Child preset, change Wishlist to View, save, see toast, matrix and audit entry) not performed here.

## Deviations from Plan

**1. [Rule 1 - Bug] Route model binding ran before the access check**
- Unknown id as non-admin returned 404, not 403. Route uses `whereNumber('user')` and the controller calls `findOrFail`. Commit e59542f.

**2. [Rule 3 - Blocking] Placeholder page in task 1 commit**
- The Members page test needs the Vue component to exist; task 1 committed a minimal page, replaced in task 2.

**3. [Minor] Beyond plan**
- `SettingsLayout` now forwards a `panel` slot so the docked member panel sits in the shell body.
- `AuditPageTest` section count 2 to 3 (Members section now appears automatically).

## Auth gates

None.

## Known Stubs

None. The Sessions tab is added by plan 08 and Invite member / Invitations by plan 07, as planned.

## Threat Flags

None beyond the plan's threat model (T-02-30 to T-02-34 mitigated and tested).

## Requirements

AUTH-05 and AUTH-06 are delivered in full (per-member per-module editor, presets applied then adjustable) and ticked. AUTH-08 stays open until invite and session events are emitted by plans 07 to 09.

## Self-Check: PASSED

Files exist; commits e59542f and b14f3e7 present.
