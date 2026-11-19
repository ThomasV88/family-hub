---
phase: 16-group-wishlists-issue-25
plan: 05
subsystem: wishlist-privacy-tests
tags: [privacy, tests, groups]
requires: [16-02, 16-04]
provides: [group privacy proofs, personal-list regression proof]
key-files:
  modified: [tests/Support/WishlistPrivacy.php]
  created: [tests/Feature/Wishlist/GroupReservationPrivacyTest.php, tests/Feature/Wishlist/PersonalListsUnchangedTest.php]
requirements-completed: [WLG-04, WLG-05, WLG-06]
completed: 2026-11-20
---

# Phase 16 Plan 05: Group privacy proofs Summary

Router-enumerated differential, canary and zero-SQL privacy proofs for gifts and shared group lists, plus a regression suite proving personal, child and managed lists are unchanged.

## Commits
- 2b21174 test(16-05): group reservation privacy proofs
- e5a4a68 test(16-05): personal, child and managed lists unchanged

## What was built
- `WishlistPrivacy::groupScenario(GroupMode)`, `?group=` / `&wish=` / `&available=1` URL variants (only when ctx has `group`) and a `{group}` parameter provider. Existing outputs for the personal scenario are unchanged; ReservationPrivacyTest was not edited and passes.
- GroupReservationPrivacyTest (5 tests, 6 cases): gifts-mode members A and B get byte-identical surfaces with/without reservation and co-buy, no canary name/amount, no reservation SQL; identical 403 on every giving write; shared-mode member sees organizer and contributor names and amount; shared non-member gets 403 on list, 404 on image, 403 on all giving writes; gifts outsider sees the giver view.
- PersonalListsUnchangedTest (5 tests): identical `list`/`selected` props for owner, manager, giver; same giving statuses; child list with managing parent stays recipient-side and excludes group wishes; manager purge still works for a group member; owner personal wish count unchanged.

## Deviations
- No production leak found; no production code changed.
- [Test calibration] The zero-SQL check ignores the empty-set statement `select * from wish_reservations where 0 = 1`. It is issued by the giver branch (GiverListPage::decorate) when memberB views memberA's empty personal list via the generic `?member=` URLs; it cannot read any row and is legitimate giver-side behaviour, not a group-list leak. Group URLs issue no reservation SQL at all.
- User documentation: no user-visible change (tests only).

## Verification
Pest `tests/Feature/Wishlist tests/Unit/Arch/WishlistPrivacyArchTest.php tests/Feature/Access/EndpointMatrixTest.php`: 544 passed, 4022 assertions, 0 failed. GroupReservationPrivacyTest + ReservationPrivacyTest: 12 passed (370 assertions). PersonalListsUnchangedTest: 5 passed (45 assertions). Pint --test: PASS (1295 files). Compose project fh1605 torn down with `down -v`.

## Self-Check: PASSED
