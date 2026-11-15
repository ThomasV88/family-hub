---
phase: 02-identity-and-authorization-core
plan: 09
subsystem: auth
tags: [laravel, socialite, invitations, google, inertia, vue]
requires: [02-07]
provides:
  - InviteService::accept (transaction, lockForUpdate, state re-check, preset grants, audit) and InvalidInviteException
  - GET/POST /invite/{token} (invite.show, invite.accept) with locale cookie handoff and AcceptInvite page
  - GoogleLoginService (invite flow, match by google_id, link once by verified email, deny) and GoogleController redirect/callback
  - Google buttons on Login and AcceptInvite, shown only when GOOGLE_CLIENT_ID and GOOGLE_CLIENT_SECRET are set
  - tests/Support/FakeGoogle (Socialite faked, no real Google calls)
affects: [02-10]
tech-stack:
  added: []
  patterns: [server-side invite token in session during OAuth round trip, status flash for Google outcomes]
key-files:
  created:
    - app/Auth/GoogleLoginService.php
    - app/Auth/InvalidInviteException.php
    - app/Http/Controllers/Auth/InviteAcceptController.php
    - app/Http/Controllers/Auth/GoogleController.php
    - app/Http/Requests/AcceptInvitationRequest.php
    - routes/web/invite.php
    - routes/web/google.php
    - resources/js/Pages/Auth/AcceptInvite.vue
    - tests/Support/FakeGoogle.php
    - tests/Support/EndpointMatrix/{invite,google}.php
    - tests/Feature/Invites/InviteAcceptTest.php
    - tests/Feature/Auth/GoogleLoginTest.php
    - tests/js/auth/AcceptInvite.test.js
  modified:
    - app/Auth/InviteService.php
    - app/Providers/FortifyServiceProvider.php
    - config/services.php
    - .env.example
    - ops/env/app.env.example
    - resources/js/Pages/Auth/Login.vue
    - tests/js/auth/Login.test.js
decisions:
  - "Google outcomes travel as the flash status (google_failed, google_not_invited, google_mismatch); the invite page reads google_mismatch from the same status channel"
  - "Invite token for the Google round trip lives in session key google_invite_token and is pulled (removed) at the callback; if it no longer resolves to an invitation the callback fails closed with google_failed"
  - "A revoked or expired invite during the Google round trip yields google_failed, never the login-page branches"
metrics:
  tasks: 2
  completed: 2026-11-13
---

# Phase 2 Plan 09: Invitee Joins Summary

The invite link opens a registration page (name and password, or Continue with Google when the Google email is verified and equals the invited address), existing members can sign in with Google, and no account is ever created from Google without an invite link.

## Tasks

| Task | Commit | Notes |
|------|--------|-------|
| 1 Invite accept with password, race-safe, AcceptInvite page | 2dd03c0 | Pest InviteAccept (10 tests incl. double-accept) and Vitest AcceptInvite incl. axe en/sk/pl light/dark |
| 2 Google sign-in, invite-by-Google, Google buttons | 9329e3f | Pest GoogleLogin (23 cases incl. unverified email in every branch, no token persisted), Login Vitest additions |

## Verification

- Full Pest 259 passed, Vitest 397 passed, `npm run build` prints SW-PRECACHE OK, Pint clean (pre-existing `routes/console.php` ordering issue left untouched, as in plan 07). Compose project `fh0209` removed.
- Acceptance greps: lockForUpdate, regenerate(), no-referrer in invite routes, email_verified in GoogleLoginService, 0 token references in Google files, `GOOGLE_CLIENT_ID=` empty in ops/env/app.env.example.
- Endpoint matrix rows added: invite.show, invite.accept, auth.google.redirect, auth.google.callback. Checker warning applied: auth.google.redirect carries `no-referrer` (callback does not, per contract).
- Human check (accept with Google on a phone, mismatch, login-page Google) deferred until plan 10 configures Google on staging.

## Deviations from Plan

**1. [Rule 2 - Security] Fail closed when the invite token in session no longer resolves**
- If the session holds an invite token whose invitation is gone or revoked at callback time, the callback returns google_failed instead of falling through to the login-page branches (which could sign in an existing member under an invite context).
- Files: app/Http/Controllers/Auth/GoogleController.php. Tested ("fails when the invite was revoked during the Google round trip").

**2. [Minor] Invite route has no token character constraint**
- Unknown or odd tokens render the shared "cannot be used" state (same as revoked) instead of a 404, matching the plan's "same copy for revoked and unknown".

**3. [Minor] AcceptInvite.vue committed with Task 1 already contains the Google block**
- It is inert until `googleEnabled` is true; the Google backend and tests landed in Task 2.

## Auth gates

None (Google faked; real credentials and consent screen are plan 10).

## Known Stubs

None.

## Threat Flags

None beyond the plan's threat model (T-02-47 to T-02-53 and T-02-55 mitigated and tested; T-02-54 accepted).

## Requirements

AUTH-03 (Google by verified identity, already ticked) and AUTH-06 (preset applied at accept) are fully delivered. AUTH-04 is now fully delivered (persistent DB sessions plan 04, admin revoke plan 08) and ticked. AUTH-01 and AUTH-08 are left unticked: AUTH-01 real email delivery (invite mail on log mailer in staging) and AUTH-08 append-only DB privileges and 2-year prune are plan 10 scope.

## Self-Check: PASSED
All created files exist; commits 2dd03c0 and 9329e3f present.
