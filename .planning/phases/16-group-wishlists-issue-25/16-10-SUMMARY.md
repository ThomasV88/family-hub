# Phase 16 Plan 10: Gap closure after staging checkpoint Summary

Fixes the group wishlist defects D1-D6 found at the 16-09 staging checkpoint (GitHub #25).

## Fixes and root causes

- **D1** `GroupService::leave` aborted with a bare 409 for the last member; Inertia showed its raw error modal and GroupPanel fell back to the generic save error. Now it throws `ValidationException` on `member_ids` with `wishlist.group.error.last_member` (the key existed, unused), which GroupPanel already maps inline. Group stays unchanged. Tests: Pest `GroupManagementTest` (session error plus group/members unchanged; service test expects ValidationException), Vitest `GroupPanel.test.js` (message shown, no generic text).
- **D2** `ReservationBox` showed `wishlist.res.free_hint` (personal-list recipient wording) on group lists. Added `showOwnerHint` prop, `GiftWishPanel` passes `!list.group`. Hidden for group lists in both modes; no lang text changed (no SK/PL drafts).
- **D3** `OwnListView` personal banner now hidden when `list.group` is set.
- **D4** `GiftWishCard` omits the status tag row for received wishes, so only "Received" shows.
- **D5** Intentional, no change: `GET /wishlist?group=<unknown or not visible>` answers 403 for both, a uniform refusal that does not leak whether the group exists.
- **D6** `ContributorList`: the leading space inside the inline span was trimmed by Vue; an explicit space is now rendered, giving "Name (organizer)".
- **D7** Left as is (top-bar "Add wish to X" plus in-list tile are acceptable).
- D8 pre-existing, out of scope.

## Tests

- Pest (docker, project fh1610): tests/Feature/Wishlist + Access + Manual + Help: 612 passed, then GroupManagementTest re-run after fixing its service test: 24 passed. Pint --test: pass. Stack torn down with `down -v`.
- Vitest tests/js/wishlist, help, manual, brand, i18n-phase16: 30 files, 430 tests passed (includes new `tests/js/wishlist/GroupListGaps.test.js` for D4, D6, D2). D3 has no dedicated test.
- Vite build into scratch outDir: OK. Privacy tests stayed green.

## Deviations

None of Rules 1-4 beyond the above. User documentation: no user-visible text meaning changed beyond removing a wrong hint on group lists; no manual or help files touched (hint not documented there), no SK/PL text changes.

## Files

app/Wishlist/Groups/GroupService.php; resources/js/Wishlist/{ContributorList,GiftWishCard,GiftWishPanel,OwnListView,ReservationBox}.vue; tests/Feature/Wishlist/GroupManagementTest.php; tests/js/wishlist/GroupPanel.test.js; tests/js/wishlist/GroupListGaps.test.js.
