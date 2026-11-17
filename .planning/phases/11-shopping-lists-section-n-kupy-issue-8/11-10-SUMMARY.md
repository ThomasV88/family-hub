---
phase: 11-shopping-lists-section-n-kupy-issue-8
plan: 10
subsystem: shopping-overview-ui
tags: [shopping, vue, navigation, i18n, manual]
requires: ["11-05", "11-06"]
provides:
  - "Shopping entry (ShoppingCart) as the last MODULES item, ACCESS_KEY.shopping, in sidebar and bottom bar"
  - "Pages/Shopping/Index.vue: Open and Archive tabs, rows, search above 10 open lists, archive paging, empty, loading and error states"
  - "Shopping/CreateListDialog.vue, Shopping/CopyListDialog.vue (reused by the detail page in 11-11), ListRow.vue"
  - "Shopping/listStatus.js: statusTag, reminderTag, formatListDate, plain (accent-free search text), firstName"
  - "Lang shopping.overview.*, shopping.create.*, shopping.copy.*, shopping.status.*, shopping.toast.{list_created,copied}, shopping.error.{store_unknown,responsible_not_eligible,copy_invalid}, nav.shopping, module.shopping"
  - "Manual topic shopping covers module:shopping with sections overview, create-list, copy"
affects: [11-11, 11-12, 11-13, 11-14]
key-files:
  created:
    - resources/js/Pages/Shopping/Index.vue
    - resources/js/Shopping/ListRow.vue
    - resources/js/Shopping/CreateListDialog.vue
    - resources/js/Shopping/CopyListDialog.vue
    - resources/js/Shopping/listStatus.js
    - tests/js/shopping/Overview.test.js
    - tests/js/shopping/CopyListDialog.test.js
  modified:
    - resources/js/Shell/modules.js
    - lang/en.json
    - lang/sk.json
    - lang/pl.json
    - resources/manual/en/shopping.json
    - resources/manual/sk/shopping.json
    - resources/manual/pl/shopping.json
    - resources/manual/en/who-sees-what.json
    - resources/manual/sk/who-sees-what.json
    - resources/manual/pl/who-sees-what.json
    - tests/js/shell/moduleAccess.test.js
    - tests/js/shell/AppShell.test.js
    - tests/js/i18n-phase11.test.js
    - tests/Feature/Shopping/ListPagesTest.php
metrics:
  tasks: 2
  completed: 2026-11-17
---

# Phase 11 Plan 10: Shopping navigation, overview, create and copy Summary

Members with a Shopping grant now reach the module from the navigation, see the lists they may access on an Open and Archive overview, and create or copy a list from dialogs, all documented in the manual in en, sk and pl.

## Commits
- a24fbfe navigation entry, overview page, dialogs, listStatus helpers, lang, manual, tests (`Refs #8`). Tasks 1 and 2 are one commit, as the plan allows: ManualCoverage needs `module:shopping` as soon as the MODULES entry exists.

## Tests
Isolated compose project `fh1110` (own Postgres, scratch folder over storage/logs via an override file), serial, removed with `down -v`. Pint on the touched PHP file: pass. Full Vitest: 158 files, 5251 tests passed (includes ManualCoverage, HelpCoverage, i18n-phase11, shell, new Overview and CopyListDialog tests with axe for lists, empty states, archive tab and both dialogs in en, sk, pl, light and dark, pseudo-long names). ListPagesTest plus `tests/Feature/Manual`: 17 passed (Index component now asserted with the default ensure_pages_exist check). Full serial Pest suite: 3264 passed, 1 skipped.

## User documentation
Manual: `shopping.json` gains `module:shopping` in `covers`, an updated summary and the sections `overview`, `create-list` and `copy` (placed before `stores-catalog`, the order is overview, create-list, copy, stores-catalog, units). `who-sees-what.json` table gets the row "A shopping list" and the link `[Shopping](shopping#overview)` in the link line. `getting-started.json` was not touched: its section `moving-around` does not name modules. No help topic (the plan assigns none).

Touched `resources/manual/` files, sk and pl are drafts for native review:
- resources/manual/en/shopping.json, resources/manual/sk/shopping.json, resources/manual/pl/shopping.json
- resources/manual/en/who-sees-what.json, resources/manual/sk/who-sees-what.json, resources/manual/pl/who-sees-what.json

Also for native review: the whole `shopping.overview`, `shopping.create`, `shopping.copy`, `shopping.status`, `shopping.toast` subtrees and the three added `shopping.error` texts in `lang/sk.json` and `lang/pl.json`, plus `nav.shopping` and `module.shopping`.

## Decisions and notes for later plans
- `CopyListDialog` takes `open` (v-model), `list {id, name}`, optional `nameMax`; it posts `{name, keep}` to `/shopping/lists/{id}/copy` and the server redirects with the toast. 11-11 reuses it from the detail menu.
- `listStatus.js` helpers are for 11-11 to 11-13: `statusTag(status)` gives `{key, icon, variant}`, `reminderTag(list)` the warn tag, `formatListDate(iso, locale, {time})`.
- Toast keys follow what the 11-05 server flashes: `shopping.toast.list_created` and `shopping.toast.copied` (the plan names `created`; the controller key `list_created` is the one the UI needs).
- The overview archive tab reads the server `archive` payload; paging goes through `router.get('/shopping', {tab: 'archive', page})`, and a failed page load shows the error state with Try again.

## Deviations from Plan
- **Row action is a copy button, not an overflow menu.** `UiMenu` has no icon-only trigger (its trigger always shows a text label, see 11-06) and the menu would hold one item, so each row has an icon-only ghost button with `aria-label` "Copy list {name}", shown only when `abilities.copy`. Behaviour as specified.
- **Store logo thumbnails in the create dialog select.** A native select cannot show images, so the options are text and the logo of the chosen store is shown beside the select.
- **Lang keys added beyond the owned namespaces:** `shopping.error.store_unknown`, `responsible_not_eligible` and `copy_invalid` (returned by the 11-05 requests, no earlier plan had texts), and `shopping.toast.list_created` instead of `created`.
- **Empty and error states use heading level 2** (the page title is the h1; axe flagged h3).
- **Navigation:** as the plan records, no "More" place exists, so six modules fit in the bottom bar for a member with every grant; the 360 px visual check stays with 11-14.

## Known Stubs
None.

## Threat Flags
None beyond the plan's threat model: T-11-39 (server-filtered payload, the client only searches within what it received), T-11-40 (text interpolation only, covered by a test that renders `<img onerror>` as text), T-11-41 (nav hiding is cosmetic; routes stay gated server-side).

## Self-Check: PASSED
