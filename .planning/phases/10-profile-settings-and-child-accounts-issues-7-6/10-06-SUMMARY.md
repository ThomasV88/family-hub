---
phase: 10-profile-settings-and-child-accounts-issues-7-6
plan: 06
subsystem: child-accounts
tags: [child-account, invitation, conversion, members-ui, help, manual, i18n]
requires: ["10-05"]
provides:
  - "POST /settings/members/{user}/convert (settings.members.children.convert): admin + recent auth, throttled, listed in SensitiveRoutes::accessGranting()"
  - "InviteService::create(..., ?int childUserId) and a same-row accept branch for invitations with user_id"
  - "AuditEvent::ChildConverted (child_converted, group account)"
  - "Members payload: invitation {id, email, expiresAt} on a child row with an open conversion invitation"
  - "ChildConvertCard, Invited tag on child row and panel"
affects: [10-08]
key-files:
  created:
    - app/Http/Requests/Settings/ConvertChildRequest.php
    - tests/Feature/Invites/ChildConversionTest.php
    - resources/js/Components/Settings/ChildConvertCard.vue
  modified:
    - app/Models/Invitation.php
    - app/Auth/InviteService.php
    - app/Http/Controllers/Settings/ChildrenController.php
    - app/Http/Controllers/Settings/MembersController.php
    - app/Audit/AuditEvent.php
    - routes/web/settings-children.php
    - tests/Support/EndpointMatrix/settings-children.php
    - tests/Support/SensitiveRoutes.php
    - tests/Feature/Settings/MembersPageTest.php
    - resources/js/Components/Settings/MemberPanel.vue
    - resources/js/Components/Settings/MemberRow.vue
    - resources/js/Components/Settings/AuditTable.vue
    - lang/en.json
    - lang/sk.json
    - lang/pl.json
    - resources/help/topics.json
    - resources/help/en.json
    - resources/help/sk.json
    - resources/help/pl.json
    - resources/manual/en/admin.json
    - resources/manual/sk/admin.json
    - resources/manual/pl/admin.json
    - tests/js/settings/MembersPage.test.js
metrics:
  tasks: 2
  completed: 2026-11-17
---

# Phase 10 Plan 06: Convert a child to a normal member Summary

An admin with a recent re-authentication invites a child to an e-mail address from the child's panel. Accepting the invitation (password or Google) fills the same `users` row, so wish list, tasks, grants and parents stay and no second account appears. Until acceptance the child still cannot sign in, and revoking the invitation changes nothing.

## Commits
- 4517aa2 conversion endpoint, same-row accept branch under locks, audit event, Members payload, tests (`Refs #6`)
- 68c23f9 Make a normal member card, Invited tag, audit label, help tip and manual section (`Fixes #6`)

## Tests
Isolated compose project `fh1006` (own Postgres, scratch folder over storage/logs), serial, removed with `down -v` (no containers left). Pint on touched PHP: pass. ChildConversionTest: 11 tests (same row, `User::count()` unchanged, grants/wish/task/manager rows kept, Google path, revoke, taken e-mail in any case, 404 adult and unknown, 403 non-admin, stale admin 423, one open invite per child, no longer a child, unique-index race, lock statements). Pest batch Invites, Auth, Settings, EndpointMatrix, SensitiveGateMatrix, Audit: 457 passed (10-02 lockout tests included). Full Vitest: 152 files, 5166 tests passed (manual, help coverage, a11y sweep, axe for the card with a pending invitation in en/sk/pl, light and dark, pseudo-long text). Full serial Pest suite: 2929 passed, 1 skipped.

## User documentation
Manual: new section `child-convert` in `admin.json` (same id, 4 steps and 5 list items in en, sk, pl). Help tip `members.convert` (screen `members_child`, area settings) on the e-mail field; the screen label was reworded to "Members, child dialog and panel" in the three help files because the tip now sits in the panel too.

Touched `resources/manual/` files, sk and pl are drafts for native review:
- resources/manual/en/admin.json, resources/manual/sk/admin.json, resources/manual/pl/admin.json

Also for native review: `resources/help/sk.json`, `pl.json` (members.convert, members_child label), and `settings.members.convert.*` plus `audit.detail.child_converted` in `lang/sk.json` and `lang/pl.json`.

## Deviations from Plan
- **Signature.** `InviteService::create` already took `User $admin` first, so the new argument is appended: `create(User $admin, string $email, Preset $preset, string $locale, ?int $childUserId = null)`.
- **Race error mapping.** A lost unique-index race (or any `UniqueConstraintViolationException` in accept) is mapped to `InvalidInviteException`, which both accept entry points (password form, Google) already handle (invite shows as no longer valid, nothing written, no 500). A `ValidationException` was not used because the accept page has no e-mail field and the Google path would not catch it. The test simulates the race on the same connection (a rival row is inserted between the uniqueness check and the write), since two real connections cannot be used under RefreshDatabase. The normal (no `user_id`) accept path gets the same protection, its behaviour is otherwise unchanged.
- **One open invitation per child.** Creating a conversion invitation revokes an older open one for the same child (audited as `invite_revoked`), so an address change does not leave two live links.
- **Name on accept.** The accept form's name replaces the child's name (the accepter types it), everything else on the row stays.
- **No link handoff.** The convert response flashes only a toast ("Invitation sent to {email}"); the link is not flashed. Copy link and Resend stay available from the invitation list.
- **Card markup.** `ChildConvertCard` is a `section` with an `h3`, not `UiCard`: `UiCard` renders a `header` that axe reports as a second banner landmark inside the panel.
- **Existing test touched.** `MembersPageTest` member-row key list gained `invitation` (plan-mandated payload field).
- **Process slip, restored.** I ran `git stash` once by mistake while checking a failing test, then immediately `git stash pop`; the stash list is empty and every change was restored intact (verified by `git status` and the later green test runs).

## Known Stubs
None.

## Threat Flags
None beyond the plan's threat model. T-10-31 (admin + recent, `accessGranting()` + SensitiveGateMatrixTest, 404 for non-children), T-10-32 (invitation and user row `lockForUpdate`, state re-check, flag cleared only in the transaction), T-10-33 (case-insensitive check, unique index, mapped violation, race test), T-10-35 (`invite_created`, `child_converted` rows) are covered by tests.

## Self-Check: PASSED
Commits 4517aa2 and 68c23f9 and all created files verified present.
