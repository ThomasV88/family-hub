---
phase: 03-to-do
plan: 07
subsystem: notifications
tags: [reminders, outbox, scheduler, queue, mail, i18n]
requires: [03-03, 03-04]
provides:
  - tasks.remind_at maintenance (TaskReminderObserver)
  - generic notification_outbox + notification_preferences
  - reminders:enqueue, outbox:dispatch, outbox:prune, SendOutboxItem
  - OutboxHandler registry (kind => handler), TaskReminderHandler with pushMessage for 03-10
affects: [03-10, phase-4-wishlist-reminders]
key-files:
  created:
    - app/Observers/TaskReminderObserver.php
    - app/Models/OutboxItem.php
    - app/Models/NotificationPreference.php
    - app/Notifications/NotificationPreferences.php
    - app/Notifications/TaskReminderNotification.php
    - app/Notifications/Outbox/ (OutboxStatus, OutboxWriter, OutboxHandler, OutboxHandlers, TaskReminderHandler)
    - app/Tasks/ReminderRecipients.php
    - app/Console/Commands/ (EnqueueReminders, DispatchOutbox, PruneOutbox)
    - app/Jobs/SendOutboxItem.php
    - database/migrations/2026_11_14_110000_create_notification_outbox_table.php
    - database/migrations/2026_11_14_110001_create_notification_preferences_table.php
  modified:
    - app/Providers/AppServiceProvider.php
    - routes/console.php
decisions:
  - "SendOutboxItem has tries = 1; retry state (attempts, available_at, backoff 60/300/900/3600 s, failed after 5) lives in the outbox row"
  - "Mail preference is re-checked at send time (row skipped if the user switched mail off after enqueue)"
  - "A new task with no remind_before_minutes attribute takes the column default 0 in the observer, so date-only tasks remind at 08:00 local by default"
metrics:
  tasks: 3
  completed: 2026-11-13
---

# Phase 3 Plan 07: Reminder emails through a generic outbox

Due tasks now produce one localized reminder email per allowed recipient: observer-maintained `remind_at`, an idempotent `reminders:enqueue` scan into a kind-based outbox, and a `SendOutboxItem` job with atomic claim, send-time re-check, and row-driven retries.

## Commits
- 6124bcb feat(03-07): keep tasks.remind_at correct on every save
- 09997a4 feat(03-07): notification outbox, preferences and idempotent reminders:enqueue
- 859fd45 feat(03-07): outbox dispatch, SendOutboxItem with retries, localized reminder mail, prune

## Verification
Full Pest suite: 455 passed (run while 03-05 files were present). Mail driver stays `array`/`log`; no real delivery. Live Stalwart delivery remains the deferred check in 03-12. `schedule:list` entries are asserted in tests (reminders-enqueue, outbox-dispatch every minute without overlapping; outbox-prune daily).

## Deviations from Plan

**1. [Rule 1 - Bug] Default offset for new tasks.** The observer saw `remind_before_minutes` as null on create when the attribute was unset (the DB default applies only at insert), so date-only tasks got no reminder. Fix: new rows without the attribute are set to 0 before computing. File: TaskReminderObserver.

**2. [Minor] Date format in the mail.** Plan said `isoFormat('LLL')`; Carbon's sk locale renders LLL as "10. 5. 18:00" (no month name or year). Used `isoFormat('LL')` plus `H:i` for timed tasks ("10. mája 2027 18:00"), `LL` for date-only. File: TaskReminderNotification.

**3. [Minor] Extra send-time guards.** The job also skips when the mail preference was turned off after enqueue, and the handler treats a done/completed/skipped task as not relevant.

## Deferred / Notes
- `pint --test` over the whole repo reports 13 files; ten are pre-existing and not mine (Auth, Audit, Fortify, Middleware, Listeners tests), plus ordered_imports in `AppServiceProvider.php` and `routes/console.php` (pre-existing import order, left alone). My own new files are Pint clean.
- Push channel is not handled: the job marks non-mail rows failed with `unsupported_channel` until 03-10 adds the branch.
- The rendered HTML test asserts the intro lines for date/title content (HTML entities make raw substring checks on the title unreliable) and asserts the notes text never appears.

## Known Stubs
None.

## Threat Flags
None beyond the plan's threat model (T-03-23..27 mitigated and tested).

## Self-Check: PASSED
Created files exist and commits 6124bcb, 09997a4, 859fd45 are in git log.
