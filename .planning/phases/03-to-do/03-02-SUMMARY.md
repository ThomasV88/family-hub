---
phase: 03-to-do
plan: 02
subsystem: dependencies
tags: [composer, rrule, web-push, spike]
requires: []
provides:
  - rlanvin/php-rrule 3.0.0 and minishlink/web-push 11.0.0 locked
  - pinned recurrence and web-push behaviour
affects: [03-04, 03-10]
tech-stack:
  added: [rlanvin/php-rrule 3.0.0, minishlink/web-push 11.0.0]
key-files:
  created:
    - tests/Unit/Recurrence/RruleLibrarySpikeTest.php
    - tests/Unit/Notifications/WebPushLibrarySpikeTest.php
  modified: [composer.json, composer.lock]
metrics:
  completed: 2026-11-13
---

# Phase 3 Plan 02: Library install and spike tests Summary

php-rrule 3.0.0 and web-push 11.0.0 installed after owner approval; fixed-clock spike tests prove month-end clamp (BYSETPOS=-1) and DST wall-clock behaviour, so the recurrence engine is built on php-rrule.

ENGINE: rrule

## Package approval

Owner answer, verbatim: "Approve both (Recommended)" for rlanvin/php-rrule 3.0.0 and minishlink/web-push 11.0.0 with their dependencies.

Lock diff review: new packages are minishlink/web-push, rlanvin/php-rrule, web-token/jwt-library 4.2.7, spomky-labs/base64url 2.0.4, php-http/discovery 1.20.0, php-http/httplug 2.4.1, php-http/promise 1.3.1, symfony/polyfill-php83. spomky-labs/pki-framework and psr/clock were already in the lock before this plan (not added). No composer allow-plugins change was needed.

## Results

- ext-curl, openssl, mbstring, json already present in the app image; docker/app/Dockerfile unchanged.
- All desired clamp assertions passed with no change: BYMONTHDAY=28..31 + BYSETPOS=-1 (Jan 31 -> Feb 28 -> Mar 31 -> Apr 30; leap Feb 29), 28,29,30 and 28,29 variants, BYMONTHDAY=-1, INTERVAL=3 with no drift (Jan 31, Apr 30, Jul 31, Oct 31), INTERVAL=1 after DTSTART.
- Observation (not counted): plain FREQ=MONTHLY from Jan 31 skips short months (Jan 31, Mar 31, May 31); 03-04 never emits it.
- DST: DAILY at 08:00 Europe/Bratislava stays 08:00 local on 2027-03-28 (06:00 UTC) and 2027-10-31 (07:00 UTC).
- Nonexistent 02:30 on 2027-03-28 is pinned as observed: the library returns 03:30 local; 2027-04-04 returns 02:30 again.
- Weekly INTERVAL=2 BYDAY=MO,WE correct; occurrences carry the DTSTART timezone.
- web-push v11: VAPID::createVapidKeys, WebPush::sendOneNotification/queueNotification/flush, Subscription::create, MessageSentReport::isSuccess/isSubscriptionExpired confirmed.
- Full Pest suite: 303 passed. Pint clean on tests/Unit.

## Task commits

1. Task 1 (approval record, empty commit): 16857ef
2. Task 2 (install): f858921
3. Task 3 (spike tests): efef5f3

## Deviations from Plan

None. Test-file constants were prefixed (RRULE_SPIKE_*) to avoid global name collisions with other test files.

## Self-Check: PASSED
