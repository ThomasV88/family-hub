# Quick 261117-c0r: 360 px bottom navigation and Shopping row layout Summary

Bottom bar with 6 or 7 items now uses a dense/tight style with no mid-word breaks, and the locked Shopping overview row stacks its trailing cluster under the text at 360 px (Refs #8).

## Changes
- `resources/js/Shell/modules.js`: `bottomNavDensity(count)` ('normal' up to 5, 'dense' 6, 'tight' 7+).
- `resources/js/Shell/BottomNav.vue`: `bottom-nav--dense|tight` modifier (none for 5 or fewer), tighter padding and no gap, passes `density` to items.
- `resources/js/Shell/NavItem.vue`: `density` prop; 20 px icon, smaller label font, `is-dense`/`is-tight` classes; bottom labels use `overflow-wrap: normal`, `word-break: normal`, `hyphens: manual`, nowrap with ellipsis and a `title`; `overflow-wrap: anywhere` now only for the sidebar variant. 44 px target and `is-current` rules unchanged.
- `resources/js/Shopping/ListRow.vue`: title and meta wrap at word boundaries, main column `flex: 1 1 12rem`, and a `@media (max-width: 480px)` rule that wraps the row and puts the trailing cluster on its own line under the text. `UiListRow.vue` untouched.
- Tests: new `tests/js/shell/BottomNav.test.js`; 360 px case and CSS-source check in `tests/js/shopping/PseudoLong.test.js`; 360 px cases in `tests/js/a11y-sweep.test.js` (Shopping/Index and empty, 3 locales, 2 themes, plus AppShell pseudo-long); 360 added to the AppShell text-length widths in `tests/js/shell/AppShell.test.js`.

## Verification
- Full Vitest: 168 files, 5540 tests passed (includes ManualCoverage, HelpCoverage, PseudoLong, a11y-sweep).
- `npx vite build` into a scratch folder: OK (service worker regenerated, gitignored).
- Pint/Pest not run: no PHP changed.

## Deviations
- Tests were written together with the implementation rather than strictly red first (jsdom does no layout, so the CSS-source assertions are the regression guard).
- Also added 360 to the width list in `tests/js/shell/AppShell.test.js` (not listed in plan files_modified; covers the plan's "AppShell 360 px" done criterion).
- Tasks 1 to 3 went into one code commit, as Task 3 specifies.

## User documentation
No user-visible change: layout-only CSS fix at narrow widths, no wording change. No `resources/manual/` or `resources/help/` file touched.
