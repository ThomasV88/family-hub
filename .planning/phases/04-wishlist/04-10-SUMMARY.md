---
phase: 04-wishlist
plan: 10
subsystem: wishlist-giving-ui
tags: [vue, reservations, co-buy, shopping-summary, vitest]
requires: [04-06, 04-07, 04-08, 04-09]
provides:
  - Giver status tags, ReservationBox state machine, ContributorList, ShareForm
  - ShoppingSummary ("Your shopping") on giver lists and the occasions screen
affects: [04-12, 04-13]
key-files:
  created:
    - resources/js/Wishlist/ReservationBox.vue
    - resources/js/Wishlist/ContributorList.vue
    - resources/js/Wishlist/ShareForm.vue
    - resources/js/Wishlist/ShoppingSummary.vue
    - tests/js/wishlist/givingFixtures.js
    - tests/js/wishlist/ReservationBox.test.js
    - tests/js/wishlist/ShoppingSummary.test.js
    - tests/js/wishlist/GiftListView.test.js
  modified:
    - resources/js/Wishlist/GiftWishCard.vue
    - resources/js/Wishlist/GiftWishPanel.vue
    - resources/js/Wishlist/GiftListView.vue
    - resources/js/Pages/Wishlist/Occasions.vue
    - tests/js/wishlist/GiftWishPanel.test.js
    - tests/js/wishlist/WishImage.test.js
key-decisions:
  - "GiftWishPanel reads list.owner.name and list.viewer_can_reserve from usePage().props.list, because Pages/Wishlist/Index.vue is owned by 04-12 in this wave and could not be edited to pass props."
  - "ShoppingSummary reads the JSON endpoint GET /wishlist/shopping (plan 04-08) from the component; no page prop carries shopping data."
metrics:
  tasks: 2
  completed: 2026-11-13
---

# Phase 4 Plan 10: Giver reservation and co-buy UI Summary

Giver cards carry one status tag each, the wish panel hosts the ReservationBox (free, mine, other, co-buy open/closed, inline share form, cancel confirm, conflict and error alerts), and "Your shopping" appears on giver lists and the occasions screen, fetched from GET /wishlist/shopping with no page props involved.

## Tasks

| Task | Commit | Result |
|------|--------|--------|
| 1 status tags, ReservationBox, ContributorList, ShareForm | e241d6f | ReservationBox, GiftWishPanel and OwnListPrivacy tests pass |
| 2 ShoppingSummary, GiftListView and Occasions wiring, full suites | f179bd8 | Vitest 884/884, Pest 946/946, build prints SW-PRECACHE OK |

## Notes

- ReservationBox uses the server contract only (pledged, people, i_joined, my_amount); no client sums. Actions use router.post/put/delete with preserveScroll and `loading` buttons (aria-busy). Share amount is normalised ("12,50" to "12.50"); an empty share sends `{amount: null}`.
- With view-only access (`viewer_can_reserve` false) every state renders read-only, with no buttons.
- ShoppingSummary renders nothing when empty or when the request fails; fetch uses same-origin credentials, `cache: no-store`, JSON headers. Rendered only by GiftListView and Occasions; a test mounts Index with own and managed lists and asserts no shopping request.
- OwnListView, OwnWishCard and OwnWishPanel are untouched.

## Deviations from Plan

**1. [Rule 3 - Blocking] Panel context via usePage instead of props.** Index.vue belongs to 04-12 this wave, so GiftWishPanel gets owner name and `viewer_can_reserve` from `usePage().props.list`.

**2. [Rule 3 - Blocking] Existing test mocks updated.** GiftWishPanel.test.js and WishImage.test.js mocked `@inertiajs/vue3` without `usePage`; added a minimal `usePage` to each.

**3. [Rule 1 - Bug] UiCard header landmark.** Using UiCard's `title` rendered a `<header>` that axe flagged as a duplicate banner inside the panel; ReservationBox uses its own h3 instead.

## Known Stubs

None.

## Threat Flags

None. T-04-51..53 mitigations are in place (summary only on giver surfaces with a test, no-store fetch, text interpolation only).

## Self-Check: PASSED
