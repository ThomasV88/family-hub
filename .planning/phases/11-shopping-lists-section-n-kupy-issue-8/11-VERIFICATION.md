---
phase: 11-shopping-lists-section-n-kupy-issue-8
verified: 2026-11-17T00:00:00Z
status: human_needed
score: 10/10 must-haves verified
overrides_applied: 0
human_verification:
  - test: "Native review of new Slovak and Polish text (lang/sk.json, lang/pl.json shopping.* and shopping.error.*, resources/manual/{sk,pl}/shopping.json, resources/help/{sk,pl}.json, lang/{sk,pl}/mail.php and notifications.php)"
    expected: "Natural wording; the owner's carry-over question reads correctly in sk and pl"
    why_human: "Drafts written by Claude; the repo rule requires native review"
  - test: "Reminder push on a real device (iPhone PWA and Android) for a list with a reminder date"
    expected: "Push arrives for the responsible person only, opens /shopping/lists/{id}"
    why_human: "Real push delivery cannot be checked from code or tests"
  - test: "E-mail delivery of a shared list to an external address through Stalwart"
    expected: "Plain-text mail with items arrives; no list data leaks beyond the list"
    why_human: "External service"
  - test: "Re-check 360 px layout on staging after the next deploy (bottom bar with 6-7 items, locked overview row)"
    expected: "No mid-word label breaks; overview row stacks under the text"
    why_human: "jsdom does no layout; quick task 261117-c0r only has CSS-source assertions"
---

# Phase 11: Shopping lists "Nakupy" Verification Report

**Phase Goal:** Shared shopping lists from an item catalog with stores, responsible person and access, lock for shopping, bought/unavailable marks, finish to archive with carry-over, e-mail sharing and push reminders.
**Status:** human_needed (all code-verifiable truths hold, four human items remain)
**Re-verification:** No, initial

## Observable Truths

| # | Truth | Status | Evidence |
|---|-------|--------|----------|
| 1 | Create list, picker with catalog images, free text, units | VERIFIED | ListController/ItemController/ItemService, ItemPickerDialog.vue, units.js, ShoppingUnit built-ins, tests ItemServiceTest, ItemHttpTest, ItemPicker.test.js |
| 2 | Store chain on list; chains and catalog managed in Settings > Shopping | VERIFIED | routes/web/settings-shopping.php, Settings/Shopping* controllers, Catalog/StoreService, Settings/Shopping.vue, CatalogSettingsTest |
| 3 | One responsible person, contributors/viewers, server-side enforcement | VERIFIED | ShoppingAccess (role, mayEditItems, canChangeResponsible), `access:module,shopping,*` middleware on all routes, ResponsiblePerson fallback, ListPrivacyTest, ShoppingAccessTest |
| 4 | Only responsible locks/unlocks and marks; locked list blocks add/edit/remove | VERIFIED | ListStateService setLocked/setItemState: `isResponsible` 403, state 409; ItemService requires `ListStatus::Open` (409). Staging: 8 rapid marks persisted, non-responsible view read-only |
| 5 | Finish appears only when all resolved, moves to archive | VERIFIED | finish(): aborts 409 if any Pending, sets Done; UI confirmed on staging |
| 6 | Carry-over question; new OPEN list keeps store, responsible, access, only unavailable items | VERIFIED | createCarry + copyMembers + copyItems(unavailable), one-carry unique index with idempotent re-read, CarryOverTest; staging produced "(nezakupene)" open list |
| 7 | Copy existing or archived list | VERIFIED | ListStateService::copy, CopyController, canCopy, CopyTest, CopyListDialog |
| 8 | E-mail a list | VERIFIED | MailController, ShoppingListMailer, MailAddress validation, rate limits, ShoppingMailTest (delivery itself is human item) |
| 9 | Reminder push to responsible only | VERIFIED (code) | EnqueueShoppingReminders scheduled every minute (routes/console.php), ShoppingReminderHandler.stillRelevant checks effective responsible, age and status; ShoppingReminderTest. Real device is human item |
| 10 | Proposals and conflicts flagged and resolved | VERIFIED | Handled in CONTEXT/RESEARCH and implemented: hand-over keeps access (WR-01), no-active-responsible fallback (WR-02), archive copy rights, catalog image/unit deletion, mail privacy/limits |

**Score:** 10/10

### Review follow-up
11-REVIEW critical (mark queue loss) fixed (useMarkQueue, e242d7f) and confirmed on staging. Fix report: 28 of 31 fixed, 3 skipped by decision (desktop finish placement, migration hygiene, archived-list unit/store deletion), all documented, none contradict a success criterion.

### Quick task 261117-c0r
Code and tests committed (BottomNav density, ListRow wrap, 168 Vitest files passing per summary). Visual effect not checked on staging yet (human item 4).

### Anti-patterns
No TBD/FIXME/XXX in app/Shopping, Shopping controllers or resources/js/Shopping. No stubs found in the state service or access layer.

### Requirements
GitHub #8: all points mapped to truths above.

### Gaps
None.

_Verifier: Claude (gsd-verifier)_
