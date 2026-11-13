---
phase: 03-to-do
plan: 05
subsystem: to-do
tags: [laravel, inertia, vue, tasks, permissions, detail-panel]
requires: [03-03]
provides:
  - TaskAccess (canEdit, canChangeStatus, canDelete, canChangeVisibility, abilities) and TaskService (create, update, setStatus, delete)
  - PATCH /todo/tasks/{task}, PATCH /todo/tasks/{task}/status, DELETE /todo/tasks/{task} with matrix rows
  - Index props tasks[].abilities and selectedTaskMissing
  - TaskDetailPanel (create, edit, read-only, missing), StatusChips, optimistic card checkbox, Add task for {name}, shell add handler
affects: [03-06, 03-08, 03-09]
key-files:
  created:
    - app/Tasks/TaskAccess.php
    - app/Tasks/TaskService.php
    - app/Rules/HasTodoAccess.php
    - app/Http/Requests/Todo/UpdateTaskRequest.php
    - app/Http/Requests/Todo/UpdateTaskStatusRequest.php
    - app/Http/Controllers/Todo/TaskStatusController.php
    - resources/js/Todo/TaskDetailPanel.vue
    - resources/js/Todo/StatusChips.vue
    - tests/Feature/Todo/TaskCrudTest.php
    - tests/Feature/Todo/AssignmentTest.php
    - tests/Feature/Todo/StatusTest.php
    - tests/js/todo/TaskPanel.test.js
    - tests/js/todo/StatusChips.test.js
  modified:
    - app/Http/Controllers/Todo/TaskController.php
    - app/Http/Requests/Todo/StoreTaskRequest.php
    - routes/web/todo.php
    - tests/Support/EndpointMatrix/todo.php
    - tests/Feature/Todo/BoardTest.php
    - resources/js/Pages/Todo/Index.vue
    - resources/js/Todo/TaskBoard.vue
    - resources/js/Todo/TaskColumn.vue
    - resources/js/Todo/TaskCard.vue
decisions:
  - Controllers load tasks only through Task::visibleTo()->findOrFail (404 for invisible and missing alike), then check TaskAccess (403)
  - Read-only editors who hold the status ability save status chip changes immediately via the status endpoint
  - Panel state (?task=id|new, &assignee=id) lives in the URL; TaskBoard owns it and renders the panel inline
metrics:
  tasks: 2
  completed: 2026-11-13
---

# Phase 3 Plan 05: Task detail panel Summary

Members create, edit, assign, complete and delete tasks through an accessible panel and a card checkbox, with edit rights enforced server-side (creator/assignee edit, any editor changes status, only the creator deletes, no `is_admin` in `app/Tasks`).

## Commits

- 2c0c289 feat(03-05): task edit rights, service and update/status/delete endpoints
- (task 2) feat(03-05): task detail panel, status chips, card checkbox and add handler

## Verification

Full Pest 455 passed, full Vitest 532 passed (axe for create/edit/read-only/missing in en/sk/pl, light/dark), `npm run build` prints SW-PRECACHE OK. `pint --test` over the whole repo still reports 10 pre-existing style issues in files this plan does not own (ThrottleAuthRoutes, RecordAuthEvents, AppServiceProvider, FortifyServiceProvider, routes/console.php, several Audit/Auth/Sessions tests); all files of this plan are clean.

## Deviations from Plan

**1. [Rule 1 - Bug] BoardTest quick-add "ignores extra fields"** posted `assignee_id` and `status` and expected them ignored; the panel create now legitimately honours both, so the test sends `creator_id`, `visibility` and `completed_at` instead (file not in files_modified, needed).

**2. [Rule 3] resources/js/Pages/Todo/Index.vue** (not listed) passes the new `selectedTaskMissing` prop to TaskBoard.

**3. Own form state instead of Inertia `useForm`.** The panel keeps a plain reactive form with a JSON snapshot for dirty tracking and calls `router.post/patch/delete`; this is equivalent and keeps the Vitest router mock simple. Inertia 3 `onHttpException` / `onNetworkError` drive the danger alert and the checkbox revert.

**4. Panel placement.** TaskBoard renders the panel itself (flex row, docked beside the board; overlay/sheet teleport), because Index.vue and AppShell slots were outside the file list.

**5. Toast action not added.** The shared toast still has no action support (outside this plan's files); `flash_data.taskId` remains available. Save/delete toasts come from the server flash (`todo.toast.saved`, `todo.toast.deleted`).

## Notes for later plans

- Panel template has marked insertion comments for Repeat/Reminder (03-09), Visibility (03-08) and History (03-09); `TaskAccess::canChangeVisibility` already exists.
- `TaskService::update(Task, array, ?User)` takes an optional actor so a status key in the payload sets completion data; 03-08/03-09 extend it.
- Completing a task via the checkbox moves it into the collapsed Done list, so the checkbox leaves the DOM and focus cannot stay on it (UI-SPEC wants focus to stay); a fix needs the Done list expanded or a focus fallback, deferred.
- Store flashes `todo.toast.saved` when more than the title is sent, otherwise `todo.quick.added`.

## Known Stubs

None.

## Self-Check: PASSED
