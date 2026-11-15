---
phase: 03-to-do
plan: 01
subsystem: i18n
tags: [vue-i18n, laravel-lang, plurals, sk, pl, en]
requires: []
provides:
  - todo.* and notifications.* namespaces in lang/{en,sk,pl}.json
  - lang/{en,sk,pl}/notifications.php (reminder_title, reminder_greeting, reminder_body, reminder_action, test_title)
affects: [03-02, 03-03, later Phase 3 plans (read-only use of lang files)]
tech-stack:
  added: []
  patterns: [pipe plurals one|few|many|other for sk/pl, server strings in group files]
key-files:
  created:
    - lang/en/notifications.php
    - lang/sk/notifications.php
    - lang/pl/notifications.php
    - tests/js/i18n-phase3.test.js
    - tests/Unit/NotificationsLangTest.php
  modified:
    - lang/en.json
    - lang/sk.json
    - lang/pl.json
key-decisions:
  - "Plural keys: todo.column.count and todo.repeat.summary_days/weeks/months (2 segments en, 4 sk/pl); call with t(key, {n}, n)"
  - "todo.delete is an object (action/title/body/body_recurring/keep); reminder keys are before_15m/before_1h/before_1d"
  - "Reminder title/body live only in notifications.php, never in JSON; no string carries notes"
requirements-completed: [TODO-01, TODO-02, TODO-03, TODO-04, TODO-05, TODO-06]
duration: 10min
completed: 2026-11-13
---

# Phase 3 Plan 01: Phase 3 copy Summary

All To-do and notification copy exists in en, sk and pl (vue-i18n JSON plus Laravel server-side reminder strings) with parity, placeholder, plural and compile tests.

## Tasks

| Task | Commit | Result |
|------|--------|--------|
| 1. todo/notifications JSON + parity test | 581268b | 170ish keys per language, plural forms verified (sk 1/2/5, pl 1/2/5) |
| 2. notifications.php + Pest test | 543c824 | 5 keys per locale, placeholder parity, no notes placeholder |

## Deviations from Plan

None. The generated JSON was written with a throwaway script (scratchpad, not committed); existing formatting round-trips byte-identically.

## Verification

- Full Vitest: 422 passed. Full Pest: 303 passed. Pint clean on lang and the new test.
- `npm run build` was not run (no frontend source changed).
- Untracked `tests/Unit/Notifications/` and `tests/Unit/Recurrence/` belong to plan 03-02 and were not touched.

## Known Stubs

None.

## Self-Check: PASSED

All listed files exist; commits 581268b and 543c824 exist.
