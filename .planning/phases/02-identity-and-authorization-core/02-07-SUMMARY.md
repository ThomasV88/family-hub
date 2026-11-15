---
phase: 02-identity-and-authorization-core
plan: 07
subsystem: auth
tags: [laravel, invitations, queue, notifications, inertia, vue]
requires: [02-06]
provides:
  - invitations table (sha256 token_hash, partial unique index invitations_one_open) and Invitation model (state(), findByToken())
  - InviteService create/resend/rotateLink/revoke, audited, plain token returned once
  - Queued InviteNotification with failed() hook setting mail_failed_at
  - POST/DELETE /settings/invitations routes (access:admin, throttle 30/min), one-shot flash_data.invite handoff
  - Members page invitations prop, InvitationRow, InviteDialog, Invitations card
affects: [02-09]
tech-stack:
  added: []
  patterns: [flash_data one-shot secret handoff, numeric route ids resolved in controller after access:admin]
key-files:
  created:
    - database/migrations/2026_11_13_130000_create_invitations_table.php
    - app/Models/Invitation.php
    - app/Auth/InviteService.php
    - app/Notifications/InviteNotification.php
    - app/Http/Requests/Settings/StoreInvitationRequest.php
    - app/Http/Controllers/Settings/InvitationsController.php
    - routes/web/invitations.php
    - tests/Support/EndpointMatrix/invitations.php
    - tests/Feature/Invites/{InviteManagementTest,InviteMailTest}.php
    - resources/js/Components/Settings/{InvitationRow,InviteDialog}.vue
    - tests/js/settings/Invitations.test.js
  modified:
    - app/Http/Controllers/Settings/MembersController.php
    - resources/js/Pages/Settings/Members.vue
    - resources/js/Components/ui/UiDialog.vue
    - ops/env/app.env.example
    - tests/Feature/Settings/MembersPageTest.php
    - tests/js/settings/MembersPage.test.js
    - tests/js/ui/overlays.test.js
decisions:
  - "Invitation routes take numeric ids resolved with findOrFail in the controller (same as plan 06), so non-admins get 403 not 404"
  - "Admin responses are 303 (explicit back(303)) so Inertia PUT/DELETE/POST redirects work; matrix rows expect 303"
  - "Resend/link/revoke on a non-open invitation returns 409 with no change"
  - "Invite dialog defaults the starting preset to Child (least privilege) and language to the admin's page locale"
metrics:
  tasks: 2
  completed: 2026-11-13
---

# Phase 2 Plan 07: Invitations Summary

The admin invites a member by email with a starting preset and language, gets a copyable link, and can resend, re-link or revoke pending invites; only token hashes are stored and every action is audited.

## Tasks

| Task | Commit | Notes |
|------|--------|-------|
| 1 Table, service, queued mail, endpoints | 6f2622d | Pest filtered tests green incl. "plain token appears nowhere in the database" |
| 2 Invite dialog and Invitations card | 4b2ac97 | Vitest Invitations.test.js incl. axe en/sk/pl light/dark |
| Follow-up | (style commit) | Pint fixes, overlays test selector |

## Verification

- Final run: Pest 225 passed, Vitest 384 passed, `npm run build` prints SW-PRECACHE OK, `scripts/check-secrets.sh` OK. Pint clean except pre-existing `routes/console.php`. Compose project `fh0207` removed.
- Acceptance greps met: invitations_one_open, hash('sha256'), ShouldQueue 1, mail_failed_at, `MAIL_MAILER=log` in ops/env/app.env.example 1, aria keys 3 in InvitationRow, rotated_note in InviteDialog.
- Human check at end of phase (invite as Child in Slovak, copy, resend, revoke) not performed here.

## Deviations from Plan

**1. [Rule 1 - Bug] Route model binding replaced by controller lookup**
- Binding runs before access:admin and would 404 non-admins instead of 403. Routes use `whereNumber` and `findOrFail` in the controller.

**2. [Rule 1 - Bug] InviteNotification property name**
- `$locale` collides with `Notification::$locale`; renamed `$inviteLocale`.

**3. [Rule 3 - Blocking] UiDialog landmarks**
- With a dialog open on a page with the top bar, axe reported duplicate banner landmarks because UiDialog used `<header>`/`<footer>`. They are now `<div>`s (same classes); `tests/js/ui/overlays.test.js` selector updated.

**4. [Rule 3 - Blocking] InviteDialog initial focus**
- UiInput's root is a wrapper, so the focus trap received the wrapper; the dialog now passes the inner `<input>`.

**5. [Minor] Beyond plan**
- `.env.example` already had `MAIL_MAILER=log`; unchanged. `MembersPage.test.js` assertion flipped to expect the "Invite member" button.
- Default starting preset is Child rather than the first preset key (Parent).

## Auth gates

None.

## Known Stubs

None. `/invite/{token}` (invite.show) is registered by plan 09; until then the link 404s.

## Threat Flags

None beyond the plan's threat model (T-02-35, T-02-37 to T-02-41 mitigated and tested; T-02-36 accepted).

## Notes

- The full Pest run once failed on plan 02-08's `settings.members.sessions.destroy_all` matrix row (302 vs 303) while its files were half-written; it passed on re-run.

## Self-Check: PASSED
