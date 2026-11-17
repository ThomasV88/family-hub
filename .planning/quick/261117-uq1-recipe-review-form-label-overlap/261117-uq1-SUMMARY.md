# Quick 261117-uq1 Summary

Recipe form review mode: field labels now wrap cleanly with their "From the page" tags (UiField label row `flex-wrap`, number-field row as equal auto-fill grid tracks that stack on narrow containers).

## Changes
- `resources/js/Components/ui/UiField.vue`: `.ui-field__head` wraps, label `min-width: 0` + `overflow-wrap: anywhere`, children `max-width: 100%`.
- `resources/js/Pages/Recipes/Form.vue`: `.rec-form__basics` is `repeat(auto-fill, minmax(min(100%, 10rem), 1fr))`; removed the servings full-row span and the container-query 3-column override.
- `label-after` is used only in Form.vue (title, description, servings, prep, cook), all through UiField; categories and the ingredient/step headings already wrap (`rec-form__h2` is flex-wrap).
- Tests: new `tests/js/recipes/FormLayout.test.js` (CSS source), review-mode pseudo-long cases at 360/800 px (en/sk/pl) in `tests/js/recipes/PseudoLong.test.js`, axe sweep for Recipes/Form (review) at 360 and 800 px in `tests/js/a11y-sweep.test.js`.
- Manual/help: no user-visible change (layout only), no `resources/manual/` file touched.

## Results
Full Vitest: 196 files, 6665 tests passed. Build: `vite build` OK (SW precache 12 entries).

## Deviations
`npm run build` fails with EACCES: the ignored `public/build/assets` is root-owned (from an earlier run). Verified with `vite build --outDir` into a scratch directory instead; `check-sw-precache.mjs` was not run against it. Needs a one-off `sudo chown`/cleanup of `public/build` to rebuild in place.

Commit: code commit `fix(quick-261117-uq1)` (see git log).
