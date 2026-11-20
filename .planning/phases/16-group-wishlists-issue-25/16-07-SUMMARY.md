---
phase: 16-group-wishlists-issue-25
plan: 07
subsystem: wishlist-ui
tags: [wishlist, groups, vue, views]
requires: [16-03, 16-04, 16-06]
provides:
  - GroupHeader and SharedListView components, per-mode and per-membership view branch in Index.vue
  - group wishes in OwnWishPanel (group_id, received_by) and GiftWishPanel (editable, edit)
  - group sections in Your shopping
affects: [16-08]
key-files:
  created:
    - resources/js/Wishlist/GroupHeader.vue
    - resources/js/Wishlist/SharedListView.vue
    - tests/js/wishlist/groupFixtures.js
    - tests/js/wishlist/GroupViews.test.js
  modified:
    - resources/js/Pages/Wishlist/Index.vue
    - resources/js/Wishlist/GiftListView.vue
    - resources/js/Wishlist/OwnWishPanel.vue
    - resources/js/Wishlist/GiftWishPanel.vue
    - resources/js/Wishlist/ShoppingSummary.vue
    - tests/js/wishlist/ShoppingSummary.test.js
metrics:
  tasks: 2
  completed: 2026-11-20
---

# Phase 16 Plan 07: Group list views Summary

Group lists render per mode and membership: gifts-mode members get the unchanged recipient view with a group header, shared-mode members get a reservation-aware gallery they can add to and edit, non-members get the giver view titled "group list" with member names, and Your shopping lists group reservations under the group name.

## Commits
- 4069fd6: GroupHeader, SharedListView, GiftListView group title, Index.vue branch (Task 1)
- 93e8aba: OwnWishPanel group_id and received_by, GiftWishPanel editable, ShoppingSummary group sections (Task 2)

Both carry `Refs #25`, no attribution.

## What was built
- `GroupHeader.vue`: name, mode tag, "Members: A and B" (Intl.ListFormat), mode banner for members, Manage list button when `group.can_manage` (emits `manage`, wired to `openGroupManage`). Reads only `list.group`.
- `SharedListView.vue`: ShoppingSummary, context slot, GiftWishCard gallery (reservation tags), add tile when `can_edit`, collapsible received section with "Received by {name}".
- `Index.vue`: `ownSide` (recipient or shared member) uses OwnWishPanel with `group-id`; shared members open a wish in GiftWishPanel (`editable`), and Edit wish sets `editingWishId` to switch to OwnWishPanel in edit mode; closing resets it. Header "Add" label uses `wishlist.group.add`. OwnListView is untouched (empty `git diff`, OwnListPrivacy test unchanged and green).
- `GiftListView.vue`: for group lists shows `wishlist.group.list_title`, the member line and the group empty state, no avatar.
- `ShoppingSummary.vue`: sections with `group` link to `/wishlist?group={id}&wish={id}`, heading `wishlist.group.shopping_group`, keys `g{id}` vs `p{id}`.

## Deviations from Plan

**1. Own list add tile label.** For gifts-mode members the add tile inside OwnListView reads "Add wish" (OwnListView must not change); the header/shell add and the panel heading use "Add wish to {name}". Tests assert this.

**2. GroupHeader not used in the giver view.** GiftListView renders its own group title and member line, avoiding a duplicated heading; GroupHeader appears only for the recipient and shared-member views.

**3. OwnWishPanel `ownerId` no longer required** (default null) so a group create passes only `groupId`.

**4. Local build** run with `--outDir /tmp/fhbuild1607` (scratch), `public/build` untouched.

No new lang keys were needed (only 16-06 keys used). No PHP touched, no Pest, no compose project.

## Verification
- Vitest `tests/js/wishlist tests/js/help tests/js/manual tests/js/brand tests/js/ui tests/js/i18n-phase16.test.js tests/js/i18n-phase4.test.js`: 39 files, 553 tests passed (GroupViews incl. axe en/sk/pl for the three views and pseudo-long; BrandName and HelpCoverage green).
- Build: SW-PRECACHE OK.
- Greps: v-html 0 in new components; OwnListView diff empty.

## User documentation
User-visible (new views). Manual topics are written in 16-08 of this phase (same PR); help tips were done in 16-06. No `resources/manual/` files touched here.

## SK and PL drafts for native review
No new strings in this plan; it renders the 16-06 `wishlist.group.*` texts (header_members, mode_tag_*, banner_*, add, list_title, received_by, edit_wish, empty_*, shopping_group) in `lang/sk.json` and `lang/pl.json`, which still need native review.

## Known Stubs
None.

## Threat Flags
None.

## Self-Check: PASSED
