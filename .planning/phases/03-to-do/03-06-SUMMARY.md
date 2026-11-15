---
phase: 03-to-do
plan: 06
subsystem: to-do
tags: [laravel, inertia, vue, dashboard, shell]
requires: [03-03, 03-05]
provides:
  - Home dueSoon prop (lazy, through Task::visibleTo, null without To-do view)
  - DueSoonCard on the Overview
  - shell triggerAdd fallback to /todo?task=new, edit-gated FAB, topbar Add task and quick add
key-files:
  created:
    - resources/js/Dashboard/DueSoonCard.vue
    - tests/Feature/Todo/DashboardCardTest.php
    - tests/js/todo/DueSoonCard.test.js
  modified:
    - app/Http/Controllers/HomeController.php
    - resources/js/Pages/Home.vue
    - resources/js/Shell/shellState.js
    - resources/js/Shell/FabAdd.vue
    - resources/js/Shell/TopBar.vue
    - tests/js/Home.test.js
    - tests/js/shellState.test.js
    - tests/js/shell/AppShell.test.js
decisions:
  - Overdue ordering is done in SQL (CASE against family-local today and time), limit 5, with a separate count for "more"
  - Home falls back to the old ModuleCard when the dueSoon prop is absent
metrics:
  tasks: 2
  completed: 2026-11-13
---

# Phase 3 Plan 06: Overview Due soon card and shell add entry points Summary

The Overview shows up to 5 of my open dated tasks (overdue first) via `Task::visibleTo`, and the FAB and topbar Add task lead into `/todo?task=new`, only for To-do editors.

## Commits

- 28f1418 feat(03-06): dueSoon prop on the Overview through Task::visibleTo
- f9670b6 feat(03-06): Due soon card, shell add entry points lead into To-do, edit-gated
- (follow-up) Pint fix for an unused import in DashboardCardTest

## Verification

Full Vitest 576 passed, full Pest 506 passed, `npm run build` prints SW-PRECACHE OK. Pint is clean on this plan's PHP files. grep gates: no `notes` in HomeController, `/todo?task=new` present and `openComingSoon('tasks'` absent in shellState.js.

## Deviations from Plan

None of substance. Task-row links are plain anchors with a click handler calling `router.visit` (modified clicks pass through). The a11y-sweep Home case (not in files_modified) keeps passing through the ModuleCard fallback; axe for the card itself is covered in Home.test.js (en/sk/pl) and DueSoonCard.test.js. Session was interrupted by a WSL crash; all work was already committed.

## Known Stubs

None.

## Self-Check: PASSED
