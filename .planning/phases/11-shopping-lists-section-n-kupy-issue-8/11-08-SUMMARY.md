---
phase: 11-shopping-lists-section-n-kupy-issue-8
plan: 08
subsystem: shopping
tags: [shopping, reminders, push, outbox, scheduler]
requires: ["11-03"]
provides:
  - "shopping:enqueue-reminders (every minute, withoutOverlapping), push-only outbox rows kind shopping_reminder"
  - "ShoppingReminderHandler registered in OutboxHandlers (send-time relevance re-check)"
  - "notifications.shopping_reminder_{title,body,body_no_store} in en, sk, pl"
affects: [11-12]
key-files:
  created:
    - app/Console/Commands/EnqueueShoppingReminders.php
    - app/Notifications/Outbox/ShoppingReminderHandler.php
    - tests/Feature/Shopping/ShoppingReminderTest.php
  modified:
    - app/Notifications/Outbox/OutboxHandlers.php
    - routes/console.php
    - lang/en/notifications.php
    - lang/sk/notifications.php
    - lang/pl/notifications.php
metrics:
  tasks: 2
  completed: 2026-11-17
---

# Phase 11 Plan 08: Shopping reminders Summary

Due list reminders become one push outbox row for the effective responsible person only (creator when the responsible person is gone, lost Shopping access or cannot sign in), dropped at send time if the list was finished, the time or the responsible person changed, or access or push was lost.

## Commits
- 4d88dd5 enqueue command and schedule line (`Refs #8`)
- f575fff outbox handler, registry, three-language strings, tests (`Refs #8`)
- test rename commit: helper names made unique (global Pest function clash with `run()` in another test file) (`Refs #8`)

## Behaviour
- Command: transaction, `for update skip locked`, batch 200, due, not enqueued, not done. Row only when the recipient can sign in, holds Shopping view, has push on and a subscription. `reminder_enqueued_at` is always set (consumed). Dedupe key `shopping_reminder:{list}:{ts}:{user}:push`; payload holds ids and the timestamp only. Changing the reminder through `ListService::updateSettings` clears `reminder_enqueued_at` (11-05), so the list becomes due again; tested.
- Handler: `stillRelevant` checks list exists, not done, `remind_at` equals payload, user is still the effective responsible, can sign in, holds Shopping view. `sendMail` throws `LogicException`. `pushMessage` returns title, body and url `/shopping/lists/{id}` in the recipient's locale.

## Tests
Isolated compose project `fh1108` (own Postgres, scratch folder over storage/logs), serial. ShoppingReminderTest: 15 passed (54 assertions). Pint pass on touched files. Full serial Pest suite: 3255 passed, 1 skipped. Vitest not run: no JS, JSON lang, help or manual file touched.

## User documentation
No user-visible page change in this plan (backend and push text only); the reminder field, the push-off hint and the manual section `reminder` belong to 11-12. No `resources/manual/` file touched. The sk and pl push strings in `lang/sk/notifications.php` and `lang/pl/notifications.php` are drafts for native review.

## Deviations from Plan
- **Body not shown on the device.** `pushMessage` returns a `body` as the plan says, but `SendOutboxItem::sendPush` deliberately forwards only title, url and tag ("nothing else may reach a lock screen"), so the delivered push shows only "Time to shop" and opens the list. I did not change the sender (privacy rule predates this plan); the body is tested at handler level. Showing it on the lock screen would need a sender and service worker change, a decision for the owner.
- **Flat string keys.** Strings are `shopping_reminder_title`, `shopping_reminder_body`, `shopping_reminder_body_no_store` instead of a nested `shopping_reminder.*` array, because `tests/Unit/NotificationsLangTest.php` requires flat keys; its expected key list was extended (commit fix(11-08)).
- Test helper functions were prefixed with `shopping` because Pest helpers are global and `run()` clashed with another test file.

## Known Stubs
None.

## Threat Flags
None beyond the plan's threat model (T-11-33 send-time re-check, T-11-34 ids-only payload, T-11-35 skip locked, withoutOverlapping, unique dedupe key; all covered by tests).

## Self-Check: PASSED
