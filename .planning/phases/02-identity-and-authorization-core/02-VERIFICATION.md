---
phase: 02-identity-and-authorization-core
verified: 2026-11-13T00:00:00Z
status: human_needed
score: 5/5 roadmap truths verified in code and tests (live-service parts human_needed)
re_verification: false
human_verification:
  - test: "Plan 02-10: create Stalwart mailbox, send a real invite email to an external inbox"
    expected: "Invite mail delivered (AUTH-01 real delivery); queue failure flag clears"
    why_human: "Needs live mail server; deliberately deferred (DEFERRED.md)"
  - test: "Google sign-in end to end with real OAuth clients on staging and production"
    expected: "Invited member signs in via Google; uninvited Google account refused"
    why_human: "Needs live Google OAuth clients; tests use a mocked Socialite"
  - test: "Run migrations on the real server DB role (fh_app) and run the audit prune cron"
    expected: "UPDATE/DELETE/TRUNCATE on audit_logs denied for the app role; 2-year prune works (AUTH-08)"
    why_human: "Real DB role and cron exist only on servers; tests verify the grants and trigger on the test DB only"
  - test: "Manual UI checks listed in each plan SUMMARY on phone, iPad and desktop (invite dialog, member panel, sessions, audit page, dark mode, SK/PL/EN)"
    expected: "Matches 02-UI-SPEC"
    why_human: "Visual and real-device behavior; not run per DEFERRED.md"
---

# Phase 2: Identity and Authorization Core Verification Report

**Goal:** Family members join by invitation and sign in, and all data is guarded server-side by per-member, per-module permissions controlled by the admin.
**Status:** human_needed (no code gaps found; 02-10 live ops deliberately deferred)

## Truths

| # | Truth | Status | Evidence |
|---|-------|--------|----------|
| 1 | Admin invites by email; registration only via invitation | VERIFIED in code (delivery human) | InviteService, InvitationsController, InviteAcceptController; InviteAcceptTest (single use, expired/used/revoked, race, plain token never stored); RegistrationAbsentTest: /register GET and POST are 404, only resetPasswords Fortify feature enabled |
| 2 | Email+password or Google sign-in, persistent session, admin can revoke sessions | VERIFIED in code (Google live human) | LoginTest, RememberMeTest, GoogleLoginTest, SessionListTest, SessionRevokeTest, SessionRevokerTest |
| 3 | Admin sets none/view/edit per member per module, presets, adjustable | VERIFIED | PresetTest, PermissionSaveTest, MembersPageTest |
| 4 | No grant means no access through any endpoint, proven by matrix | VERIFIED | EndpointMatrixTest fails for any route without a matrix row or unnamed, any route without an access: middleware (except listed Fortify rules), stale matrix rows; each row asserts status for all 7 roles; EnsureRouteDeclaresAccess fail-closed guard |
| 5 | Admin can view audit log of sensitive access/changes | VERIFIED in code (AUTH-08 server role human) | AuditPageTest, AuditMiddlewareTest, AuditTriggerTest, AuthEventsAuditTest, AuditPruneTest; migration revokes UPDATE/DELETE/TRUNCATE from fh_app and adds a BEFORE UPDATE trigger; AuditAppendOnlyTest 5 passed (not skipped) |

## Spot-checks run

| Check | Result |
|-------|--------|
| docker compose -p fh02ver run --rm app ./vendor/bin/pest | 259 passed (1544 assertions) |
| npx vitest run | 35 files, 397 tests passed |
| npm run build | OK, SW-PRECACHE OK |

## Requirements

AUTH-02..07 satisfied. AUTH-01 (real delivery) and AUTH-08 (real role, prune cron) remain unticked pending 02-10, consistent with REQUIREMENTS.md. No orphaned requirements.

## Anti-patterns

Not exhaustively scanned for debt markers beyond the test and build runs; no blocker found.

## Gaps

None in code. Plan 02-10 is deferred by the owner; its items are listed under human_verification.
