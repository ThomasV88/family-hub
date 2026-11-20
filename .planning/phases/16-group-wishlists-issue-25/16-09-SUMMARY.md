---
phase: 16-group-wishlists-issue-25
plan: 09
status: partial (Tasks 1 and 2 done, Task 3 staging checkpoint pending)
requirements: [WLG-01, WLG-02, WLG-03, WLG-04, WLG-05, WLG-06]
key-files:
  modified:
    - database/seeders/Staging/WishlistSeeder.php
    - database/seeders/StagingSeeder.php
    - database/seeders/Staging/FinanceSeeder.php
    - app/Console/Commands/StagingAudit.php
    - docs/ops/staging-data.md
    - tests/Feature/StagingDemoSeedTest.php
    - tests/Feature/StagingSeedTest.php
    - tests/js/a11y-sweep.test.js
  created:
    - tests/js/wishlist/PseudoLong.test.js
---

# Phase 16 Plan 09: Staging seed, audit, a11y sweep and gate (partial)

Staging now carries one synthetic group list per mode created through GroupService, the reseed purge and `staging:audit` cover the new tables, the group screens pass the accessibility sweep and the pseudo-long check, and the full local gate is green. The staging verification (Task 3) is pending for the orchestrator.

## Tasks

| Task | Commit | Result |
|------|--------|--------|
| 1 Staging group scenarios, purge, audit, ops note | fb96112 (Refs #25) | done |
| 1b Seed fix found by the gate (see deviations) | 1c78e99 (Refs #25) | done |
| 2 a11y sweep, PseudoLong, full gate | 97f9ba1 (Fixes #25) | done |
| 3 Staging verification | none | PENDING (orchestrator checkpoint) |

## What was built
- `WishlistSeeder::seedGroups`: "Spoločný zoznam rodičov (demo)" (gifts, members sk_sk.1 and en_gb.5, 3 wishes, one reserved by pl_pl.3 with an open co-buy and a share by sk_sk.2) and "Do domácnosti (demo)" (shared, same members, 3 wishes, one reserved by en_gb.5, one received with received_by sk_sk.1). Groups are created through `GroupService::create`; wishes, reservations and contributions are plain inserts like the existing seeder, without links.
- `StagingSeeder` reseed purge: groups whose members are all synthetic are deleted with their wishes before the wishes by owner; synthetic memberships are removed everywhere; a group with a real member keeps its rows.
- `StagingAudit`: rules for `wishlist_groups` (null or non-synthetic created_by_id) and `wishlist_group_members` (non-synthetic user_id or added_by_id).
- `docs/ops/staging-data.md`: Slovak subsection "Spoločné zoznamy".
- Tests: both seed tests list the new tables; new demo test (props of both modes per viewer, 403 for sk_sk.2), reseed-twice test (exactly 2 groups, 4 members, 6 group wishes, audit 0), audit/purge test for a real member, and `synthRows` now creates a tester group with a wish.
- a11y sweep: 9 group states (gifts-member, shared-member with reservation, shared open reservation box, gifts non-member, panel create, edit, mode confirmation, leave confirmation, delete confirmation) x en/sk/pl x light/dark x 360 and 1280 px, plus the same 9 x sk/pl with +40% text at 320 px: 126 new axe tests, all pass.
- `tests/js/wishlist/PseudoLong.test.js`: 8 tests (chips and gifts header with long name and 12 members, shared view and received_by line, panel with hints, member list and mode confirmation in sk and pl at 320 px; no truncation or `nowrap` classes; no `v-html` in the Wishlist components, checked also by grep: 0 in every file).

## Gate results
- Pint `--test`: PASS, 1295 files (and 1 file after the seeder fix).
- Full Pest, serial, compose project `fh1609`: 4245 passed, 1 skipped, 1 failed (39347 assertions, 709.7 s). The one failure was `StagingDemoSeedTest` "keeps finance private per account" ("overdue" missing), see deviation 1. After the fix, `StagingDemoSeedTest`, `StagingSeedTest` and `tests/Feature/Finance`: 248 passed (3473 assertions). A full re-run after the one-line fix was not repeated (the failing test is the only one that reads the changed seeder function; the Finance suite passes). Compose project torn down with `down -v`.
- Full Vitest (`--testTimeout=30000`): 211 files, 7222 tests passed (208.6 s). No flake occurred (CodexRunnerTest ran green, HelpSettingsPage axe did not time out).
- `npm run build`: success, `SW-PRECACHE OK`. Run inside the container with `--outDir /tmp/fhbuild1609 --emptyOutDir` (scratch outDir, as in 16-06 and 16-07); `public/build` untouched.
- `scripts/check-nginx-template.sh`: CHECK-NGINX OK. `scripts/check-compose.sh`: CHECK-COMPOSE OK.
- Gate commit 97f9ba1 contains `Fixes #25`; it is the last code commit.

## Deviations
1. [Rule 3 - Blocker, pre-existing] `FinanceSeeder::latestPastDue` used `candidate > today`, so on the 20th of a month (today) the "unpaid on purpose" fitness fee was due today and not overdue; the demo finance test failed. Changed to `>=` (strictly past due). Unrelated to groups, date-dependent, fixed in 1c78e99 so the gate could pass.
2. [Test calibration] The audit/purge test asserts only the group rules (`non-synthetic wishlist_groups`, `non-synthetic wishlist_group_members`) because the real user row itself keeps the audit at exit 1.
3. The a11y sweep mock of `usePage` gained `url: '/wishlist'` and `list` (a hoisted holder) so the Wishlist page can be swept; other pages are unaffected.
4. Wishes in the demo seed rose from 51 to 57 (within the existing 30 to 60 range, no widening needed).
5. Prior-plan deviations carried (16-01 to 16-08): ViewerContext single UNION query (16-01); lockForUpdate textual count 2 instead of 4 (16-02); NULL-safe shopping filter and race-guard test adaptation (16-04); zero-SQL check ignores the empty `where 0 = 1` giver lookup (16-05); extra error keys name_too_long and mode_invalid, scratch-outDir build (16-06, 16-07); GroupHeader not used in the giver view and add-tile label "Add wish" (16-07); manual additions about reservations on leave or add (16-08).

## User documentation
No user-visible change in this plan. Phase 16 touched: `resources/help/topics.json`, `resources/help/{en,sk,pl}.json` (16-06, also `lang/{en,sk,pl}.json`), `resources/manual/{en,sk,pl}/wishlist.json`, `who-sees-what.json`, `faq.json` (16-08). All sk and pl texts (lang `wishlist.group`, toasts, help tips, manual) are drafts that need native review. Terms to check: SK "Spoločné zoznamy", "Darčeky od ostatných", "Spoločné nákupy"; PL "Listy wspólne", "Prezenty od innych", "Wspólne zakupy". The Slovak operator note in docs/ops is internal.

## WLG coverage (checked against code and tests)
- WLG-01 create and invite: GroupManagementTest (create, ineligible refusal, limits), EndpointMatrixTest rows in `tests/Support/EndpointMatrix/wishlist-groups.php`, GroupPanel.test.js; seeded by `seedGroups` through the same service.
- WLG-02 members add, edit, remove wishes and manage the group: GroupWishCrudTest (create, update, delete, mark received), GroupManagementTest (syncMembers, rename, mode, leave, delete), GroupViews.test.js.
- WLG-03 mode per list: mode CHECK and GroupAccessTest, confirmed mode change that cancels reservations (GroupManagementTest, GroupPanel.test.js).
- WLG-04 hidden in gifts: GroupReservationPrivacyTest (byte-identical surfaces with and without reservations, no reservation SQL, canary name absent), GroupGivingTest (outsider reserves and co-buys), GroupViews.test.js. Seeded demo test asserts the same on seed data.
- WLG-05 open in shared: GroupReservationPrivacyTest (organizer and contributors with names and amounts), GroupGivingTest, GroupViews.test.js; seeded in the demo test.
- WLG-06 server-side access and unchanged personal and children lists: GroupAccessTest, EndpointMatrixTest, WishlistPrivacyArchTest, PersonalListsUnchangedTest, ReservationPrivacyTest, ChildListTest; manual and help in three languages (16-06, 16-08, Manual and Help Pest and Vitest coverage tests green). Honest limit: the deployed build is not yet verified (Task 3), and sk and pl texts are drafts.

## Task 3 checkpoint (pending): staging steps copied from the plan
Precondition: `develop` contains this phase's HEAD, the staging deploy finished on https://family-hub-stage.vostack.dev (otherwise report "awaiting staging deploy") and staging was reseeded. Widths 360, 820 and 1280, en plus one pass in sk and pl. Log in only with seeded demo accounts; the seed password is read from the staging `app.env` into a variable over `ssh my-server-agent` and never echoed, logged, screenshotted or written here.

Browser (record pass or fail per step):
1. As sk_sk.1: the Wishlist page shows the group-list chips with both seeded lists and a New group list button.
2. Seeded gifts list as sk_sk.1 and as en_gb.5: header with name, mode Gifts from others and both members; wishes look like an own list (no reservation tags, no names, no co-buy); received section works.
3. Same list as pl_pl.3 (non-member): titled as a group list with the members' names; the seeded reservation and open co-buy are visible; reserve another wish, then cancel it.
4. Back as sk_sk.1 and en_gb.5: the list looks exactly as in step 2; open the browser devtools network view and confirm the Inertia JSON of /wishlist?group= has no `reservation` key and no pl_pl.3 name.
5. Seeded shared list as sk_sk.1: reservation by en_gb.5 with the name, the received wish shows "marked by" sk_sk.1; reserve a free wish, open co-buy; as en_gb.5 join with a share; Edit wish works from the gift panel.
6. Same shared list URL as sk_sk.2: the 403 page; the list is not among sk_sk.2's chips.
7. As sk_sk.1 create "Test group (demo)" in Gifts from others with en_gb.5; add two wishes (one with a demo example.com link); as pl_pl.3 reserve one; as en_gb.5 open Manage list, switch to Shared purchases: the confirmation says all reservations are cancelled; confirm; the list shows no reservation. Read-only SQL: count of wish_reservations on that group's wishes is 0.
8. Members: as sk_sk.1 add pl_pl.3 to the test list, then as pl_pl.3 leave it; as en_gb.5 remove sk_sk.1; as en_gb.5 (last member) Leave is refused with the "delete instead" message; Delete list with confirmation removes it and its wishes.
9. Personal and children lists: sk_sk.2's own list still hides the Bugatti reservation; en_gb.5 managing en_gb.6 still sees no reservations there; sk_sk.1 viewing sk_sk.2's list still sees it. Your shopping of pl_pl.3 lists the gifts-list reservation under the group name with a working link.
10. 360 px in sk and pl, light and dark: chips, header, panel, confirmations and the shared view wrap without clipping; `/manual/wishlist` section group-lists renders in all three languages; the two new "?" tips and the reworded reservation tip open.

Read-only server checks (via `ssh my-server-agent` with sudo docker exec; counts only):
11. `select mode, count(*) from wishlist_groups group by 1`; `select count(*) from wishes where (owner_id is null) = (group_id is null)` returns 0.
12. `php artisan staging:audit` in the staging app container passes; production: only confirm the production site still answers (HTTP status), nothing else.

Acceptance: steps 1 to 12 each recorded as pass (counts only for SQL); step 4 records that no reservation, organizer name or co-buy amount appears for either gifts-list member in the page or the Inertia JSON; step 7 records zero reservations on the switched list after the confirmation.

Note for the verifier: the seeded demo logins are `sk_sk.1` (parent1), `sk_sk.2` (child1), `pl_pl.3` (grandparent), `pl_pl.4` (guest), `en_gb.5` (parent2), `en_gb.6` (child2).

## Decision record for the owner
To be completed in the final summary after Task 3, as the plan's table (owner decisions D-purpose, D-manage, D-privacy, D-enforce and planner decisions G-01 to G-13, open points and backlog). Not repeated here: no change to those decisions was made in this plan.

## Known Stubs
None.

## Self-Check: PASSED
Commits fb96112, 1c78e99 and 97f9ba1 exist; `tests/js/wishlist/PseudoLong.test.js` and the changed files are present. Nothing was pushed and no server was contacted.
