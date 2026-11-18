---
phase: 14-rebranding-issue-13
plan: 03
subsystem: branding-ui
tags: [brand, logo, theme, offline, style-guide]
requires: [14-01, 14-02, 14-04]
provides: [BrandLogo component, logo in top bar, auth pages, offline page, style guide brand section]
key-files:
  created:
    - resources/js/Components/BrandLogo.vue
    - resources/js/StyleGuide/Sections/05-Brand.vue
    - tests/js/brand/BrandLogo.test.js
  modified:
    - resources/js/Layouts/AuthLayout.vue
    - resources/js/Shell/TopBar.vue
    - public/offline.html
    - lang/en.json
    - lang/sk.json
    - lang/pl.json
    - tests/js/Home.test.js
    - tests/js/shell/AppShell.test.js
    - tests/js/auth/Login.test.js
metrics:
  tasks: 2
  completed: 2026-11-18
---

# Phase 14 Plan 03: Logo placement Summary

The FamilyHUB logo now replaces the text name in the app shell top bar (which also frames the 403 page), above the card on all AuthLayout pages and on the offline page, switching between the colour logo (light) and the white mono logo (dark) with the theme.

## Commits
- Task 1: feat(14-03) FamilyHUB logo in top bar and auth pages
- Task 2: feat(14-03) offline page and style guide brand section

## Details
- BrandLogo renders both images; a non-scoped style block hides one with `display: none` using selectors that mirror tokens.css (explicit data-theme, or system dark unless data-theme is light). Same alt on both. No filter, shadow or opacity.
- Heights: top bar 32, auth 64, offline 56. offline.html uses `<picture>` with prefers-color-scheme and the 14-01 colours.
- Style guide section "Brand" (05-Brand.vue) shows both logos on literal light and dark frames and the mark; new keys `style.brand_light`, `style.brand_dark`, `style.brand_mark`, `style.section.brand` in en, sk, pl (sk and pl drafts, need native review: "Značka", "Marka", "Znak", logo captions).
- Vitest (brand, StyleGuide, a11y-sweep, i18n, Home, shell, auth, errors): 528 tests pass, including axe checks.

## Deviations from Plan
None. The plan did not call for a `Fixes #13` trailer on these tasks, so none was added. Tests were run with the local vitest rather than the docker command.

## User documentation
No manual or help file touched: this plan changes no instruction a user follows, no form field, button or permission (logo described by 14-05). The new style-guide strings are developer-facing only.

## Known Stubs
None.

## Self-Check: PASSED
