---
phase: 03-to-do
reviewed: 2026-11-13T00:00:00Z
depth: standard
files_reviewed: 52
files_reviewed_list:
  - app/Recurrence/LocalTime.php
  - app/Recurrence/RRuleRecurrenceEngine.php
  - app/Recurrence/RecurrenceEngine.php
  - app/Recurrence/RecurrenceMode.php
  - app/Recurrence/RecurrencePattern.php
  - app/Recurrence/RuleBuilder.php
  - app/Tasks/ReminderRecipients.php
  - app/Tasks/ReminderTimeCalculator.php
  - app/Tasks/TaskAccess.php
  - app/Tasks/TaskCompletionService.php
  - app/Tasks/TaskRecurrence.php
  - app/Tasks/TaskService.php
  - app/Tasks/TaskStatus.php
  - app/Tasks/TaskVisibility.php
  - app/Observers/TaskReminderObserver.php
  - app/Jobs/SendOutboxItem.php
  - app/Console/Commands/EnqueueReminders.php
  - app/Console/Commands/DispatchOutbox.php
  - app/Console/Commands/PruneOutbox.php
  - app/Console/Commands/PushVapid.php
  - app/Models/Task.php
  - app/Models/OutboxItem.php
  - app/Models/NotificationPreference.php
  - app/Models/PushSubscription.php
  - app/Notifications/NotificationPreferences.php
  - app/Notifications/TaskReminderNotification.php
  - app/Notifications/Outbox/OutboxHandler.php
  - app/Notifications/Outbox/OutboxHandlers.php
  - app/Notifications/Outbox/OutboxStatus.php
  - app/Notifications/Outbox/OutboxWriter.php
  - app/Notifications/Outbox/TaskReminderHandler.php
  - app/Http/Controllers/Todo/TaskController.php
  - app/Http/Controllers/Todo/TaskSkipController.php
  - app/Http/Controllers/Todo/TaskStatusController.php
  - app/Http/Controllers/Todo/NextOccurrence.php
  - app/Http/Controllers/Settings/NotificationSettingsController.php
  - app/Http/Controllers/Settings/PushSubscriptionController.php
  - app/Http/Controllers/HomeController.php
  - app/Http/Requests/Todo/StoreTaskRequest.php
  - app/Http/Requests/Todo/UpdateTaskRequest.php
  - app/Http/Requests/Todo/UpdateTaskStatusRequest.php
  - app/Http/Requests/Settings/StorePushSubscriptionRequest.php
  - app/Http/Requests/Settings/UpdateNotificationPreferenceRequest.php
  - app/Rules/HasTodoAccess.php
  - app/Push/PushConfig.php
  - app/Push/PushEndpointRule.php
  - app/Push/PushResult.php
  - app/Push/PushSender.php
  - app/Push/WebPushSender.php
  - routes/web/todo.php
  - routes/web/notifications.php
  - routes/console.php
  - database/migrations/2026_11_14_100000_create_tasks_table.php
  - database/migrations/2026_11_14_110000_create_notification_outbox_table.php
  - database/migrations/2026_11_14_110001_create_notification_preferences_table.php
  - database/migrations/2026_11_14_120000_create_push_subscriptions_table.php
  - resources/js/sw.js
  - resources/js/push.js
findings:
  critical: 0
  warning: 8
  info: 5
  total: 13
status: issues_found
---

# Phase 3: Code Review Report

**Reviewed:** 2026-11-13
**Depth:** standard
**Files Reviewed:** 52 PHP/JS/SQL files (Vue components under resources/js/Todo and Pages/Todo were only skimmed for what they send to the server)

## Summary

The core security properties hold. Every read and write path I traced goes through `Task::visibleTo`: index, history, update, status, skip, destroy, dueSoon, and the reminder handler through `ReminderRecipients::canSee`. There is no admin bypass, and `AccessGate` also grants admins nothing. `assignee_id` and `member_ids` are validated by `HasTodoAccess`, and the Selected-visibility rule is enforced in `TaskService::enforceVisibility`. Outbox payloads carry ids only, the push payload is title, url and tag only, and VAPID keys are only printed by the explicit generate command. `p256dh` and `auth` are encrypted at rest. The push endpoint allowlist is strict, and the service worker stays precache-only. The month-end clamp (`BYMONTHDAY=28..N;BYSETPOS=-1`), the DST handling and the completion lock plus partial unique index look correct.

I found no BLOCKER-class defect. The warnings cover one reachable 500 and series-integrity hole, outbox retry and idempotency edges, one content-injection path into email, and push lifecycle and defense-in-depth gaps.

## Warnings

### WR-01: A closed occurrence can be edited, its recurrence cleared, then re-opened, which violates the one-open-per-series invariant and returns a 500

**File:** `app/Tasks/TaskAccess.php:19` (`canEdit`), `app/Tasks/TaskService.php:114-128` (`applyRecurrence`), `app/Http/Controllers/Todo/TaskStatusController.php:20`
**Issue:** `canEdit` does not exclude closed occurrences (completed rows stay visible in the index for 30 days). `TaskService::update` happily applies `recurrence: null` to such a history row, which clears `recurrence_rule` and `recurrence_mode`. The row then fails `isClosedOccurrence()` because that check requires `isRecurring()`. The status endpoint now accepts `todo` and `applyStatus` nulls `completed_at`, so the row is open again while the real open occurrence (same `series_id`) exists. The `tasks_one_open_per_series` unique index rejects the save with an uncaught `UniqueConstraintViolationException`, which the user sees as a 500. History rows can also have their due date and title rewritten, which falsifies history.
**Fix:** Make a closed occurrence immutable, and define "closed" by `series_id`, not by whether the rule is still set:
```php
public function isClosedOccurrence(Task $task): bool
{
    return ($task->series_id !== null || $task->isRecurring())
        && ($task->completed_at !== null || $task->skipped_at !== null);
}
public function canEdit(User $user, Task $task): bool
{
    return $this->hasEditGrant($user) && ! $this->isClosedOccurrence($task)
        && ($task->creator_id === $user->id || $task->assignee_id === $user->id);
}
```
Remove the duplicate private `TaskService::isClosedOccurrence` and inject `TaskAccess`.

### WR-02: A poison outbox row retries forever because `attempts` is never checked at claim or reset time

**File:** `app/Console/Commands/DispatchOutbox.php:23`, `app/Jobs/SendOutboxItem.php:40-48`
**Issue:** `MAX_ATTEMPTS` is only enforced in `recordFailure`, which only runs when the job catches a Throwable. If the worker dies mid-send (OOM, queue timeout kill, fatal error), the row stays `sending`. `DispatchOutbox` resets it to `pending` 10 minutes later and the claim increments `attempts` again. The row never reaches `failed`, and `attempts` keeps growing (smallint overflow at 32767). Each cycle can re-send mail or push.
**Fix:** In the stuck-row reset, fail rows that have exhausted their attempts:
```sql
update notification_outbox set status = 'failed', last_error = 'stuck', updated_at = ?
  where status in ('queued','sending') and updated_at < ? and attempts >= 5;
```
Run this before the reset-to-pending statement, and add `and attempts < 5` to the reset. Also guard the claim with `->where('attempts', '<', self::MAX_ATTEMPTS)`.

### WR-03: Duplicate sends are possible, because a slow send is treated as stuck and a post-send DB failure causes a resend

**File:** `app/Console/Commands/DispatchOutbox.php:23`, `app/Jobs/SendOutboxItem.php:64-66`
**Issue:** The reset window is a fixed 10 minutes since `updated_at`, with no heartbeat. A `sending` row whose send is still in progress (a push to 10 devices at 15 s timeout each, or a slow SMTP) can be reset to `pending` and sent again by a second worker. Also, if `sendMail()` succeeds and `finish()` then throws (DB blip), the catch block calls `recordFailure`, which sets the row to `pending` and the mail is sent a second time. This is at-least-once delivery without the documented dedupe guarantee.
**Fix:** Keep sending-time work well below the reset window and set an explicit `public $timeout` on the job (for example 120 s) so the window is meaningful. Separate the "send" and "mark" steps: if `sendMail()` returned without throwing, retry only the status update, and never route a post-send exception to `recordFailure`.

### WR-04: Markdown and link injection in the reminder email from user-controlled task title and member name

**File:** `app/Notifications/TaskReminderNotification.php:47-49`
**Issue:** The task title (set by any family member who can assign a task) and `$notifiable->name` are interpolated into `->line()` and `->greeting()`. Laravel's mail template renders lines as Markdown, so a title such as `[Confirm your bank login](https://evil.example)` becomes a clickable link in an email sent from the family's own domain to another member. Raw HTML is escaped by Laravel, but Markdown links, emphasis and images are not. The title is also placed in the subject. Symfony encodes CR/LF, so header injection is not possible there.
**Fix:** Escape Markdown metacharacters before interpolation, for example:
```php
$safe = fn (string $s) => preg_replace('/([\\\\`*_{}\[\]()#+\-.!|<>~])/', '\\\\$1', $s);
->line(trans('notifications.reminder_body', ['title' => $safe($this->title), 'date' => $date], $locale))
```
Apply the same to the greeting name. Keep the plain value in the subject.

### WR-05: A push subscription survives logout, so reminder titles keep appearing on a shared device

**File:** `resources/js/Shell/AccountMenu.vue:45` (logout), `resources/js/push.js:96-103` (`disable`), `app/Http/Controllers/Settings/PushSubscriptionController.php:24-45`
**Issue:** Logout only calls `router.post('/logout')`. The browser subscription and the server row stay bound to user A. After A logs out (iPad or family PC), A's reminder titles, which may be Personal-task titles, still appear on the lock screen for whoever uses the device, until B logs in and re-binds. There is also no server-side cleanup on logout or session invalidation.
**Fix:** On logout, call `disable()` from `push.js` before posting `/logout`, wrapped in try/catch so logout never blocks. Server-side, optionally delete the subscription for the current endpoint in the logout handler.

### WR-06: SSRF protection exists only at write time, and the sender does not re-check the endpoint or restrict redirects

**File:** `app/Push/WebPushSender.php:15-31`, `app/Push/PushEndpointRule.php`
**Issue:** `PushEndpointRule::allowed` is applied only in `StorePushSubscriptionRequest`. `WebPushSender::send` posts to `$subscription->endpoint` without re-validating, so any row created by a future code path, a data import or a DB edit is trusted. The minishlink Guzzle client also follows redirects by default, so an allowlisted host that returns a 3xx could steer the server-side POST to an internal address. The `catch (\Throwable)` hides all of this.
**Fix:** Call `PushEndpointRule::allowed($subscription->endpoint)` at the top of `send()` and return `PushResult::Expired` for a bad endpoint. Construct `new WebPush($auth, [], 15, ['allow_redirects' => false])` (the fourth argument is the Guzzle client options).

### WR-07: The recurrence engine silently ends a series after 1000 occurrences, with no signal to the user

**File:** `app/Recurrence/RRuleRecurrenceEngine.php:44-60`
**Issue:** In Schedule mode the loop starts at the old anchor and skips everything `<= now`. A daily task overdue by more than about 2.7 years (or any series whose next accepted instance is beyond the 10-year limit) returns `null`. `TaskCompletionService::createNext` then returns `null` and the series ends: the user completes the occurrence, no next one is created, and nothing indicates why. A bounded jump-ahead is also cheaper than iterating from the anchor.
**Fix:** For Schedule mode, start the iterator from `max($anchor, $now)` aligned to the rule (or count the cap only from the first occurrence after `$now`), and make a `null` result observable, for example by flashing a toast key.

### WR-08: A stale reminder is sent after the task is rescheduled or reassigned

**File:** `app/Notifications/Outbox/TaskReminderHandler.php:21-29`
**Issue:** `stillRelevant` checks status, completion and visibility, but not that `payload.remind_at` still matches `tasks.remind_at` or the computed reminder time, and not that `$user` is still the reminder recipient. If an outbox row is pending or retrying (backoff up to 1 h) and the task's due date is pushed out or the task is reassigned, the original user still gets a reminder for a moment that no longer applies.
**Fix:** In `stillRelevant`, recompute the expected remind instant (`ReminderTimeCalculator`) and compare with `payload['remind_at']`, and require `in_array($user->id, array_map(fn ($u) => $u->id, $this->recipients->for($task)))`.

## Info

### IN-01: Service worker URL guard accepts `/\host`

**File:** `resources/js/sw.js:29`
**Issue:** `startsWith('/') && !startsWith('//')` lets `/\evil.example` through, and `new URL('/\\evil.example', origin)` resolves to `https://evil.example` in WHATWG parsing. The payload is server-signed, so exploitation needs a server bug, but it is cheap to close.
**Fix:** Add `&& !data.url.includes('\\')`, or parse with `new URL(url, self.location.origin)` and require `.origin === self.location.origin`.

### IN-02: `WebPushSender` swallows every Throwable without any diagnostic

**File:** `app/Push/WebPushSender.php:31`
**Issue:** A bad VAPID key, a JSON failure or a network error all become `PushResult::Failed`. The outbox shows only `push_failed` after 5 attempts, which is hard to debug. Logging must still exclude payloads and keys.
**Fix:** Log `class_basename($e)` and the endpoint host only, and let `push:vapid --check` validate the key shape.

### IN-03: `isClosedOccurrence` is duplicated in `TaskAccess` and `TaskService`

**File:** `app/Tasks/TaskAccess.php:31`, `app/Tasks/TaskService.php:103`
**Issue:** The two copies already agree, but fixing WR-01 in only one would reopen the hole.
**Fix:** Keep a single implementation (a `Task::isClosedOccurrence()` model method).

### IN-04: `$guarded = []` on `OutboxItem` and `PushSubscription`, and the push endpoint is stored in plaintext

**File:** `app/Models/OutboxItem.php:12`, `app/Models/PushSubscription.php:10`, migration `2026_11_14_120000_create_push_subscriptions_table.php`
**Issue:** Only server code writes these models today, but `$guarded = []` removes the mass-assignment safety net if a request array is ever passed in. `endpoint` is a capability URL (it is hidden in JSON but not encrypted, and only `p256dh` and `auth` are). Together with the VAPID key, which lives in the same environment, a DB leak could be used to push to devices.
**Fix:** Use explicit `$fillable`, and consider `'endpoint' => 'encrypted'` (the hash column already supports lookup).

### IN-05: Push subscription store can 500 on a concurrent first insert, and the test endpoint can run for minutes

**File:** `app/Http/Controllers/Settings/PushSubscriptionController.php:26-45, 62-72`
**Issue:** Two simultaneous POSTs of the same new endpoint both pass the `lockForUpdate()->first()` (nothing to lock) and one hits the unique `endpoint_hash` constraint, giving a 500. `$row->touch()` after `save()` is redundant. `test()` sends sequentially to up to 10 devices at a 15 s timeout inside a web request.
**Fix:** Use `PushSubscription::updateOrCreate` or `upsert` on `endpoint_hash`, drop `touch()`, and cap or queue the test send.

---

_Reviewed: 2026-11-13_
_Reviewer: Claude (gsd-code-reviewer)_
_Depth: standard_
