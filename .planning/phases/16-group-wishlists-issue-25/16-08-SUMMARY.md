---
phase: 16-group-wishlists-issue-25
plan: 08
subsystem: manual
tags: [manual, docs, wishlist, groups, i18n]
requires: [16-06, 16-07]
provides:
  - manual section wishlist#group-lists in en, sk, pl
  - group list rows and link in who-sees-what, FAQ wish-reserved extended
affects: [16-09]
key-files:
  modified:
    - resources/manual/en/wishlist.json
    - resources/manual/sk/wishlist.json
    - resources/manual/pl/wishlist.json
    - resources/manual/en/who-sees-what.json
    - resources/manual/sk/who-sees-what.json
    - resources/manual/pl/who-sees-what.json
    - resources/manual/en/faq.json
    - resources/manual/sk/faq.json
    - resources/manual/pl/faq.json
metrics:
  tasks: 2
  completed: 2026-11-20
---

# Phase 16 Plan 08: Manual for group lists Summary

The in-app manual now documents group lists as built: two modes, creating, managing, leaving, deleting, limits and who sees reservations, in en, sk and pl with identical structure.

## Commits
- Task 1: Wishlist topic (new section `group-lists` between `reserve` and `surprise`; `lists`, `reserve` and `surprise` paragraphs extended)
- Task 2: who-sees-what (3 new rows, link `wishlist#group-lists`) and FAQ `wish-reserved` (one sentence plus link)

Both carry `Refs #25`, no attribution.

## Behaviour documented (as built in 16-01..16-07)
Group chips row under the person buttons; anyone with Wishlist edit creates, members are added directly (no accept step), every member manages; labels New group list, Create list, Manage list, Leave this list, Delete list, Gifts from others, Shared purchases (taken from the lang files); name max 80, 12 members per list, 10 groups per person; mode change asks confirmation and cancels all reservations; leaving or removal on a shared list cancels that person's reservations; people added to a gifts list lose their own reservations there; the last member must delete; delete removes wishes and images; no occasions of their own; received wishes show who marked them; Your shopping shows group sections; a person removed from or leaving a gifts list becomes an ordinary giver and can see its reservations.

## Deviations from Plan
None of substance. Two additions beyond the plan text, both describing built behaviour: the consequences for reservations when someone leaves or is added (from 16-02 decisions G-08), and mention of images in delete.

## User documentation
Touched manual files (9): `resources/manual/{en,sk,pl}/wishlist.json`, `resources/manual/{en,sk,pl}/who-sees-what.json`, `resources/manual/{en,sk,pl}/faq.json`. Help tips were done in 16-06 (`resources/help/topics.json`, `resources/help/{en,sk,pl}.json`, plus `lang/{en,sk,pl}.json`). Section ids, section count, table row count and link set are identical across languages. No new `covers` value.

## SK and PL drafts for native review
All new and changed text in `resources/manual/sk/{wishlist,who-sees-what,faq}.json` and `resources/manual/pl/{wishlist,who-sees-what,faq}.json`: the `group-lists` section, the appended sentences in `lists`, `reserve`, `surprise`, the three who-sees-what rows and link, the FAQ sentence. Terms to check: SK "Spoločné zoznamy", "Darčeky od ostatných", "Spoločné nákupy"; PL "Listy wspólne", "Prezenty od innych", "Wspólne zakupy" (they must match the UI labels in `lang/sk.json` and `lang/pl.json`).

## Verification
- Vitest `tests/js/manual tests/js/help tests/js/brand`: 11 files, 176 tests passed (ManualCoverage, HelpCoverage, BrandName, axe on the manual page en/sk/pl).
- Pest `tests/Feature/Manual tests/Feature/Help`: 49 passed (628 assertions).
- Compose project fh1608 torn down with `down -v`.

## Known Stubs
None.

## Self-Check: PASSED
