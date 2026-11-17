---
phase: 11-shopping-lists-section-n-kupy-issue-8
plan: 06
subsystem: shopping-settings-ui
tags: [shopping, settings, vue, i18n, manual, help]
requires: ["11-04"]
provides:
  - "GET /settings/shopping (settings.shopping) behind Shopping edit, SettingsPayload (catalog, stores, units, canManageUnits)"
  - "Settings section 'shopping' (icon shopping-cart) for members with Shopping edit"
  - "Pages/Settings/Shopping.vue with CatalogTab, StoresTab, UnitsTab; CatalogImage, ImageUploadField"
  - "resources/js/Shopping/units.js: unitLabel and formatQuantity for every Shopping screen"
  - "Lang namespaces shopping.settings.*, shopping.image.*, shopping.unit.*, shopping.error.{name_invalid,unit_unknown}, settings.section.shopping"
  - "Manual topic shopping (covers settings:shopping, sections stores-catalog and units adminOnly), help topics shopping.catalog|store|units"
affects: [11-07, 11-09, 11-10, 11-11]
key-files:
  created:
    - app/Http/Controllers/Settings/ShoppingSettingsController.php
    - app/Shopping/Queries/SettingsPayload.php
    - resources/js/Pages/Settings/Shopping.vue
    - resources/js/Shopping/CatalogImage.vue
    - resources/js/Shopping/ImageUploadField.vue
    - resources/js/Shopping/Settings/CatalogTab.vue
    - resources/js/Shopping/Settings/StoresTab.vue
    - resources/js/Shopping/Settings/UnitsTab.vue
    - resources/js/Shopping/units.js
    - resources/manual/en/shopping.json
    - resources/manual/sk/shopping.json
    - resources/manual/pl/shopping.json
    - tests/Feature/Shopping/SettingsPageTest.php
    - tests/js/shopping/SettingsShopping.test.js
    - tests/js/shopping/units.test.js
    - tests/js/i18n-phase11.test.js
  modified:
    - routes/web/settings-shopping.php
    - tests/Support/EndpointMatrix/settings-shopping.php
    - app/Support/SettingsSections.php
    - resources/js/Pages/Settings/Index.vue
    - resources/js/Layouts/SettingsLayout.vue
    - lang/en.json
    - lang/sk.json
    - lang/pl.json
    - resources/help/topics.json
    - resources/help/en.json
    - resources/help/sk.json
    - resources/help/pl.json
    - tests/Feature/Settings/SettingsShellTest.php
metrics:
  tasks: 2
  completed: 2026-11-17
---

# Phase 11 Plan 06: Settings > Shopping Summary

Members with Shopping edit get Settings > Shopping with three tabs (item catalog with pictures and default units, stores with logos, built-in plus administrator-added units), gated server-side, documented in the manual and contextual help in en, sk and pl, with a shared unit and quantity formatter that every later Shopping screen reuses.

## Commits
- 45b820a route, controller, SettingsPayload, Settings section entry, matrix row, SettingsPageTest and shell test (`Refs #8`)
- 0db5362 page, components, units.js, lang, help, manual, Vitest files (`Refs #8`)

## Tests
Isolated compose project `fh1106` (own Postgres, scratch folder over storage/logs via an override file), serial, removed with `down -v`. Pint on all touched PHP: pass. Task 1 set (SettingsPage, Settings, EndpointMatrix): 297 passed. Full Vitest: 156 files, 5220 tests passed (ManualCoverage, HelpCoverage, HelpPlacement, i18n-phase11, new Settings > Shopping tests with axe for the page and every dialog in en, sk, pl, light and dark, pseudo-long text). Full serial Pest: 3216 passed, 1 skipped (includes `tests/Feature/Manual`, `Help`, EndpointMatrix, SensitiveGateMatrix).

## User documentation
Manual: new topic `resources/manual/{en,sk,pl}/shopping.json` (category modules, order 35, covers `settings:shopping`, sections `stores-catalog` and `units` with `adminOnly`). Help: `resources/help/topics.json` plus `en.json`, `sk.json`, `pl.json` (area `shopping`, screen `shopping_settings`, topics `shopping.catalog`, `shopping.store`, `shopping.units`; tips placed beside each tab heading). Lang: `lang/{en,sk,pl}.json`. Slovak and Polish manual, help and lang texts are drafts for native review (manual files, help files and the `shopping.*` lang subtree all need it).

## Decisions and notes for later plans
- `units.js` exports `localeCode`, `unitLabel(unit, quantity, t, locale)` and `formatQuantity(quantity, unit, t, locale)`; a unit is `{key, names}` as in the Settings payload (`names` = `{en, sk, pl}` for custom units). Quantity 1 of pcs or of no unit formats as an empty string; a fraction takes the last plural form. `locale` may be the app code or the vue-i18n locale.
- `CatalogImage` takes `kind` (`catalog` or `store`), `id`, `version` (null shows the placeholder), `size`; it builds `/shopping/{catalog|stores}/{id}/image/thumb?v={version}`.
- The payload gives `imageVersion` and `logoVersion` as null when there is no picture (the version column alone cannot tell).
- The plan owner list of lang keys was extended: this plan also adds `shopping.error.name_invalid` and `shopping.error.unit_unknown` (11-03 and 11-04 requests return them but no earlier plan added the texts) and `shopping.settings.err_duplicate_store`. Later plans must not redefine these keys with a different value; the i18n-phase11 test covers the whole `shopping` subtree, so every later key must be added in en, sk and pl together.
- `SettingsSections` uses `AccessGate::allows(..., Shopping, Edit)`; an administrator without a Shopping grant does not see the section (and gets 403 on the page), as in the matrix.

## Deviations from Plan
- Row actions are two icon-only buttons (Pencil, Trash2, 44px, `aria-label` with the item name) instead of a `UiMenu`: `UiMenu` has no icon-only trigger, so a menu would show a visible label on every row. Behaviour is unchanged.
- Removing an existing picture is applied on Save (together with the JSON save) rather than through its own confirm dialog: nothing is destroyed until Save, cancelling the dialog changes nothing, and it avoids a confirm dialog stacked on a dialog. The four planned `shopping.image.remove_*` confirm strings were therefore not added.
- New items: the id needed for the picture upload is found by name in the refreshed props after the JSON save (the write routes redirect back and carry no id). If the picture upload fails, the item stays saved, the dialog stays open with the error, and Save retries only the picture.
- Added `Shopping/Settings/` helper-free duplication (CatalogTab and StoresTab share structure) rather than a generic component, to keep the three planned component files.
- Small unrelated fix while touching the Settings index: the `shield-check` icon was missing from the index `ICONS` map (security row had no icon); added with `shopping-cart`.
- Store duplicate error: the server answers the item key `shopping.settings.err_duplicate` for stores too; the store dialog maps it to the store wording `err_duplicate_store`.

## Known Stubs
None.

## Threat Flags
None beyond the plan's threat model (T-11-24 to T-11-27 covered by SettingsPageTest 403 rows, the matrix row and Vue text interpolation only; no `v-html`).

## Self-Check: PASSED
