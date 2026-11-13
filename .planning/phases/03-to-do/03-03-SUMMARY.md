---
phase: 03-to-do
plan: 03
subsystem: to-do
tags: [laravel, inertia, vue, tasks, visibility, quick-add]
requires: [03-01]
provides:
  - tasks and task_members schema (all Phase 3 columns, CHECKs, tasks_one_open_per_series)
  - App\Models\Task with scopeVisibleTo / scopeOpen, TaskFactory states
  - GET /todo board and POST /todo/tasks quick add, matrix rows
  - Todo board UI (person columns, filter chips, quick add) and Overview quick add
affects: [03-04, 03-05, 03-06]
tech-stack:
  added: []
  patterns: [record-level visibility scope with no admin bypass, shared useQuickAdd composable]
key-files:
  created:
    - config/family.php
    - database/migrations/2026_11_14_100000_create_tasks_table.php
    - app/Tasks/TaskStatus.php
    - app/Tasks/TaskVisibility.php
    - app/Models/Task.php
    - database/factories/TaskFactory.php
    - app/Http/Requests/Todo/StoreTaskRequest.php
    - app/Http/Controllers/Todo/TaskController.php
    - routes/web/todo.php
    - tests/Support/EndpointMatrix/todo.php
    - tests/Feature/Todo/BoardTest.php
    - resources/js/Pages/Todo/Index.vue
    - resources/js/Todo/ (TaskBoard, TaskColumn, TaskCard, QuickAddBar, FilterChips, dueLabel.js, useQuickAdd.js)
    - tests/js/todo/Board.test.js, tests/js/todo/dueLabel.test.js
  modified:
    - resources/js/Shell/modules.js
    - resources/js/Dashboard/QuickAdd.vue
    - tests/js/Home.test.js
    - tests/js/a11y-sweep.test.js
    - tests/js/shell/AppShell.test.js
decisions:
  - Quick add is title only; Family visibility, creator as assignee and the family timezone are server defaults
  - Board filter and selected task live in the URL (?filter=, ?task=) via router.get replace
metrics:
  tasks: 3
  completed: 2026-11-13
---

# Phase 3 Plan 03: To-do board and quick add Summary

Members create tasks by title from the board or the Overview and see them in per-person columns, with every query going through `Task::visibleTo` (creator, Family, or Selected membership; no `is_admin` anywhere).

## Commits

- 1a211b0 feat(03-03): schema, scope, endpoints, matrix rows, BoardTest
- 3a34fcc feat(03-03): board page, columns, cards, filters, quick add bar
- (task 3 commit) feat(03-03): Overview quick add and To-do nav wired

## Verification

Full Pest 409 passed, Vitest 479 passed, `npm run build` prints SW-PRECACHE OK. Pint is clean on all files of this plan; `pint --test` over the whole repo reports pre-existing style issues in files this plan does not own (routes/console.php, several tests/Feature Audit/Auth/Sessions tests), left untouched.

## Deviations from Plan

**1. [Rule 1 - Bug] tests/js/shell/AppShell.test.js** used the Tasks nav item as its coming-soon example; with `href: '/todo'` it is a link, so the test now uses the Wishlist item (not in files_modified, needed for the nav change).

**2. Toast action not implemented.** The plan asks for toast `todo.quick.added` with action `todo.quick.open`. The shared `useToast`/`UiToastRegion` has no action support and is outside this plan's files, so the toast comes from the server flash (`toast.key = todo.quick.added`, rendered by AppShell) without the "Open task" action. `flash.data.taskId` is still provided for a later plan to add the action.

**3. BoardTest uses `component('Todo/Index', false)`** in the first commit only so the task 1 commit was green before the page existed; the page now exists.

**4. Added `resources/js/Todo/useQuickAdd.js`** (not listed) so board and Overview share one create call.

## Notes for later plans

- With QuickAdd no longer registering a shell add handler, the topbar Add / FAB still open the coming-soon panel until plan 03-06 changes the shell fallback (as specified).
- Module cards on Home (`ModuleCard` Open) still open coming-soon; untouched.
- Card checkbox is rendered disabled (03-05 wires it). `selectedTaskId` is computed from `?task=` in TaskBoard.
- English vue-i18n locale is `en-GB`, so dates print "10 Nov".

## Known Stubs

Card checkbox disabled and card click only sets `?task=` (panel arrives in 03-05); both intentional.

## Self-Check: PASSED
