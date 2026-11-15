---
phase: 03-to-do
fixed_at: 2026-11-13T18:00:00Z
review_path: .planning/phases/03-to-do/03-REVIEW.md
iteration: 1
findings_in_scope: 10
fixed: 10
skipped: 0
status: all_fixed
---

# Phase 3: Code Review Fix Report

**Fixed at:** 2026-11-13
**Source review:** .planning/phases/03-to-do/03-REVIEW.md
**Iteration:** 1

**Summary:**
- Findings in scope: 10 (WR-01..WR-08, IN-01, IN-05; IN-03 folded into WR-01)
- Fixed: 10
- Skipped: 0

Verification at the end: full Pest 563 passed, Vitest 657 passed, `npm run build` printed SW-PRECACHE OK.
The work was done directly on `main` (not in a separate worktree) because the container, `vendor/`, `node_modules/` and `.env` live in the main checkout.

## Fixed Issues

### WR-01 (+ IN-03): Closed occurrences are immutable

**Files modified:** `app/Models/Task.php`, `app/Tasks/TaskAccess.php`, `app/Tasks/TaskService.php`, `app/Http/Controllers/Todo/TaskController.php`, `TaskStatusController.php`, `TaskSkipController.php`, `tests/Feature/Todo/CompletionTest.php`
**Commit:** 2bb5fcd
**Applied fix:** A single `Task::isClosedOccurrence()` (series_id or rule, and completed/skipped), the duplicate copies removed. `TaskAccess::abortIfClosed` answers 409 for update, status, skip and delete. `canEdit` and `canDelete` also exclude closed rows, so UI abilities match. The existing status test changed from 422 to 409. Tests cover all four endpoints and a row whose rule was cleared. Requires human verification (logic change).

### WR-02 / WR-03: Outbox retries bounded, no resend after delivery

**Files modified:** `app/Console/Commands/DispatchOutbox.php`, `app/Jobs/SendOutboxItem.php`, `tests/Feature/Notifications/OutboxTest.php`
**Commit:** 709fec4 (one commit for both, they touch the same code)
**Applied fix:** Stuck rows with attempts >= 5 become `failed` ("stuck"), the reset only touches attempts < 5, and the claim requires attempts < 5. Stuck threshold raised from 10 to 30 minutes, job `$timeout = 300`. After a successful send the status update is retried 3 times and a failure there never goes to `recordFailure`; push bookkeeping (last_success_at, delete) is best-effort. Remaining risk (documented in code): if the database stays down right after a successful send, the row stays `sending` and is retried after 30 minutes, so delivery is at-least-once in that narrow case. Requires human verification.

### WR-04: Markdown injection in reminder email

**Files modified:** `app/Notifications/TaskReminderNotification.php`, `tests/Feature/Notifications/TaskReminderMailTest.php`
**Commit:** a1cfa7b
**Applied fix:** Title and member name are backslash-escaped for every ASCII punctuation character before going into the greeting and body line (subject stays plain). Test uses a link, image, bold and bare URL title plus a malicious name and asserts the only anchors point at the app.

### WR-05: Push subscription survives logout

**Files modified:** `resources/js/Shell/AccountMenu.vue`, `app/Listeners/ForgetPushSubscriptionOnLogout.php` (new), `tests/js/AccountMenuStyleGuide.test.js`, `tests/Feature/Notifications/PushSubscriptionTest.php`
**Commit:** 5fa1b88
**Applied fix:** Logout first unsubscribes this device (`disable()`, 3 s cap, never blocks) and sends `push_endpoint` with `/logout`. A `Logout` listener deletes that user's row for the endpoint as a server-side fallback. Limitation: the server cannot map a session to a device, so revoking other sessions does not remove their subscriptions (not feasible without binding sessions to subscriptions).

### WR-06: Endpoint re-validated at send time, redirects disabled

**Files modified:** `app/Push/WebPushSender.php`, `tests/Feature/Notifications/WebPushSenderTest.php` (new)
**Commit:** 56cdc4c
**Applied fix:** `PushEndpointRule::allowed` is checked before sending (bad endpoint returns Expired, no request). The installed minishlink v11 takes a PSR-18 client, not a timeout, as the third constructor argument, so the old `new WebPush(..., 15)` would throw a TypeError caught as `Failed` on every send; the sender now builds a Guzzle client (timeout 15, connect 5, `allow_redirects` false). The library counts 3xx as success, so success now requires a 2xx status. Tests use a Guzzle MockHandler (success, 410, bad endpoint, redirect not followed).

### Extra: bcmath in the app image

**Files modified:** `docker/app/Dockerfile`
**Commit:** a9e7891
**Applied fix:** The image had neither GMP nor BCMath; web-push raises a warning that Laravel turns into an exception, so real sends would fail. Added `bcmath` to the extension install. The local dev image was rebuilt to run tests; the staging and production images need a rebuild through the normal deploy.

### WR-07: Long-overdue schedule series no longer ends

**Files modified:** `app/Recurrence/RRuleRecurrenceEngine.php`, `tests/Feature/Todo/CompletionTest.php`, `tests/Unit/Recurrence/RecurrenceEngineTest.php`
**Commit:** a866761
**Applied fix:** The 1000-occurrence cap became a 50000 iteration guard and the 10-year horizon counts from max(anchor, now) in Schedule mode, so the rule keeps its original alignment (no DTSTART jump). Tests: daily overdue 3 years, biweekly rule stays aligned, and the old "returns null beyond the cap" unit test was rewritten (it asserted the buggy behavior). The optional "flash a toast when the result is null" was not added (null now only happens beyond 10 years from now). Requires human verification.

### WR-08: Stale reminder after reschedule or reassignment

**Files modified:** `app/Notifications/Outbox/TaskReminderHandler.php`, `tests/Feature/Notifications/OutboxTest.php`
**Commit:** 3def10f
**Applied fix:** `stillRelevant` recomputes the reminder instant with `ReminderTimeCalculator` and compares it to `payload.remind_at` (the task's `remind_at` column is already nulled on enqueue, so it cannot be compared), and requires the user to be in `ReminderRecipients::for($task)`. The test helper now writes the real instant. Tests: moved due date, reassigned, changed offset. Requires human verification.

### IN-01: Service worker URL guard

**Files modified:** `resources/js/sw.js`
**Commit:** d3f3c24
**Applied fix:** Rejects backslashes and requires the parsed URL origin to equal the worker origin. No unit test (the worker is not unit-testable here); the build and SW-PRECACHE check pass.

### IN-05: Push subscription store race and test send

**Files modified:** `app/Http/Controllers/Settings/PushSubscriptionController.php`, `tests/Feature/Notifications/PushSubscriptionTest.php`
**Commit:** 0abb3b4
**Applied fix:** `updateOrCreate` on `endpoint_hash`; `touch()` kept only when nothing changed, because recency decides which subscriptions the cap keeps (so it is not fully redundant as the review said). The test send tries the 3 newest devices.

## Skipped Issues

None in scope. IN-02, IN-03 (folded into WR-01), and IN-04 were out of the requested scope (IN-02 and IN-04 not done).

---

_Fixed: 2026-11-13_
_Fixer: Claude (gsd-code-fixer)_
_Iteration: 1_
