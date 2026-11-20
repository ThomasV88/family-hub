---
phase: 16-group-wishlists-issue-25
plan: 06
subsystem: wishlist-ui
tags: [wishlist, groups, vue, i18n, help]
requires: [16-02, 16-04]
provides:
  - GroupChips and GroupPanel components, Index.vue wiring (`groupPanel`, exposed `openGroupManage`)
  - wishlist.group.* texts and wishlist.toast.group_* in en, sk, pl
  - help topics wishlist.group_mode, wishlist.group_members, screen group_panel, reworded wishlist.reservation
affects: [16-07, 16-08]
key-files:
  created:
    - resources/js/Wishlist/GroupChips.vue
    - resources/js/Wishlist/GroupPanel.vue
    - tests/js/i18n-phase16.test.js
    - tests/js/wishlist/GroupChips.test.js
    - tests/js/wishlist/GroupPanel.test.js
  modified:
    - resources/js/Pages/Wishlist/Index.vue
    - lang/en.json
    - lang/sk.json
    - lang/pl.json
    - resources/help/topics.json
    - resources/help/en.json
    - resources/help/sk.json
    - resources/help/pl.json
metrics:
  tasks: 2
  completed: 2026-11-20
---

# Phase 16 Plan 06: Group list UI and texts Summary

Members can create, rename, re-mode (with confirmation), re-member, leave and delete group lists from the Wishlist page, and all group texts and help tips exist in EN, SK and PL with parity enforced by a test.

## Commits
- 985df8f: texts and help tips (Task 1)
- c2fe236: GroupChips, GroupPanel, Index.vue wiring and tests (Task 2)

All commits carry `Refs #25`, no attribution.

## What was built
- `GroupChips.vue`: labelled chip row under the person chips (both context slots), selected chip pressed, navigation through the unsaved-changes guard to `/wishlist?group=id`, "New group list" button when `canCreate`; renders nothing when empty and not creatable.
- `GroupPanel.vue` (UiDetailPanel): name (max 80), mode radios with hints and HelpTip `wishlist.group_mode`, member checkboxes with HelpTip `wishlist.group_members` (viewer always checked and disabled). Create posts `{name, mode, member_ids (others only)}`. Edit sends PATCH `{name, mode}` and PUT members (full set incl. viewer) only when changed; a mode change opens a confirmation and sends `confirm_mode_change: true` only after confirming ("Keep as is" restores the mode, no request). Leave and Delete have confirmations (delete names the list). Server error keys are shown through `t()`. A member without `can_manage` sees it read-only with only Leave. Text interpolation only, no v-html.
- `Index.vue`: new props `groups`, `group_candidates`; `groupPanel` ref mutually exclusive with wish panels (opening one closes the other through the guard); `navigate()` uses `group` for group lists; `openGroupManage` exposed for the 16-07 list header. The "New group list" button needs `group_candidates` non-empty (server sends `[]` without Wishlist edit).
- Help: two new tips, screen "Group list panel", `wishlist.reservation` reworded in all three languages (meaning kept).

## Deviations from Plan

**1. [Rule 2] Extra error keys.** `wishlist.group.error.name_too_long` and `mode_invalid` were added (16-02 emits them, the plan list omitted them).

**2. Test calibration.** Axe tests run with real timers (fake `setTimeout` hangs axe); help tips are asserted via `data-help-topic` after seeding the help store. Nothing else.

**3. Local build.** `npm run build` was run inside the container with `--outDir /tmp/fhbuild1606` (scratch outDir, as earlier executors), result `SW-PRECACHE OK`. `public/build` untouched.

No PHP touched, so no Pest run and no compose project was needed.

## Verification
- Vitest: `tests/js/wishlist tests/js/help tests/js/manual tests/js/brand tests/js/ui tests/js/i18n-phase16.test.js tests/js/i18n-phase4.test.js`: 38 files, 527 tests passed (includes HelpCoverage with no unknown/unused topic, BrandName guard, GroupPanel 18 tests incl. axe en/sk/pl light/dark and pseudo-long, GroupChips 5, i18n-phase16 8).
- Build: success, SW-PRECACHE OK.
- Greps: v-html 0; `confirm_mode_change` and HelpTip present in GroupPanel.

## User documentation
User-visible change (new buttons, panel, modes). Help tips updated here (CLAUDE.md rule 2). Manual topics (`resources/manual/{en,sk,pl}/wishlist.json`, `who-sees-what.json`) are written in 16-08 of this phase after 16-07 finalises the list views and ship in the same PR. No `resources/manual/` files touched in this plan.

## SK and PL drafts needing native review
All `wishlist.group.*` strings (including `error.*`), the five `wishlist.toast.group_*` toasts, the help screen `group_panel`, topics `wishlist.group_mode`, `wishlist.group_members` and the reworded `wishlist.reservation` text in `lang/sk.json`, `lang/pl.json`, `resources/help/sk.json`, `resources/help/pl.json`. Names to check: SK "Spoločný zoznam", "Darčeky od ostatných", "Spoločné nákupy"; PL "Lista wspólna", "Prezenty od innych", "Wspólne zakupy". PL uses gender-neutral forms like "Otrzymał(a)".

## Known Stubs
None. Group list header (calls `openGroupManage`, mode tag, banners) and per-mode list views are 16-07.

## Self-Check: PASSED
