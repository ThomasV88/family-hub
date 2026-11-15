---
phase: 03-to-do
plan: 10
subsystem: notifications
tags: [web-push, vapid, service-worker, outbox]
requires: [03-07, 03-02]
provides:
  - push_subscriptions store with settings.notifications.push.{store,destroy,test} routes
  - PushSender interface with WebPushSender (minishlink/web-push v11), FakePushSender for tests
  - push channel in the outbox (enqueue and SendOutboxItem)
  - push:vapid command, sw.js push/notificationclick handlers
affects: [03-11 settings UI, 03-12 device checks]
key-files:
  created: [app/Push/*, app/Models/PushSubscription.php, config/push.php, app/Console/Commands/PushVapid.php, app/Http/Controllers/Settings/PushSubscriptionController.php, routes/web/notifications.php, tests/Feature/Notifications/Push*Test.php]
  modified: [app/Jobs/SendOutboxItem.php, app/Console/Commands/EnqueueReminders.php, resources/js/sw.js, tests/js/sw-precache.test.js, tests/Feature/Notifications/OutboxTest.php]
decisions:
  - PushSender is bound with bind (not singleton) so tests swap FakePushSender via app()->instance
  - SendOutboxItem::handle gets an optional third PushSender param (resolved from the container when absent) so existing two-argument callers keep working
metrics:
  tasks: 2
  commits: [daa660d, 7765e51]
---

# Phase 3 Plan 10: Push reminders Summary

Push subscriptions, VAPID-from-env sender, push channel through the outbox and service worker handlers, with title-only payloads and a precache-only worker.

## What was built
- Subscription endpoints with PushEndpointRule (https, known push hosts or subdomains, no IP/userinfo/port, max 2048), key length checks, encrypted p256dh/auth, rebind on reuse, cap 10, throttles.
- push:vapid generates keys (never committed; env templates carry empty placeholders), `--check` never prints the private key.
- Reminder enqueue writes a push row only when push is on and a subscription exists. The job sends `{title, url, tag}` to each subscription, deletes expired ones, sets last_success_at, retries via the outbox (5 attempts), skips with `push_not_configured` / `no_subscription`.
- sw.js: push (defensive parse, always showNotification, same-origin path only) and notificationclick; no caching tokens; `npm run build` prints SW-PRECACHE OK.

## Deviations
- [Rule 1] Existing OutboxTest "fails an unsupported channel" asserted the old behaviour the plan replaces; rewritten to assert a push row for a member with push off is skipped.
- Pint on `app/Providers/AppServiceProvider.php` reports import-order issues that predate this plan (shared file, left alone; the whole-repo `pint --test` has other pre-existing failures in files this plan does not own).

## Verification
Full Pest (506 passed), Vitest (576 passed), build SW-PRECACHE OK, check-secrets OK. A first Pest run failed only because public/build had not been rebuilt yet; it passed after the build. Real device delivery deferred to 03-12.

## Known Stubs
None.

## Self-Check: PASSED
