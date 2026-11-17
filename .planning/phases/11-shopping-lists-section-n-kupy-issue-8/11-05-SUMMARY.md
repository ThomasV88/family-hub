---
phase: 11-shopping-lists-section-n-kupy-issue-8
plan: 05
subsystem: shopping-list-http
tags: [shopping, routes, inertia, requests, privacy]
requires: ["11-03"]
provides:
  - "Routes shopping.index, shopping.lists.{store,show,update,destroy,lock,finish,copy}, shopping.items.{store,update,destroy,state}"
  - "ListPayload::index and ::show (Inertia props for Shopping/Index and Shopping/Show)"
  - "Form requests with decimal-comma quantity and family-timezone reminder normalisation"
  - "Endpoint matrix rows and ListPrivacyTest (404/403 differential)"
affects: [11-07, 11-10, 11-11, 11-12, 11-13]
key-files:
  created:
    - app/Shopping/Queries/ListPayload.php
    - app/Http/Controllers/Shopping/{ListController,ItemController,ItemStateController,LockController,FinishController,CopyController}.php
    - app/Http/Requests/Shopping/{StoreListRequest,UpdateListRequest,StoreItemsRequest,UpdateItemRequest,ItemStateRequest,FinishListRequest,CopyListRequest,Quantity}.php
    - routes/web/shopping.php
    - routes/web/shopping-items.php
    - tests/Support/EndpointMatrix/shopping.php
    - tests/Support/EndpointMatrix/shopping-items.php
    - tests/Feature/Shopping/{ListPagesTest,ListHttpTest,ItemHttpTest,QuantityInputTest,ListPrivacyTest}.php
metrics:
  tasks: 2
  completed: 2026-11-17
---

# Phase 11 Plan 05: List HTTP layer Summary

The Shopping domain is reachable over HTTP: module gate first, then the 11-03 services (which answer 404, 403, 409, 422 in that order), with server-built props for the overview and the list page and strict input normalisation (decimal comma, family-timezone reminder converted to UTC).

## Commits
- 0a36214 routes, requests, controllers, ListPayload and the four HTTP test files (`Refs #8`)
- cc252e7 matrix rows and ListPrivacyTest (`Refs #8`)

## Tests
Isolated compose project `fh1105` (own Postgres, scratch folder over storage/logs via an override file), serial, removed with `down -v`. Pint on app/Shopping, app/Http, routes, tests: pass. Task 1 files: 45 passed. EndpointMatrix shopping rows: 28 passed. ListPrivacyTest: 33 passed. Full serial Pest suite: 3210 passed, 1 skipped (includes EndpointMatrix, SensitiveGateMatrix, Security). Vitest not run: no JS, lang, help or manual file touched.

## User documentation
No user-visible change: there is no page, navigation entry or text until 11-10 and 11-11 (the routes are unreachable from the UI and Inertia components `Shopping/Index` and `Shopping/Show` do not exist yet). No `resources/manual/`, help or lang file touched. Translation keys used as strings that the UI plans must add:
- toasts: `shopping.toast.{list_created,list_saved,list_deleted,locked,unlocked,finished,finished_carry,copied,items_added,item_saved,item_removed}` (`items_added` has params `count` and `merged`, plus `toast.merged` list of merged item names)
- errors added by the requests: `shopping.error.{quantity,reminder_past,reminder_invalid,state_invalid,lock_invalid,copy_invalid}` besides the 11-03 keys (`name_invalid`, `store_unknown`, `responsible_not_eligible`, `member_not_eligible`, `items_count`, `catalog_unknown`, `unit_unknown`, `note_too_long`, `lock_empty`, `member_overlap`).

## Decisions and notes for later plans
- Page props use the keys in the plan contract. Additions: `tab` and `archive {data, currentPage, lastPage, perPage, total}` in the overview; archive rows and open rows carry `abilities.copy`; `logoVersion` and `imageVersion` are `null` when there is no picture.
- Writes answer 303 back, except store, copy and finish-with-carry (to the new list) and list delete (to `/shopping`). Item marking sets no toast. List creation flashes `flash_data = {open_picker: true}`.
- `PUT lock` takes `{locked: bool}`; `UpdateListRequest::settings()` passes only the keys that were sent, `remind_at: null` clears the reminder; the reminder is a local `Y-m-d\TH:i` string in `config('family.timezone')`, a nonexistent spring-forward time moves to the next real instant.
- A viewer (Shopping view grant only) is refused every write by the module gate (403); a member with Shopping edit who is only a list viewer gets 403 from the services on every write except copy (allowed by design: copy creates the copier's own list).

## Deviations from Plan
- The plan's privacy behavior line "an admin without a role gets 404": an administrator without a Shopping grant is stopped by the module gate (403, same as 11-04). The privacy test uses an administrator who holds Shopping edit but has no role on the list, who gets the same 404 as an unknown id; the matrix keeps admin and noGrants at 403.
- Added `app/Http/Requests/Shopping/Quantity.php` (shared normaliser and rules, not in `files_modified`) so item add and edit share one rule.
- Added throttles (`throttle:60..240,1`) on all write routes beside the planned state throttle.
- The lock endpoint validates inline (`locked` boolean) rather than via a request class, as the plan lists none.

## Known Stubs
None.

## Threat Flags
None beyond the plan's threat model (T-11-19 to T-11-23 covered by ListPrivacyTest, ListPagesTest, QuantityInputTest, ListHttpTest, ItemHttpTest and the matrix rows).

## Self-Check: PASSED
