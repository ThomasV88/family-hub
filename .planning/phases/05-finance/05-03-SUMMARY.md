---
phase: 05-finance
plan: 03
subsystem: finance
tags: [recurrence, matcher, tdd]
requires: []
provides:
  - RecurrenceEngine::between() window enumeration
  - App\Finance\Recurring Matcher, StatusResolver, Occurrence, MatchCandidate, BillStatus, Days
affects: [05-finance recurring bill plans]
key-files:
  created:
    - tests/Unit/Recurrence/BetweenTest.php
    - app/Finance/Recurring/Occurrence.php
    - app/Finance/Recurring/MatchCandidate.php
    - app/Finance/Recurring/Matcher.php
    - app/Finance/Recurring/BillStatus.php
    - app/Finance/Recurring/StatusResolver.php
    - app/Finance/Recurring/Days.php
    - tests/Unit/Finance/MatcherTest.php
    - tests/Unit/Finance/StatusResolverTest.php
  modified:
    - app/Recurrence/RecurrenceEngine.php
    - app/Recurrence/RRuleRecurrenceEngine.php
    - tests/Feature/Todo/CompletionTest.php
decisions:
  - "between() iterates from DTSTART with a local date cutoff, so it is independent of the real clock; the anchor date is always included"
  - "Matching uses absolute toleranceMinor and symmetric windowDays per the UI-SPEC"
metrics:
  tasks: 3
  completed: 2026-11-13
---

# Phase 5 Plan 03: Recurrence window and matcher Summary

`RecurrenceEngine::between()` (Schedule semantics, month-end clamp, local-time anchor, cap 62, 10-year limit from the anchor, allowlist re-validation) plus pure integer-only Matcher (score, autoAccepts, deterministic greedy assign, rankForManual) and StatusResolver.

## Commits
- 8ea64f5: between() + BetweenTest + CompletionTest stub
- e47a9af: Matcher, StatusResolver and value classes with tests

## Deviations from Plan
- [Rule 3] Added small helper `app/Finance/Recurring/Days.php` for integer day arithmetic shared by Matcher and StatusResolver (plan allowed a "private static helper"; shared class avoids duplication).
- `between()` the anchor's time of day is preserved (the plan's 12:00 convention is supplied by callers via the anchor).

## Verification
Pint clean on own files; full Pest 1019 passed (first run had 3 failures in sibling-owned files, e.g. NoFloatScanTest on MoneyFormat.php, which cleared on re-run); Vitest 955 passed; build OK; compose project torn down.

## Known Stubs
None. The CompletionTest anonymous engine `between()` throws LogicException by plan design.

## Self-Check: PASSED
