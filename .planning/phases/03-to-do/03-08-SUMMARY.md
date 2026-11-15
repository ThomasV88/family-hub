---
phase: 03-to-do
plan: 08
subsystem: todo
tags: [visibility, privacy, vue, laravel]
requires: [03-05]
provides: [per-task visibility write path, VisibilityField]
affects: [app/Tasks/TaskService.php, resources/js/Todo/TaskDetailPanel.vue]
key-files:
  created: [resources/js/Todo/VisibilityField.vue, tests/Feature/Todo/VisibilityTest.php, tests/js/todo/VisibilityField.test.js]
  modified: [app/Tasks/TaskService.php, app/Http/Requests/Todo/StoreTaskRequest.php, app/Http/Controllers/Todo/TaskController.php, resources/js/Todo/TaskDetailPanel.vue, tests/Feature/Todo/TaskCrudTest.php, tests/Feature/Todo/BoardTest.php]
decisions:
  - "Create omits visibility from the payload when Family (server default)"
  - "Non-creator sending visibility/member_ids gets 403 after validation"
metrics:
  completed: 2026-11-13
requirements: [TODO-03]
---

# Phase 03 Plan 08: Task visibility Summary

The creator sets Personal, Family or Selected members per task; the server enforces the invariants in one transaction and there is no admin bypass.

## Commits
- 98d5d31: write path (service, requests, controller) and VisibilityTest
- 5858cfa: VisibilityField, panel wiring, Vitest tests

## What was built
- TaskService: Personal forces assignee = creator and detaches members; Selected syncs members plus creator and assignee and rejects an assignee outside the set (assignee_id = todo.error.assignee); Family detaches.
- Requests: visibility enum, member_ids required/min 1 for Selected (todo.error.selected_none), each id checked with HasTodoAccess.
- Controller: only TaskAccess::canChangeVisibility applies visibility; otherwise 403 when sent.
- VisibilityField: radio cards with hints, member checklist (creator excluded), read-only line for non-creators; panel restricts Assigned to (disabled on Personal, creator plus chosen on Selected, resets on drop-out).

## Deviations
- [Rule 1] TaskCrudTest and BoardTest (03-05) posted `visibility: personal` and asserted it was ignored; removed that field from the posts since visibility is now legitimately honoured.

## Verification
Full Vitest (576), Pest (506), build (SW-PRECACHE OK) pass. Pint --test reports style issues only in pre-existing tests/Feature/Auth and Sessions files (not owned by this plan, left untouched). No is_admin in app/Tasks or Todo controllers.

## Self-Check: PASSED
