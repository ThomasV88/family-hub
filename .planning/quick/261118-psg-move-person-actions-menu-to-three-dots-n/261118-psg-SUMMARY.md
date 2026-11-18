# Quick 261118-psg: Person actions menu moved to three dots next to the name

The Health person header menu is now an icon-only three-dots button (Ellipsis, same pattern as UploadBatch.vue) inside a new `.person-header__title-row` flex row beside the h2. The separate "More actions for this person" secondary button below the action row is gone.

## Changes
- `resources/js/Health/PersonHeader.vue`: Ellipsis import, title row (h2 + menu, `flex: none` on the menu, `min-width: 0` on the h2 so a long name wraps with `overflow-wrap: anywhere`), removed old menu block and its two CSS rules. Items, permissions (`person.canEdit`), `data-testid="header-menu"` and the container-query rule are unchanged. Label comes from `health.person.more_actions` as aria-label (UiButton icon-only) and as `title` (on the UiMenu wrapper, since UiButton has no title passthrough).
- `tests/js/health/PersonManage.test.js`: asserts aria-label, empty visible text, title, placement in the title row next to the h2, and that no other header button carries the label.
- Manual: one paragraph added to the `people-access` section describing the three-dots menu.

Commit: see `git log` ("feat(health): move person actions menu to three-dots next to name").

## Touched manual/help files
- `resources/manual/en/health.json`
- `resources/manual/sk/health.json` (DRAFT, needs native review)
- `resources/manual/pl/health.json` (DRAFT, needs native review)
- `resources/help/*`: none touched. No help tip mentioned the old button.

## Tests
- Vitest: tests/js/health, help, manual, brand, a11y-sweep, settings/AiBulk, ui/AvatarCallSites: 47 files, 3257 tests, all pass (after fixing my own test to read the title on `.ui-menu`).
- Pest `tests/Feature/Manual` + `tests/Feature/Help` via `docker compose -p fhpsg261118 run --rm app php artisan test`: 49 passed. Project torn down with `down -v`.

## Deviations
- The 40px target was not changed: icon-only UiButton is `--control-md` (40px, 44px on coarse pointers) and square via `aspect-ratio: 1`.
- `title` sits on the wrapper div, not on the button itself.
- Manual paragraph is placed in `people-access` (not `archive-delete`), same block count in all three locales.

## Not verified visually
No browser or dev server was run. Layout at 360/820/1280 px with a long name is reasoned from CSS: title row is `display:flex; min-width:0`, the h2 shrinks and wraps, the 40px menu is `flex: none`, so the dots stay beside the name. Exercise it on staging.
