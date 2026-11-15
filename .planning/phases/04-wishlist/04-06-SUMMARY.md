---
phase: 04-wishlist
plan: 06
subsystem: ui
tags: [vue, inertia, wishlist, privacy, a11y, i18n]
requires: ["04-01", "04-05 (props contract only)"]
provides:
  - "Pages/Wishlist/Index: single viewer_is_recipient branch between OwnListView and GiftListView; ?member/?wish URL state"
  - "OwnListView/OwnWishCard/OwnWishPanel (reservation-blind), GiftListView/GiftWishCard/GiftWishPanel (context slot, room for 04-10)"
  - "PersonChips, WishImage, LinksEditor, money.js, ShellAdd (renderless)"
  - "Settings > Members > Wishlist tab (WishlistManagersTab)"
  - "shell add handler can carry its own label (topbar and FAB)"
affects: [04-07, 04-08, 04-10, 04-12]
key-files:
  created:
    - resources/js/Pages/Wishlist/Index.vue
    - resources/js/Wishlist/{PersonChips,OwnListView,GiftListView,OwnWishCard,GiftWishCard,WishImage,OwnWishPanel,LinksEditor,GiftWishPanel,ShellAdd}.vue
    - resources/js/Wishlist/money.js
    - resources/js/Components/Settings/WishlistManagersTab.vue
    - tests/js/wishlist/{fixtures,Gallery,OwnWishPanel,GiftWishPanel,OwnListPrivacy,money}.test.js (fixtures.js is data)
    - tests/js/settings/WishlistManagersTab.test.js
  modified:
    - resources/js/Shell/{modules.js,shellState.js,TopBar.vue,FabAdd.vue}
    - resources/js/Components/Settings/MemberPanel.vue
    - resources/js/Pages/Settings/Members.vue
    - tests/js/shell/AppShell.test.js
decisions:
  - "Panels render inside Index.vue next to the gallery (as TaskBoard does), so the shell add hook is a renderless ShellAdd child (the shell is provided by AppShell, a child of the page)"
  - "WishImage pending status renders a UiSkeleton (UI-SPEC Loading); none, failed and ready-without-url render the Gift placeholder"
  - "Received wishes open OwnWishPanel read-only (disabled fieldset) with only Delete and Close, since no un-receive copy exists in 04-01"
metrics:
  completed: 2026-11-13
---

# Phase 4 Plan 06: Wishlist Vue slice Summary

The Wishlist page (person chips, own and giver card galleries, owner create/edit panel with up to 5 links, read-only giver panel), the nav entry and the admin's wishlist-managers tab, built against the 04-05 contract. The owner view is proven blind to injected reservation data.

## Commits
- 786ce68: page, galleries, cards, image, chips, panels, money helpers, nav entry, shell label support
- f3df9fc: OwnWishPanel tests
- 8745fa1: WishlistManagersTab, MemberPanel tab, GiftWishPanel and OwnListPrivacy tests

## Verification
- Full Vitest 53 files / 773 tests pass; `npm run build` prints SW-PRECACHE OK; full Pest 754 passed (04-05 backend was present in the tree by then); compose project fh0406 torn down.
- Grep gates: `viewer_is_recipient` in Index.vue; no `reserv` in OwnListView/OwnWishCard; no `v-html`; no `wishlist.cancel` in OwnWishPanel; `noopener noreferrer` in GiftWishPanel; `ZxCanaryGiver` x8 in the privacy test.
- OwnListPrivacy: innerHTML (including the teleported panel) identical with and without injected `reservation`, `status`, `viewer_can_reserve`, `shopping` for own, managed child and Received group (also expanded); no `ZxCanaryGiver`/`7777`; no rendered `wishlist.status.*` / `wishlist.res.*` string in en/sk/pl.

## Deviations from Plan

1. **[Rule 3 - Blocking] Shell add label.** TopBar and FabAdd hardcode "Add task" and show only for todo edit; "Add wish" in the topbar/FAB was impossible. `registerAddHandler(fn, label)` now sets a reactive `addLabel`; TopBar/FabAdd use it and also show when a handler is registered. Files outside the plan list: shellState.js, TopBar.vue, FabAdd.vue (existing shell tests still pass).
2. **[Rule 3] MemberPanel needs the member list** for the managers tab: added an optional `members` prop and passed it from Pages/Settings/Members.vue (not in the plan list). MemberPanel's dirty state now ORs permissions and wishlist tabs.
3. **Own save path uses `router.post/patch` instead of `useForm`** (same as TaskDetailPanel; gives snapshot-based dirty tracking, simpler to test). Behavior as specified.
4. **Extra component** `ShellAdd.vue` (renderless) for the shell hook; no `WishlistPage` split because panels live in Index.vue.
5. Priority chips reuse `UiChip` in a roving radiogroup (same pattern as StatusChips); avatars are 32px (UiAvatar only allows 32/40; spec says 24).
6. Commit grouping: panel components needed by Index.vue landed in the first commit, so there is no separate RED/GREEN split per task.

## Known Stubs
None. Image upload/load controls, polling (04-07), ReservationBox and ShoppingSummary (04-10) and OccasionStrip (04-12) are intentionally absent; their insertion points are the `context` slot and the GiftWishPanel body.

## Threat Flags
None beyond the plan's register (text interpolation only, https/http hrefs from props, no wish URL used as img src, tested).

## Self-Check: PASSED
