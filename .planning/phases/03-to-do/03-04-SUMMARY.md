---
phase: 03-to-do
plan: 04
subsystem: recurrence
tags: [rrule, dst, reminders, php]
requires: [03-02]
provides:
  - App\Recurrence (RecurrenceMode, RecurrencePattern, RuleBuilder, RecurrenceEngine, RRuleRecurrenceEngine, LocalTime)
  - App\Tasks\ReminderTimeCalculator
affects: [03-07, 03-09, phase-5 finance]
tech-stack:
  patterns: [pure domain-agnostic engine, allowlisted RRULE, wall-clock stepping in local zone]
key-files:
  created:
    - app/Recurrence/RecurrenceMode.php
    - app/Recurrence/RecurrencePattern.php
    - app/Recurrence/RuleBuilder.php
    - app/Recurrence/RecurrenceEngine.php
    - app/Recurrence/RRuleRecurrenceEngine.php
    - app/Recurrence/LocalTime.php
    - app/Tasks/ReminderTimeCalculator.php
    - tests/Unit/Recurrence/{RuleBuilder,RecurrenceEngine,MonthEnd,Dst}Test.php
    - tests/Unit/Tasks/ReminderTimeCalculatorTest.php
  modified: [app/Providers/AppServiceProvider.php]
key-decisions:
  - "ENGINE: rrule built (php-rrule); no CarbonRecurrenceEngine"
  - "Completion mode never returns the completion day (non-monthly) or the completion month (monthly): next = completion date/month + interval"
  - "Engine re-validates the rule through RuleBuilder::fromRrule (allowlist) before use"
  - "Cap counts occurrences iterated from DTSTART (max 1000) and a 10 year window from DTSTART"
metrics:
  completed: 2026-11-13
---

# Phase 3 Plan 04: Recurrence engine and reminder calculator Summary

php-rrule backed RecurrenceEngine with allowlisted RuleBuilder (BYSETPOS month-end clamp), DST-safe LocalTime, schedule/completion modes, and a UTC ReminderTimeCalculator, all pure and bound in the container.

## Tasks

| Task | Commit | Result |
|------|--------|--------|
| 1. Pattern + RuleBuilder | 8126282 | 57 tests, strict canonical round trip |
| 2. Engine + LocalTime + binding | ac369d0 | month-end, leap, DST tests green |
| 3. ReminderTimeCalculator | e917f34 | 12 tests |

## Deviations from Plan

1. [Rule 3 - Blocking] Unit tests do not boot Laravel, so `app(RecurrenceEngine::class)` resolved a bare container. The three engine test files call `uses(Tests\TestCase::class)` (no DB refresh).
2. [Rule 1 - Bug] PHP resolves an ambiguous fall-back time to the second instant; LocalTime now returns the first instant as the contract requires (pinned in DstTest).

## Notes for consumers

- Chaining from a spring-forward shifted result (02:30 shifted to 03:30) keeps 03:30 afterwards; the 02:30 time returns only when anchored on the original scheduled time (pinned: 2027-03-28 03:30, then 2027-04-04 02:30 from the 03-21 anchor).
- A daily task overdue by more than 1000 occurrences (or any rule by more than 10 years) returns null (series ends); this is the T-03-11 cap.
- Engine throws InvalidArgumentException for non-allowlisted rules and for completion mode without a completion time.

## Verification

Full Pest 409 passed (includes sibling 03-03 work present in the tree). Pint clean on app/Recurrence, app/Tasks, tests/Unit. Vitest and npm build not run (no JS touched). Containers (project fh0304) torn down.

## Known Stubs

None.

## Self-Check: PASSED
