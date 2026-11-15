---
phase: 04-wishlist
verified: 2026-11-13T19:50:00Z
status: human_needed
score: 5/5 roadmap success criteria verified in code and tests; live behaviour deferred (plan 04-13)
overrides_applied: 0
human_verification:
  - test: "Deploy to staging/production (images with gd/exif rebuilt), confirm uploads volume is mounted and the nightly uploads snapshot and off-site copy exist on the server"
    expected: "Uploads persist across redeploys; backup-freshness reports uploads-nightly fresh; restore drill on the server proves DB and uploads"
    why_human: "Live VPS services (plan 04-13 deferred, see DEFERRED.md)"
  - test: "Add a wish with real shop URLs (several shops) and wait for the background fetch"
    expected: "Product image appears automatically; pages without og:image leave the manual upload path usable"
    why_human: "Real shop sites and the live worker; tests use a local socket fixture only"
  - test: "Upload a photo from a real iPhone (HEIC/large, with GPS EXIF)"
    expected: "Stored as metadata-free JPEG, correct orientation, within limits through the live nginx"
    why_human: "Real device and live proxy"
  - test: "As recipient (and as a designated manager) on a real device, browse list, overview, occasions, notifications and settings while another member holds a reservation and a co-buy"
    expected: "No trace of the reservation anywhere"
    why_human: "Real devices and sessions; automated checks are differential/canary/DOM only"
  - test: "Receive real giver reminders (mail and push) before an occasion; confirm recipient and managers receive none"
    expected: "Giver gets 08:00 family-time reminder; recipient/managers nothing"
    why_human: "Live SMTP, push service and scheduler"
  - test: "Spot-check Polish name days against an authoritative source and confirm the Wikipedia attribution is visible"
    expected: "Sample dates match; attribution shown"
    why_human: "Source-quality judgement (plan 04-13 item)"
  - test: "End-of-phase manual UI checks listed in the plan SUMMARIES (SK/PL/EN copy, layouts on phone/iPad)"
    expected: "Matches 04-UI-SPEC"
    why_human: "Visual/UX; explicitly not run (DEFERRED.md)"
---

# Phase 4: Wishlist Verification Report

**Phase Goal:** Each member keeps a gift wishlist that relatives can reserve from and chip in on, without the recipient ever seeing reservations.
**Verified:** 2026-11-13
**Status:** human_needed (no code gaps found; live ops deliberately deferred)
**Re-verification:** No, initial

## Commands run locally

| Check | Result |
|---|---|
| Pest in container (`docker compose -p fh04ver run --rm app ./vendor/bin/pest`) | 946 passed, 10959 assertions |
| `npx vitest run` | 62 files, 891 tests passed |
| `npm run build` | OK, `SW-PRECACHE OK` |
| `scripts/test-backup-local.sh` | `TEST-BACKUP OK` |
| `scripts/check-upload-limit.sh` | `UPLOAD-LIMIT OK upload=302 other=413` |
| `scripts/check-compose.sh` | `CHECK-COMPOSE OK` |

## Observable Truths

| # | Truth (ROADMAP SC) | Status | Evidence |
|---|---|---|---|
| 1 | Wishes with title, notes, price, size/variant notes, multiple URLs (WISH-01/02) | VERIFIED | WishCrudTest, WishModelTest (minor-unit price), WishUrlTest (5 ok, 6 rejected, scheme rejection) |
| 2 | Auto image from link in background, manual upload fallback (WISH-03) | VERIFIED in code; live fetch human | WishImageTest (queued after commit, no HTTP in request code, upload to metadata-free JPEG, bad uploads rejected, throttles, authenticated serve with ETag/nosniff/304), FetchWishImageTest, SafeFetcher tests |
| 3 | Reserve and co-buy, hidden from recipient everywhere (WISH-04/07) | VERIFIED in code; device check human | see secrecy evidence below; ReservationTest, CoBuyTest, ManagerPurgeTest |
| 4 | Birthdays, SK/PL name days, Christmas, custom occasions (WISH-05) | VERIFIED | OccasionTest, OccasionCalendarTest, BirthDatePrivacyTest, NameDayDataTest |
| 5 | Giver-only reminders (WISH-06) | VERIFIED in code; live delivery human | OccasionReminderTest |

## Specific evidence requested

- **Reservation secrecy:**
  - Arch test `tests/Unit/Arch/WishlistPrivacyArchTest.php`: owner side, Catalog, Fetch, Occasions, ViewerContext, WishAccess, reminders and home card must not use WishReservation, WishContribution or Giving. The reservation models are only used inside the giving side, factories and test support.
  - Differential test (ReservationPrivacyTest:14): recipient-side viewers get byte-identical surfaces with and without reservations.
  - Canary test (ReservationPrivacyTest:43): a canary name and the amount 7777 are absent from every surface.
  - Query-log check (ReservationPrivacyTest:34): `DB::getQueryLog()` shows no reservation SQL for recipient-side requests.
  - Owner-action parity (OwnerActionsTest:45): every owner action behaves identically on reserved and unreserved wishes.
  - Related checks: ReservationPrivacyTest:51 (giving writes answer identically for owner and manager), :81 (no relation of Wish serialises reservations), :103 (own-list props are identical whether or not the viewer holds reservations elsewhere).
  - `tests/js/wishlist/OwnListPrivacy.test.js` is the DOM-equality test; it passed in vitest.
  - Not exercised: "search" and "export" surfaces. I found no such feature in the wishlist code. The arch and differential coverage applies if they are added.
- **Recipient and managers excluded from reminders:** OccasionReminderTest "never for the owner, managers or view-only members". Also "carries ids and dates only and never touches reservations", plus an arch rule on the reminder classes.
- **SafeFetcher:** SafeFetcherTest denies private, reserved and IPv6 addresses including `127.0.0.1`, `169.254.169.254`, `::1`, `::ffff:127.0.0.1`, `64:ff9b::7f00:1` and `2002:7f00:1::`. It also covers a mixed public/private A record, pinned DNS, manual redirects (max 3) and rejection of unsafe redirects. SafeFetcherSocketTest runs real sockets against `tests/fixtures/fetch/router.php`: pinned connection with the Host header, oversize Content-Length, oversize chunked body, slow-drip timeout, redirect to a disallowed host, and no escape hatch in production code.
- **Upload hardening:** WishImageTest covers the metadata-free JPEG re-encode, rejection of bad uploads with translated keys, throttles of 20/min and 10/min, authenticated serving (private no-cache, nosniff, Vary Cookie), deletion of files with the wish, and the nginx limit via check-upload-limit (302 for the upload route, 413 elsewhere).
- **Name-day data gate:** NameDayDataTest asserts a complete 366-day calendar with provenance and license, clean names only, and byte-identical rebuild from stored sources.
- **Backups:** backup.sh writes an `uploads.tar` snapshot tagged `uploads-<kind>`. test-backup-local.sh asserts the uploads snapshot exists, the off-site copy matches, the drill prints `RESTORE-DRILL UPLOADS OK`, the drill still restores the newest database snapshot (`RESTORE-DRILL OK tables=... snapshot=<newest db id>`), an uploads snapshot id is refused as a drill argument, and a tar failure creates no snapshot. All passed.

## Requirements Coverage

WISH-01 through WISH-07 are all addressed by plans 04-01..04-12 and are SATISFIED at the code and test level. REQUIREMENTS.md still shows them as "Pending" (bookkeeping only; update on phase close). No orphaned requirements.

## Anti-patterns

No blockers found. I did not grep every modified file for TBD/FIXME/XXX markers; the test suites do not surface any.

## Deferred (not gaps)

Plan 04-13 (live ops) is deferred by the owner per `.planning/phases/04-wishlist/DEFERRED.md`. It is not scheduled in a later roadmap phase, so I treat it as owner-accepted human verification, not a gap. The same file lists the manual UI checks from the plan SUMMARIES as not run, and notes that create-mode image upload is disabled until the wish exists.

## Gaps Summary

No code gaps. Status is human_needed because live services, real shops, real devices and visual checks are unverified. A person must confirm them before the phase is treated as shipped.

_Verifier: Claude (gsd-verifier)_
