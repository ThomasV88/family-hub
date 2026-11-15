---
phase: 04
slug: wishlist
status: draft
nyquist_compliant: false
wave_0_complete: true
created: 2026-11-13
---

# Phase 04 — Validation Strategy

> Per-phase validation contract for feedback sampling during execution.

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | Pest 4 (PHP, in app container, Postgres) + Vitest (frontend) + shell proofs (scripts/test-backup-local.sh, scripts/check-*.sh, scripts/check-upload-limit.sh) |
| **Config file** | phpunit.xml, vitest.config.js |
| **Quick run command** | the task's own `<automated>` command (targeted Pest files or Vitest files, compose project `-p fh04NN`) |
| **Full suite command** | `docker compose -p fh04NN run --rm app ./vendor/bin/pest && npx vitest run && npm run build` (run once, in each plan's final task) |
| **Estimated runtime** | targeted task verify ~20-60 s; full Pest ~2-3 min (about 500 existing tests plus the Phase 4 privacy enumeration); Vitest ~40 s; build ~30 s; scripts/test-backup-local.sh ~2-3 min (restic, several backups and drills); full plan-end run ~4-6 min |

---

## Sampling Rate

- **After every task commit:** the task's `<automated>` command (targeted)
- **After every plan:** the plan's final task runs full Pest, full Vitest, Pint on the plan's files and (where UI or assets changed) the build
- **After every wave:** full Pest + Vitest once more on the merged tree if any plan of the wave reported a sibling-caused re-run
- **Before `/gsd-verify-work`:** full suite must be green
- **Max feedback latency:** ~60 s for per-task verifies (full-suite runs only at plan ends)

---

## Per-Task Verification Map

Commands are shown without the leading `cd /home/voslar/projects/family-hub &&`. "full" = `./vendor/bin/pest && npx vitest run` (+ `npm run build` where listed) in that plan's compose project, then `down -v`.

| Task ID | Plan | Wave | Requirement | Threat Ref | Secure Behavior | Test Type | Automated Command | File Exists | Status |
|---------|------|------|-------------|------------|-----------------|-----------|-------------------|-------------|--------|
| 04-01-01 | 01 | 1 | WISH-01..07 | T-04-02, T-04-03 | Manager hint has no reservation concept; params rendered by interpolation | unit (Vitest) | `npx vitest run tests/js/i18n-phase4.test.js tests/js/i18n-phase3.test.js tests/js/i18n-phase2.test.js tests/js/i18n.test.js` | ❌ W0 (created in task) | ⬜ pending |
| 04-01-02 | 01 | 1 | WISH-06 | T-04-01 | Gift reminder strings carry no wish/giver/amount placeholders | unit (Pest) + full + build | `docker compose -p fh0401 run --rm app ./vendor/bin/pest tests/Unit/WishlistLangTest.php tests/Unit/NotificationsLangTest.php` + Pint + full | ❌ W0 (created in task) | ⬜ pending |
| 04-02-01 | 02 | 1 | WISH-03 | T-04-04, T-04-05 | gd/exif present; uploads volume only in app/worker/scheduler; 8m body limit only on the upload route; SCRIPT_NAME pinned | unit + config checks | `docker compose build app && ... pest tests/Unit/Wishlist/ImageRuntimeTest.php && bash scripts/check-compose.sh && bash scripts/check-nginx-template.sh` + SCRIPT_NAME grep | ❌ W0 (created in task) | ⬜ pending |
| 04-02-02 | 02 | 1 | WISH-03 | T-04-06, T-04-07, T-04-07b | Uploads snapshot separate (uploads-<kind>), DB drill by path, explicit-id off-site copy, drill passes on the copy, uploads freshness | shell e2e + full + build | `TEST_BACKUP_PROJECT=fh0402b bash scripts/test-backup-local.sh` + full | ✅ (extended) | ⬜ pending |
| 04-03-01 | 03 | 1 | WISH-05 | T-04-08, T-04-09 | Data from pinned sources only, never model memory; PL-GATE PASS/FAIL | unit (data validation, reproducibility) | `docker compose -p fh0403 run --rm app ./vendor/bin/pest tests/Unit/Wishlist/NameDayDataTest.php` | ❌ W0 (created in task) | ⬜ pending |
| 04-03-02 | 03 | 1 | WISH-05 | T-04-08 | Stop-and-return on PL-GATE FAIL; no hand-filled data | checkpoint:decision (only on FAIL) | n/a (gate on Task 1 output line) | n/a | ⬜ pending |
| 04-03-03 | 03 | 1 | WISH-05 | T-04-10 | Bounded name search | unit + full | `... pest tests/Unit/Wishlist/Occasions` + Pint + full | ❌ W0 (created in task) | ⬜ pending |
| 04-04-01 | 04 | 1 | WISH-03 | T-04-11, T-04-12, T-04-13 | SSRF denylist, DNS pinning, re-validated redirects | unit (mocked handler) | `docker compose -p fh0404 run --rm app ./vendor/bin/pest tests/Unit/Wishlist/Fetch/SafeFetcherTest.php` | ❌ W0 (created in task) | ⬜ pending |
| 04-04-02 | 04 | 1 | WISH-03 | T-04-14, T-04-15, T-04-16 | Size/time caps on real sockets; safe HTML parsing; no URL logging | integration (real socket) + full | `... pest tests/Unit/Wishlist/Fetch` + Pint + full | ❌ W0 (created in task) | ⬜ pending |
| 04-05-01 | 05 | 2 | WISH-01, WISH-02 | T-04-18, T-04-20, T-04-22 | No reservation column; fillable allowlist; owner side arch-blind; no admin bypass | unit + feature + arch | `docker compose -p fh0405 run --rm app ./vendor/bin/pest tests/Unit/Arch/WishlistPrivacyArchTest.php tests/Unit/Wishlist/MoneyTest.php tests/Feature/Wishlist/WishModelTest.php` | ❌ W0 (created in task) | ⬜ pending |
| 04-05-02 | 05 | 2 | WISH-01, WISH-02 | T-04-17, T-04-19, T-04-21 | canEditList before writes; identical 403 for probes; http(s)-only URLs | feature + matrix | `... pest tests/Feature/Wishlist tests/Unit/Arch tests/Feature/Access/EndpointMatrixTest.php` | ❌ W0 (created in task) | ⬜ pending |
| 04-05-03 | 05 | 2 | WISH-04 | T-04-23 | Admin-only manager designation | feature + matrix + full | `... pest tests/Feature/Wishlist/WishlistManagersTest.php tests/Feature/Settings tests/Feature/Access/EndpointMatrixTest.php` + Pint + full | ❌ W0 (created in task) | ⬜ pending |
| 04-06-01 | 06 | 2 | WISH-01, WISH-02 | T-04-24, T-04-26 | No v-html; images only from the app route | component (Vitest) | `npx vitest run tests/js/wishlist/Gallery.test.js tests/js/wishlist/money.test.js tests/js/shell` | ❌ W0 (created in task) | ⬜ pending |
| 04-06-02 | 06 | 2 | WISH-01 | T-04-24 | Safe link rendering (noopener, http(s)) | component | `npx vitest run tests/js/wishlist/OwnWishPanel.test.js tests/js/wishlist/Gallery.test.js` | ❌ W0 (created in task) | ⬜ pending |
| 04-06-03 | 06 | 2 | WISH-04 | T-04-25, T-04-27 | Own-list DOM identical with injected canary reservation data | component + full + build | `npx vitest run tests/js/wishlist tests/js/settings` + full + build | ❌ W0 (created in task) | ⬜ pending |
| 04-07-01 | 07 | 3 | WISH-03 | T-04-28, T-04-29, T-04-30 | Pixel cap 13 MP pre-decode; resize-before-rotate keeps 12 MP photo < 72 MiB peak; type from bytes; EXIF/GPS stripped | unit (generated fixtures) | `docker compose -p fh0407 run --rm app ./vendor/bin/pest tests/Unit/Wishlist/ImageProcessorTest.php` and again with `php -d memory_limit=128M` | ❌ W0 (created in task) | ⬜ pending |
| 04-07-02 | 07 | 3 | WISH-03 | T-04-31..T-04-35 | Worker-only fetch; private/no-cache + ETag + nosniff; IDOR 403; 2 MB upload reaches PHP, 413 elsewhere | feature + matrix + dev-stack smoke | `... pest tests/Feature/Wishlist/WishImageTest.php tests/Feature/Wishlist/FetchWishImageTest.php tests/Feature/CacheHeadersTest.php tests/Feature/Wishlist/WishCrudTest.php tests/Unit/Arch tests/Feature/Access/EndpointMatrixTest.php && bash scripts/check-upload-limit.sh fh0407` | ❌ W0 (created in task) | ⬜ pending |
| 04-07-03 | 07 | 3 | WISH-03 | T-04-33 | Polling bounded; image controls only in owner panel | component + full + build | `npx vitest run tests/js/wishlist` + Pint + full + build | ❌ W0 (created in task) | ⬜ pending |
| 04-08-01 | 08 | 3 | WISH-04, WISH-07 | T-04-36, T-04-38, T-04-39 | Differential + canary + zero-reservation-SQL over enumerated routes; owner-action parity; reservation models only in Giving, factories, Tests\Support | feature + arch | `docker compose -p fh0408 run --rm app ./vendor/bin/pest tests/Unit/Arch tests/Feature/Wishlist/ReservationPrivacyTest.php tests/Feature/Wishlist/OwnerActionsTest.php` | ❌ W0 (created in task) | ⬜ pending |
| 04-08-02 | 08 | 3 | WISH-04 | T-04-37, T-04-40, T-04-41, T-04-44 | Recipient-side 403 before lookup; manager purge; race-safe reserve | feature + matrix | `... pest tests/Feature/Wishlist/ReservationTest.php tests/Feature/Wishlist/ManagerPurgeTest.php tests/Feature/Wishlist/ReservationPrivacyTest.php tests/Feature/Wishlist/ListPageTest.php tests/Unit/Arch tests/Feature/Access/EndpointMatrixTest.php` | ❌ W0 (created in task) | ⬜ pending |
| 04-08-03 | 08 | 3 | WISH-07 | T-04-41, T-04-42, T-04-43 | Organizer-only transitions with row locks; viewer-only shopping JSON | feature + matrix + full | `... pest tests/Feature/Wishlist tests/Unit/Arch tests/Feature/Access/EndpointMatrixTest.php` + Pint + full | ❌ W0 (created in task) | ⬜ pending |
| 04-09-01 | 09 | 3 | WISH-05 | T-04-45, T-04-48 | Birth date hidden (month/day only to others) | feature + arch | `docker compose -p fh0409 run --rm app ./vendor/bin/pest tests/Feature/Wishlist/OccasionCalendarTest.php tests/Feature/Wishlist/BirthDatePrivacyTest.php tests/Unit/Arch` | ❌ W0 (created in task) | ⬜ pending |
| 04-09-02 | 09 | 3 | WISH-05 | T-04-46, T-04-47, T-04-49 | IDOR-safe date edits; creator/admin-only custom occasions; bounded search | feature + matrix | `... pest tests/Feature/Wishlist/OccasionTest.php tests/Feature/Wishlist/OccasionCalendarTest.php tests/Unit/Arch tests/Feature/Access/EndpointMatrixTest.php` | ❌ W0 (created in task) | ⬜ pending |
| 04-09-03 | 09 | 3 | WISH-05 | T-04-50 | Text interpolation only | component + full + build | `npx vitest run tests/js/wishlist/Occasions.test.js tests/js/wishlist/OccasionPanel.test.js` + Pint + full + build | ❌ W0 (created in task) | ⬜ pending |
| 04-10-01 | 10 | 4 | WISH-04, WISH-07 | T-04-53, T-04-54 | Giver-only ReservationBox; own-list privacy DOM test | component | `npx vitest run tests/js/wishlist/ReservationBox.test.js tests/js/wishlist/GiftWishPanel.test.js tests/js/wishlist/OwnListPrivacy.test.js` | ❌ W0 (created in task) | ⬜ pending |
| 04-10-02 | 10 | 4 | WISH-04 | T-04-51, T-04-52 | Shopping JSON never requested from recipient views; not cached by SW | component + full + build | `npx vitest run tests/js/wishlist` + full + build | ❌ W0 (created in task) | ⬜ pending |
| 04-11-01 | 11 | 4 | WISH-06 | T-04-55, T-04-57 | Givers exclude owner/managers; idempotent scan | feature | `docker compose -p fh0411 run --rm app ./vendor/bin/pest tests/Feature/Wishlist/OccasionReminderTest.php` | ❌ W0 (created in task) | ⬜ pending |
| 04-11-02 | 11 | 4 | WISH-06, WISH-04 | T-04-56, T-04-58, T-04-60 | Reminder content without reservation data; escaped params; bounded prune | feature + arch | `... pest tests/Feature/Notifications/WishOccasionOutboxTest.php tests/Feature/Wishlist/PruneWishlistTest.php tests/Feature/Wishlist/OccasionReminderTest.php tests/Feature/Notifications/OutboxTest.php tests/Unit/Arch` | ❌ W0 (created in task) | ⬜ pending |
| 04-11-03 | 11 | 4 | WISH-06 | T-04-59 | Lead-day settings only for the acting user | feature + matrix + full + build | `... pest tests/Feature/Settings/GiftRemindersTest.php tests/Feature/Notifications tests/Feature/Access/EndpointMatrixTest.php && npx vitest run tests/js/settings` + Pint + full + build | ❌ W0 (created in task) | ⬜ pending |
| 04-12-01 | 12 | 4 | WISH-05 | T-04-61, T-04-62, T-04-63 | Occasions prop identical for owner/manager/giver; no birth year; gated Overview card | feature (+ privacy suite re-run) | `docker compose -p fh0412 run --rm app ./vendor/bin/pest tests/Feature/Wishlist/OccasionStripTest.php tests/Feature/Wishlist/HomeOccasionsTest.php tests/Feature/Wishlist/ReservationPrivacyTest.php tests/Feature/HomePageTest.php` | ❌ W0 (created in task) | ⬜ pending |
| 04-12-02 | 12 | 4 | WISH-05 | T-04-61 | Strip and card render only server-sent occasion data | component + full + build | `npx vitest run tests/js/wishlist/OccasionStrip.test.js tests/js/UpcomingOccasionsCard.test.js tests/js/Home.test.js tests/js/wishlist` + Pint + full + build | ❌ W0 (created in task) | ⬜ pending |
| 04-13-01 | 13 | 5 | WISH-03 | T-04-64, T-04-66, T-04-67 | Staging server checks (gd/exif, uploads volume, schedules, DB + uploads snapshots, drill) | manual (server, with owner) | MISSING by design: live server commands with the owner | n/a | ⬜ pending |
| 04-13-02 | 13 | 5 | WISH-03..06 | T-04-65 | Real shop images, device uploads, privacy on devices, reminders | manual (devices) | MISSING by design: physical devices and real websites | n/a | ⬜ pending |
| 04-13-03 | 13 | 5 | WISH-03..06 | T-04-66, T-04-67 | Production release and real reminder email | manual (production) | MISSING by design: live production and real delivery | n/a | ⬜ pending |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

"❌ W0 (created in task)" means the test file does not exist yet and is written by the same task before or alongside the implementation (`tdd="true"` tasks write the test first). No separate Wave 0 plan is needed.

---

## Wave 0 Requirements

Existing infrastructure covers all phase requirements: Pest 4 (incl. arch expectations `not->toUse` and `toOnlyBeUsedIn`), Vitest with the axe and i18n helpers from Phases 2-3, the endpoint matrix harness (tests/Support/EndpointMatrix.php), the Phase 1 backup proof (scripts/test-backup-local.sh) and the compose/nginx check scripts already exist. New test files and fixtures (tests/Support/ImageFixtures.php, tests/Support/WishlistPrivacy.php, FakeDnsResolver in tests/Support) are created by the tasks that first need them, test-first.

- [x] Test framework installed (Pest 4, Vitest)
- [x] Matrix and privacy harness patterns available (Phases 2-3)
- [x] Backup proof script available to extend (Phase 1)

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| Staging image build has gd/exif, uploads volume shared and writable, schedules registered, backup creates DB + uploads snapshots, drill prints RESTORE-DRILL UPLOADS OK, copy repo lists both ids | WISH-03 | Live server, owner present (owner rule: test on staging only) | Plan 04-13 Task 1 steps 3-5 |
| Next-day freshness shows `uploads=` age | WISH-03 | Depends on the 03:45 nightly cron on the server | Plan 04-13 Task 1 step 4 (day after release) |
| og:image fetch from real shop links (several real e-shops) | WISH-03 | Third-party websites; outbound network from the worker | Plan 04-13 Task 2 |
| iPhone photo upload (location on): no 413, upright, no GPS in downloaded image | WISH-03 | Physical device and real camera EXIF | Plan 04-13 Task 2 item 2 |
| Reservations invisible to recipient and manager on real devices (phone, iPad, desktop), co-buy flow between two givers | WISH-04, WISH-07 | Multi-account, multi-device UX check | Plan 04-13 Task 2 |
| Occasion reminder email and push delivered on time in SK/PL/EN | WISH-06 | Real mail (Stalwart) and push delivery, time-based | Plan 04-13 Tasks 2-3 |
| Production release through PR to main and repeated server checks | WISH-03..06 | Production, owner present | Plan 04-13 Task 3 |
| PL name-day gate failure decision (only if PL-GATE FAIL) | WISH-05 | Owner chooses the data source | Plan 04-03 Task 2 |

---

## Validation Sign-Off

- [x] All tasks have `<automated>` verify or are explicit manual/checkpoint tasks listed above
- [x] Sampling continuity: no 3 consecutive auto tasks without automated verify
- [x] Wave 0 covers all MISSING references (only 04-13 manual tasks are MISSING, by design)
- [x] No watch-mode flags (`vitest run`, never `vitest` watch)
- [ ] Feedback latency within the targets above (confirm during execution)
- [ ] `nyquist_compliant: true` set in frontmatter (at sign-off)

**Approval:** pending
