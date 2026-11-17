---
phase: 11-shopping-lists-section-n-kupy-issue-8
plan: 11
subsystem: shopping-list-detail-ui
tags: [shopping, vue, picker, i18n, manual]
requires: ["11-05", "11-06", "11-10"]
provides:
  - "Pages/Shopping/Show.vue: list detail in plan mode, read-only rendering of locked and finished lists, overflow menu (Copy list, Delete list)"
  - "Shopping/ListHeader.vue, ShoppingItemRow.vue, ItemEditDialog.vue, ItemPickerDialog.vue, catalogSearch.js (normalize, matches)"
  - "Lang shopping.detail.*, shopping.item.*, shopping.delete.*, shopping.picker.*, shopping.toast.{items_added,quantity_updated,item_saved,item_removed,list_deleted}, shopping.error.{quantity,save_failed,forbidden,note_too_long,items_count,catalog_unknown}"
  - "Manual section add-items in topic shopping"
affects: [11-12, 11-13, 11-14]
key-files:
  created:
    - resources/js/Pages/Shopping/Show.vue
    - resources/js/Shopping/ListHeader.vue
    - resources/js/Shopping/ShoppingItemRow.vue
    - resources/js/Shopping/ItemEditDialog.vue
    - resources/js/Shopping/ItemPickerDialog.vue
    - resources/js/Shopping/catalogSearch.js
    - tests/js/shopping/ShowPlanMode.test.js
    - tests/js/shopping/ItemPicker.test.js
    - tests/js/shopping/catalogSearch.test.js
  modified:
    - app/Http/Controllers/Shopping/ItemController.php
    - tests/Feature/Shopping/ItemHttpTest.php
    - tests/Feature/Shopping/ListPagesTest.php
    - lang/en.json
    - lang/sk.json
    - lang/pl.json
    - resources/manual/en/shopping.json
    - resources/manual/sk/shopping.json
    - resources/manual/pl/shopping.json
metrics:
  tasks: 2
  completed: 2026-11-17
---

# Phase 11 Plan 11: List detail in plan mode Summary

People with Shopping access can open a list and see its header and items, add several catalog items (picture tiles, accent-insensitive search, steppers with units) or free-text items in one request, edit or remove items, and delete the list; locked and finished lists already render read-only.

## Commits
- 49edfe4 detail page, header, item rows, edit and delete dialogs, lang, ItemController toast tweak, tests (`Refs #8`)
- 84c5f4b item picker, catalog search helper, Show wiring, picker lang, manual section, tests (`Refs #8`)

## Tests
Isolated compose project `fh1111` (own Postgres, scratch folder over storage/logs via an override file), serial, removed with `down -v`. Pint on touched PHP: pass. Full Vitest: 161 files, 5298 tests passed (ShowPlanMode 22, ItemPicker 21, catalogSearch 4, ManualCoverage, HelpCoverage, i18n-phase11; axe for page, edit dialog, both confirmations, picker states, in en, sk, pl, light and dark, pseudo-long text). `tests/Feature/Manual` plus `tests/Feature/Shopping`: 295 passed. Full serial Pest suite: 3264 passed, 1 skipped.

## User documentation
Manual: new section `add-items` ("Adding and changing items") in `shopping.json`, placed after `create-list`, covering the picker, search, quantity and unit, own items and "Also save to catalog", merging of an item already on the list, editing and removing items, decimal comma, read-only states and deleting a list. The topic summary was extended. No help topic (the plan assigns none; the catalog tip lives on the settings tab).

Touched `resources/manual/` files; sk and pl are drafts for native review:
- resources/manual/en/shopping.json, resources/manual/sk/shopping.json, resources/manual/pl/shopping.json

Also for native review: the `shopping.detail`, `shopping.item`, `shopping.delete`, `shopping.picker`, the added `shopping.toast` and `shopping.error` texts in `lang/sk.json` and `lang/pl.json` (including the three-form plurals of the footer label "Add {n} items" and the toast "{count} items added").

## Decisions and notes for later plans
- `Show.vue` header action slot holds "Add items" (`abilities.editItems`) and the overflow `UiMenu` "More actions" (only rendered when it has entries: Copy list, Delete list). 11-12 and 11-13 add their entries to `menuItems` in `Show.vue` and their buttons next to "Add items". `canEdit` is `abilities.editItems && status === 'open'`.
- Rows in locked and finished lists are non-interactive and show Bought as an accent check with text and Unavailable as a warn `UiTag` with the Ban icon. Lang keys `shopping.detail.state_bought` and `state_unavailable` exist for this; 11-13 can reuse them for the toggle labels. Banners `shopping.detail.locked_note` and `archived` are placeholders 11-13 may refine.
- The picker takes `catalog` (null shows the "could not load" alert with free text still usable), `onListIds` (pending catalog ids on the list for the "On list" tag), `units`, `canManageCatalog`. It sends one `form.post` with `{items: [{catalog_item_id|name, quantity (string), unit_id|null, save_to_catalog?}]}`; max 50 enforced client side too.
- Flash `open_picker` is read once in `onMounted` of Show (empty list, `canEdit`).
- Lang keys I added beyond the owned namespaces: `shopping.toast.item_saved` (the 11-05 controller flashes it) and `shopping.error.{note_too_long,items_count,catalog_unknown}` (returned by the 11-05 requests, no earlier plan had texts). 11-12 and 11-13 must not redefine them differently.

## Deviations from Plan
- **[Rule 1/2 - items toast] `ItemController` and `ItemHttpTest` modified (not in `files_modified`).** The 11-05 controller flashed `shopping.toast.items_added` without the top-level `count` that AppShell uses to pick the plural form, so Slovak and Polish would show a wrong or unselected form, and a merge-only add would say "0 items added". Now the toast carries `count`, and an add that only raised quantities flashes `shopping.toast.quantity_updated` with `name` (merged names joined). `toast.merged` is kept. The tests assert both.
- **Remove-item confirmation replaces the edit dialog instead of stacking.** While the confirmation is open the edit dialog is hidden (state kept); Keep item returns to it. Avoids two focus traps.
- **Add items sits in the header on every width, not in a sticky bottom bar on phone.** On phone the header actions row goes full width with a growing primary button. The sticky bar belongs with the locked-mode progress text in 11-13; moving the button then is a template change.
- **Picker: free-text items live in a "Your own items" list inside the dialog** with their own stepper, unit select and remove button (the plan only says they join the selection). The loading skeleton state was not built because the catalog is a server prop and is never loading; `catalog: null` covers the error state.
- **`catalogSearch.normalize` also folds the Polish "ł" to "l"** (NFD does not decompose it), matching the existing `plain()` helper of 11-10. Accent behaviour from the plan is unchanged.
- **Item edit dialog formats the stored quantity** without trailing zeros and with the locale decimal separator ("1,5"), the server accepts both.
- The plan's "Forbidden" 403 toast key `shopping.error.forbidden` is added to the lang files but not wired: a 403 from these endpoints is a hidden-control mismatch that the standard Inertia error page handles.

## Known Stubs
None.

## Threat Flags
None beyond the plan's threat model. T-11-42: names, notes and list names render by text interpolation only (tests render `<img onerror>`, `<b onmouseover>` and `<script>` as text in the page and the picker); T-11-43: controls render from `abilities` only, the server stays authoritative; T-11-44: the picker only receives `catalog` when the server sent it (viewers get `null`/no picker).

## Self-Check: PASSED
