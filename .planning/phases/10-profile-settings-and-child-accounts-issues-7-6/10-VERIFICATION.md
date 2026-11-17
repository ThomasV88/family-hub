---
phase: 10-profile-settings-and-child-accounts-issues-7-6
verified: 2026-11-17T00:00:00Z
status: human_needed
score: 7/7 must-haves verified
gaps: []
human_verification:
  - test: "Native review of Slovak and Polish drafts (lang/sk.json, lang/pl.json profile.* and settings.members.child.* keys, resources/manual/{sk,pl}/admin.json child-convert items, help tips)"
    expected: "Natural wording, correct plural and gender forms"
    why_human: "Translation quality cannot be checked by code; drafts flagged in 10-REVIEW-FIX.md"
  - test: "Upload a profile photo from a real iPhone (HEIC/large camera JPEG) on staging"
    expected: "Upload accepted, re-encoded, avatar replaced everywhere"
    why_human: "Real-device camera formats and Safari file picker"
  - test: "Logic-change items flagged in 10-REVIEW-FIX.md (WR-02 synthetic-child rule, WR-04 conversion advisory lock, WR-05 photo store row lock)"
    expected: "Behave under real concurrency/reseed on staging"
    why_human: "Covered by lock-statement assertions, not by a parallel-process test"
---

# Phase 10 Verification

Goal-backward check against ROADMAP success criteria, done by reading code (not SUMMARY claims).

| # | Success criterion | Status | Evidence |
|---|---|---|---|
| 1 | "Profile settings" above "Settings" in account menu | VERIFIED | resources/js/Shell/AccountMenu.vue: `profile` item (t('profile.menu')) listed immediately before `settings` |
| 2 | Name editable, e-mail read-only | VERIFIED | UpdateProfileNameRequest + routes/web/profile.php; ProfileUpdateTest; staging shows read-only e-mail |
| 3 | Photo add/replace via private re-encode pipeline, replaces initials everywhere | VERIFIED | ProfilePhotoStore, ProfilePhotoController, ProfilePhotoTest (re-encode without EXIF, size/type/pixel limits); 13 files use UiAvatar fed by shared `avatars` map; staging shows photos in Wishlist |
| 4 | Admin creates child directly, no invitation, no sign-in by any route | VERIFIED | CreateChild action, ChildrenController, User::canSignIn() used in Fortify authenticate/2FA/passkey/Google/reset/invite paths, BlockDisabledSignIn global middleware; ChildSignInBlockedTest covers password, 2FA forge, passkey, Google, reset, session, remember cookie |
| 5 | Child is Wishlist owner, parent/admin manages wishes | VERIFIED | WishlistManagerSync, ProfileAccess; staging: "Spravujete zoznam: Maťko Demo (demo)" with 3 wishes |
| 6 | Design decision documented | VERIFIED | Single users row, nullable e-mail, sign_in_disabled, CHECK invariant (migration 2026_11_26_100000); conversion keeps same row (InviteService, ChildConversionTest) |
| 7 | Privacy tests: child never authenticates or gets mail; photos not public | VERIFIED | canSignIn gates routeNotificationForMail, reset notification, ReminderRecipients, VaccinationReminderPlanner; photos stored under storage/app/private/wish-images/profile-photos, served only by authenticated ETag route with uniform no-store 404 |

## Other checks
- Locale key parity en/sk/pl for lang/*.json: identical key sets.
- Commit rule: `Fixes #6` and `Fixes #7` trailers present in git log.
- Code review: 8 of 9 in-scope findings fixed; IN-04 (blank e-mail guard in an already-pushed migration) deliberately skipped, with a pre-release SQL check noted. Not a goal blocker.
- Anti-patterns: none blocking found in grep of auth and profile paths.
- Informational: ROADMAP.md plan checkboxes for Phase 10 are still unticked (`[ ]`); update when closing the phase.
- Not run (per instruction): full test suites; relied on executors' gate plus targeted reading of test names.

## Gaps
None.
