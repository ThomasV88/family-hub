---
phase: 03-to-do
verified: 2026-11-13T00:00:00Z
status: human_needed
score: 4/4 roadmap criteria verified in code and tests; live delivery unverified
human_verification:
  - test: "Real iPhone/iPad installed-PWA push (SC4)"
    expected: "Due-task push arrives on the home-screen PWA after permission grant; tap opens /todo?task=ID"
    why_human: "Needs real device, VAPID keys and a deployed environment (plan 03-12, deferred in DEFERRED.md)"
  - test: "Real email reminder via Stalwart SMTP (SC4)"
    expected: "Reminder email arrives, localised, at the due-minus-offset time"
    why_human: "Needs live mail service and scheduler/worker running"
  - test: "Desktop/Android permission prompt and denied-state recovery"
    expected: "Prompt appears on enabling push; denied state shows recovery hint"
    why_human: "Browser behaviour"
  - test: "End-of-phase UI checks (phone/desktop layouts, panel, quick add, reminders)"
    expected: "Per SUMMARY manual checks and 03-UI-SPEC"
    why_human: "Visual/interaction; not run (DEFERRED.md)"
---

# Phase 3: To-Do Verification Report

**Goal:** The family can track shared and personal tasks together, including recurring ones, and gets reminded when tasks are due.
**Mode:** mvp. **Re-verification:** No.

## Automated runs
- Pest (container): 546 passed, 2842 assertions.
- Vitest: 46 files, 656 tests passed.
- `npm run build`: succeeded; `check-sw-precache` printed "SW-PRECACHE OK".

## Truths
| # | Truth | Status | Evidence |
|---|---|---|---|
| 1 | Create task with title, notes, due date, status, assignee | VERIFIED | TaskCrudTest (create fully specified task, update rights, validation) pass |
| 2 | Personal / Family / Selected visibility; others see only permitted | VERIFIED | VisibilityTest: Personal forced to creator, 404 for others, admin with To-do grant gets no access to Personal, Selected limited to members |
| 3 | Daily/weekly/monthly/custom recurrence, schedule vs completion, month-end and DST correct | VERIFIED | tests/Unit/Recurrence/MonthEndTest.php, DstTest.php, RuleBuilderTest, CompletionTest present and passing |
| 4 | Due-task reminders by email and push; iOS push for installed PWA | UNCERTAIN (human_needed) | Code and tests (OutboxTest, ReminderEnqueueTest, PushDeliveryTest, SW push handler) pass with fakes; no live delivery, VAPID or device check (03-12 deferred) |

## Specific confirmations
- Completion race protection: `tasks_one_open_per_series` partial unique index (WHERE series_id NOT NULL AND completed_at/skipped_at NULL) in the tasks migration, plus `lockForUpdate` and UniqueConstraintViolationException handling in `app/Tasks/TaskCompletionService.php`.
- Outbox dedupe: `dedupe_key` unique column; `OutboxWriter` insert-or-ignore; payload holds ids and timestamps only.
- Push payload: exactly `['title','url','tag']` (asserted in PushDeliveryTest); notes never sent. Note: "title" is the task title, truncated to 80 chars, so it is title/url/tag rather than a generic string. Acceptable per tests.
- Service worker: handlers show a notification always, same-origin url only; build check-sw-precache passes.

## Requirements
TODO-01..05 SATISFIED (code + tests). TODO-06 NEEDS HUMAN (live delivery). REQUIREMENTS.md checkboxes still unticked (bookkeeping).

## Anti-patterns
None blocking found in spot checks. Known gap per DEFERRED.md: toast "Open task" action not implemented (non-goal for criteria).

## Gaps
None blocking. Phase passes automated verification; live-ops items remain human_needed until 03-12 is executed.
