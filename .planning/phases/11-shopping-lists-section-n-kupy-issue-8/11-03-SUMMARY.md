---
phase: 11-shopping-lists-section-n-kupy-issue-8
plan: 03
subsystem: shopping-domain
tags: [access, services, state-machine, shopping]
requires: ["11-01", "11-02"]
provides:
  - "ShoppingAccess (role, can*, may*, abilities) with no admin bypass; ResponsiblePerson (effective, isFallback, previousName, eligible)"
  - "ListQuery::visibleTo and ListQuery::lockForActor (visibility check plus list row lock, uniform 404)"
  - "ListMembers::sync, ListService (create, updateSettings, delete), ItemService (add, update, remove), ListStateService (setLocked, setItemState, finish with carry-over, copy)"
affects: [11-05, 11-07, 11-08, 11-09]
key-files:
  created:
    - app/Shopping/ResponsiblePerson.php
    - app/Shopping/ShoppingAccess.php
    - app/Shopping/ListMembers.php
    - app/Shopping/Queries/ListQuery.php
    - app/Shopping/ListService.php
    - app/Shopping/ItemService.php
    - app/Shopping/ListStateService.php
    - tests/Support/ShoppingFixtures.php
    - tests/Feature/Shopping/ResponsiblePersonTest.php
    - tests/Feature/Shopping/ShoppingAccessTest.php
    - tests/Feature/Shopping/ListServiceTest.php
    - tests/Feature/Shopping/ItemServiceTest.php
    - tests/Feature/Shopping/ListStateMachineTest.php
    - tests/Feature/Shopping/CarryOverTest.php
    - tests/Feature/Shopping/CopyTest.php
metrics:
  tasks: 3
  completed: 2026-11-17
---

# Phase 11 Plan 03: Shopping domain layer Summary

All Shopping list rules live in seven classes: record-level access with the creator fallback for an unfit responsible person, list and item services, and a state machine (lock, marks, finish with one idempotent carry-over, copy) that runs every mutation under a list row lock.

## Commits
- 1028d82 access, effective responsible, visibility, member sync (`Refs #8`)
- d3f6207 ListService and ItemService (`Refs #8`)
- cb578a3 ListStateService, state machine, carry-over and copy tests (`Refs #8`)

## Tests
Isolated compose project `fh1103` (own Postgres, scratch folder over storage/logs via an override file), serial, removed with `down -v`. Pint on app/Shopping, tests/Feature/Shopping, tests/Support: pass (style fixes applied before each commit). `tests/Feature/Shopping`: 138 passed. Full serial Pest suite: 3086 passed, 1 skipped. Vitest not run: no JS, lang, help or manual file touched.

## User documentation
No user-visible change: domain classes and tests only; no `resources/manual/`, help or lang file touched. Error responses use `shopping.error.*` keys as strings (lock_empty, member_not_eligible, member_overlap, responsible_not_eligible, name_invalid, store_unknown, items_count, quantity_invalid, note_too_long, unit_unknown, catalog_unknown); the texts must be added by the UI plans 11-11 to 11-13.

## Decisions and notes for later plans
- Services use status-independent `may*` and `isResponsible` checks for the 403 and the status for the 409; `can*`/`abilities()` include the status and are for the UI. Order everywhere: 404 (hidden or module level none), 403, 409, 422.
- A stored responsible person who lost Shopping edit still sees the list (read-only viewer); the creator acts as responsible.
- Item quantities are validated as `^\d{1,3}(\.\d{1,2})?$`, 0 < q <= 999.99 (the column is `decimal(5,2)`); the HTTP layer must hand over the normalised string.
- Carry-over inserts inside a savepoint so a unique violation of `shopping_lists_one_carry` returns the existing carry list and the finish still commits.
- `ListQuery::lockForActor($actor, $id, $access)` is the standard entry for mutations in 11-05, 11-07, 11-08.
- AccessGate caches levels per instance: callers that change a grant mid-request must call `AccessGate::forget` (tests do via `ShoppingFixtures::setLevel`).

## Deviations from Plan
Minor, no rule triggered:
- Added `tests/Support/ShoppingFixtures.php` (shared builders and status assertions, not in `files_modified`) and `ListQuery::lockForActor` (shared lock helper) to avoid duplicating code across three services.
- Quantity upper bound and precision follow the real column (999.99, two decimals) rather than the research text.

## Known Stubs
None.

## Threat Flags
None beyond the plan's threat model (T-11-09 to T-11-13 covered by ShoppingAccessTest, ListServiceTest, ListStateMachineTest, CarryOverTest).

## Self-Check: PASSED
