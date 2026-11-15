---
phase: 03-to-do
plan: 09
subsystem: todo
tags: [recurrence, reminders, vue, laravel]
requires: [03-04, 03-07, 03-08]
provides: [TaskCompletionService, skip endpoint, RecurrenceFields, ReminderField, series history]
affects: [app/Tasks/TaskService.php, resources/js/Todo/TaskDetailPanel.vue]
key-files:
  created:
    - app/Tasks/TaskCompletionService.php
    - app/Tasks/TaskRecurrence.php
    - app/Http/Controllers/Todo/TaskSkipController.php
    - app/Http/Controllers/Todo/NextOccurrence.php
    - resources/js/Todo/RecurrenceFields.vue
    - resources/js/Todo/ReminderField.vue
    - resources/js/Todo/recurrenceState.js
    - tests/Feature/Todo/{Completion,RecurrenceInput,ReminderInput}Test.php
    - tests/js/todo/{RecurrenceFields,ReminderField}.test.js
  modified: [TaskService, TaskAccess, StoreTaskRequest, TaskController, TaskStatusController, routes/web/todo.php, EndpointMatrix/todo.php, TaskDetailPanel.vue, TaskBoard.vue, Pages/Todo/Index.vue, tests/js/todo/TaskPanel.test.js]
decisions:
  - "Client sends the structured pattern only; server derives monthDay from the due date and builds the RRULE"
  - "Saving an untouched occurrence keeps a stored clamped rule (day 31 stays 31 on the Feb 28 occurrence); a due date change re-anchors"
  - "Next-due toast: server flashes flash_data {nextDue, toast key} and no server toast; TaskBoard formats the date with Intl in the family zone"
metrics:
  completed: 2026-11-13
requirements: [TODO-04, TODO-05, TODO-06]
---

# Phase 03 Plan 09: Recurring tasks and reminders Summary

Members can repeat tasks (daily, weekdays, monthly day or last day, custom interval; from schedule or completion), complete or skip occurrences with exactly one next occurrence created under a row lock, see history, and choose a per-task reminder.

## Commits
- 94db99a: recurrence save, TaskCompletionService, skip endpoint, history prop, matrix row (Task 1)
- ae37b6f: remind_before_minutes rules and ReminderInputTest (Task 3 server)
- ea0ca86: RecurrenceFields, ReminderField, panel wiring (history, Skip, toasts) and Vitest tests (Tasks 2 and 3 UI)

## Behavior notes
- complete/skip: lockForUpdate re-read, closed row is a no-op (null); UniqueConstraintViolationException from tasks_one_open_per_series is caught outside the transaction and counted as a no-op (tested with an engine that races an insert).
- Status endpoint and panel update with done on a recurring task go through TaskCompletionService; TaskAccess.status is false for completed/skipped recurring rows (422 on the endpoint, 403 still wins for view-only).
- Request validation uses an `after()` hook (errors on `recurrence`, `recurrence.interval`, `recurrence.weekdays`, `remind_before_minutes`) instead of a closure rule; same keys as specified.
- Update with `recurrence` key absent keeps the rule; present null clears it (series_id kept); clearing the due date clears rule, mode and time.
- Board props gained recurrence, recurrenceMode, remindBefore; index prop `history` (null unless ?task is a visible recurring id, max 50, via visibleTo).

## Deviations from Plan
1. [Rule 1 - Bug] Toast contract: a server `toast` flash would render a raw `{date}` (AppShell translates it without params and the date must be Intl-formatted client side). The key is carried in `flash_data.toast` instead and TaskBoard shows the toast; this also covers card checkbox, panel save and Skip in one place. TaskCard.vue needed no change (its checkbox is already disabled by `abilities.status`, which is now false for history rows).
2. [Rule 2] Untouched-save protection for a clamped monthly rule (see decisions); not in the plan but without it any edit of the Feb 28 occurrence would rewrite day 31 to day 28.
3. The create payload omits recurrence and remind keys when they are defaults (existing exact-payload tests stay valid); the reminder key is omitted when there is no due date.

## Verification
Full Pest 546 passed, full Vitest 656 passed, build prints SW-PRECACHE OK. `pint --test` reports 10 style issues, all in pre-existing files not owned by this plan (Middleware/ThrottleAuthRoutes, Listeners, Providers, routes/console.php, Audit/Auth/Sessions tests); my files are clean. Containers (project fh0309) torn down.

## Known Stubs
None.

## Self-Check: PASSED
