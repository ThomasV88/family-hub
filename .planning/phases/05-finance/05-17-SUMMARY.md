---
phase: 05-finance
plan: 17
subsystem: finance
tags: [overview, charts, svg, budgets, home-card, a11y]
requires: [05-14, 05-15]
provides: [niceTicks, SpendingByCategoryChart, BalanceChart, ChartTableToggle, SummaryCards, BudgetList, BudgetPanel, Finance/Overview page, HomeStillToPayCard, finances nav href /finance]
affects: [05-18, 05-19]
key-files:
  created:
    - resources/js/Finance/charts/niceTicks.js
    - resources/js/Finance/charts/SpendingByCategoryChart.vue
    - resources/js/Finance/charts/BalanceChart.vue
    - resources/js/Finance/charts/ChartTableToggle.vue
    - resources/js/Finance/SummaryCards.vue
    - resources/js/Finance/BudgetList.vue
    - resources/js/Finance/BudgetPanel.vue
    - resources/js/Pages/Finance/Overview.vue
    - resources/js/Dashboard/HomeStillToPayCard.vue
    - tests/js/finance/niceTicks.test.js
    - tests/js/finance/charts.test.js
    - tests/js/finance/Overview.test.js
    - tests/js/finance/overviewFixtures.js
    - tests/js/finance/BudgetPanel.test.js
    - tests/js/finance/HomeStillToPayCard.test.js
  modified:
    - resources/js/Pages/Home.vue
    - resources/js/Shell/modules.js
    - tests/js/shell/moduleAccess.test.js
    - tests/js/shell/AppShell.test.js
    - lang/en.json
    - lang/sk.json
    - lang/pl.json
requirements: [FIN-06, FIN-07, FIN-05]
completed: 2026-11-13
---

# Phase 5 Plan 17: Overview UI, charts, budgets and Home card Summary

Finance Overview tab with per-currency summary cards, still to pay, budgets with an edit panel, and two hand-rolled SVG charts (category bars, balance line) that each have an exact table alternative. Home gets a counts-only "Still to pay" card, and Finance in the nav now opens /finance.

## What was built
- **niceTicks**: exact BigInt integer ticks (step 1/2/5 x 10^k), at most 3 ticks, includes 0 when the range crosses it. Property test: 500 seeded cases (seed 20261113, +-1e13).
- **SpendingByCategoryChart**: list rows with server amount and percent, 12px SVG bars (share of the longest row via a BigInt ratio, 2px minimum, "Other categories" in `--color-border-strong`), role img wrapper with the exact aria text, table alternative of all categories with a Total row.
- **BalanceChart**: one polyline plus markers, 3 gridlines with exact formatted labels (middle label dropped when crowded), month labels thinned under 400px, text labels at last/min/max, dashed labelled zero line when crossing zero, 6/12 month chips, ResizeObserver re-attached when switching between chart and table, table with Month/Balance/Change (+ or U+2212, sr-only up/down). Colours only via CSS variables, forced-colors fallback, 150ms fade under no-preference motion.
- **Overview page**: FinanceShell with FAB for editors, one block per server currency (ISO heading only with more than one), StillToPayCard omitted without `hasRecurring`, budgets, charts side by side from 800px container width. Budget rows open `?budget=` through a partial visit (`only: ['panelBudget']`).
- **BudgetList / BudgetPanel**: progress, near/over tags and the over amount are all server values; panel creates (POST), edits (PATCH) and removes (DELETE with confirm, default focus Keep budget) with `from_month` = selected month; Currency select only for All accounts spanning several currencies; categories with an existing budget in that currency are hidden.
- **HomeStillToPayCard** (counts only, no money formatting) rendered in Home only when `financeCard` exists; otherwise the existing Finance ModuleCard stays.

## Deviations from Plan

**1. [Rule 3 - Blocking] Missing i18n key**: `finance.budget.err_save` (generic save failure for the panel alert) did not exist in lang files; added to en/sk/pl with parity kept. Commit aec2c72.

**2. [Rule 3 - Blocking] AppShell test index**: `tests/js/shell/AppShell.test.js` picked the "coming soon" nav item by position 3 (Finance); with the real href it is now position 4 (Health). Updated that one index. Added an assertion in `moduleAccess.test.js` for the new href (there was no old null-href assertion there).

**3. TDD ordering**: tests and implementation were committed together per task (two commits) rather than separate RED/GREEN commits.

**4. UiProgress over-budget fill**: UI-SPEC section 8 says the fill turns danger over 100%, section "Chart colour use" says never colour alone; implemented the danger fill as an extra on top of the mandatory tag with exact over amount.

## Verification
- Vitest full: 84 files, 1415 passed. `npm run build` OK (SW-PRECACHE OK). Pest full: 1318 passed (NoFloatScanTest included). Compose project fh0517 torn down.
- Greps: no literal hex in charts/*.vue, no v-html, no parseFloat/toFixed in charts, no Number( outside charts, `href: '/finance'` present once, no MoneyAmount/formatMinor in the Home card.

## Known Stubs
None. Home ModuleCard "Open" for Finance (shown only when no `financeCard`) still opens the coming-soon panel as before; the nav item itself links to /finance.

## Threat surface
No new surface beyond the plan's register: charts and labels use text nodes and attribute bindings only; the UI computes no sums or percentages (Change column in the balance table is an exact BigInt difference of two server values, as the UI-SPEC requires).

## Commits
b4abb7a feat(05-17) charts; aec2c72 feat(05-17) Overview, budgets, Home card, nav.

## Self-Check: PASSED
