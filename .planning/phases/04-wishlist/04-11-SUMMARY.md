---
phase: 04-wishlist
plan: 11
subsystem: notifications
tags: [wishlist, outbox, reminders, retention, settings]
requires: [04-07, 04-08, 04-09]
provides:
  - occasions:enqueue hourly scan (giver-only gift reminders on the Phase 3 outbox)
  - wish_occasion outbox handler, localized mail and push
  - wishlist:prune daily retention
  - Gift reminders card and PUT /settings/notifications/gifts
affects: [04-13]
tech-stack:
  added: []
  patterns: [outbox handler registry, send-time stillRelevant re-check, dedupe_key idempotency]
key-files:
  created:
    - database/migrations/2026_11_15_130000_create_wish_reminder_settings_table.php
    - app/Models/WishReminderSetting.php
    - app/Wishlist/Occasions/ReminderLeadDays.php
    - app/Wishlist/Occasions/OccasionGivers.php
    - app/Console/Commands/EnqueueOccasionReminders.php
    - app/Console/Commands/PruneWishlist.php
    - app/Notifications/Outbox/WishOccasionHandler.php
    - app/Notifications/WishOccasionNotification.php
    - app/Http/Controllers/Settings/GiftReminderSettingsController.php
    - routes/web/gift-reminders.php
    - resources/js/Components/Settings/GiftReminderPrefs.vue
  modified:
    - app/Notifications/Outbox/OutboxHandlers.php
    - app/Http/Controllers/Settings/NotificationSettingsController.php
    - routes/console.php
    - resources/js/Pages/Settings/Notifications.vue
    - tests/Unit/Arch/WishlistPrivacyArchTest.php
key-decisions:
  - "Dedupe key uses the occurrence type (birthday|nameday|christmas|custom); outbox subject_type stays user_birthday|wish_occasion|christmas"
  - "Window: fire when 0 <= daysAway <= lead and daysAway >= lead-1 (one day of grace, never after the date)"
  - "Reminder title uses plain text; body params are markdown-escaped; vendor mail footer says 'All rights reserved', so the reservation-word test checks only our own lines"
requirements-completed: [WISH-06, WISH-04]
completed: 2026-11-13
---

# Phase 4 Plan 11: Giver occasion reminders Summary

Giver-only gift reminders (mail and push) on the Phase 3 outbox with per-member lead days (14/7/3/1), send-time re-check, and a daily wishlist retention job.

## Commits
- 399ee11 lead-day settings, OccasionGivers, occasions:enqueue
- f4f7c62 WishOccasionHandler, notification, registry, wishlist:prune, schedules, arch rule
- fc55cd3 Gift reminders card and settings endpoint
- (style) pint import order on two edited files

## What was built
- `occasions:enqueue` (hourly, withoutOverlapping): acts from 08:00 family time, one-day grace, channels like task reminders, dedupe key `wish_occasion:{type}:{subjectId}:{date}:{lead}:{userId}:{channel}`, payload ids and dates only. Never reads reservation tables (test asserts no SQL on them).
- `OccasionGivers`: wishlist edit (gate re-read per user) minus owner and owner's managers; owner-less occasions go to all givers.
- `WishOccasionHandler.stillRelevant`: occurrence still on that date, not past, giver rules, lead still enabled.
- Mail/push text from lang notifications.gift_*; push is title plus relative url, no body.
- `wishlist:prune` (daily): reservation retention, manager purge sweep, orphan image sweep (60 min).
- Settings: `giftReminders` prop (null without wishlist edit), `PUT /settings/notifications/gifts` (own row only, 422 on invalid), card with optimistic checkboxes, matrix row.
- Arch rule: handler, notification, enqueue command and HomeController do not use reservation models or App\Wishlist\Giving.

## Deviations from Plan
None of substance. Test-only: Pest closure datasets did not apply my mutations, replaced with a string dataset and match.

## Verification
Full Pest 946 passed, Vitest 891 passed, build SW-PRECACHE OK, Pint clean on this plan's files (pre-existing style issues remain in unrelated test files, not touched).

## Known Stubs
None.

## Self-Check: PASSED
