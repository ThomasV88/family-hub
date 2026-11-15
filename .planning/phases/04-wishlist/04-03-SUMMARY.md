---
phase: 04-wishlist
plan: 03
subsystem: wishlist
tags: [namedays, data, wikipedia, cc-by-sa, dates]
requires: []
provides:
  - "database/data/namedays/{sk,pl}.json (366 days each)"
  - "App\\Wishlist\\Occasions\\AnnualDate::next/daysUntil"
  - "App\\Wishlist\\Occasions\\NameDayCalendar::search/has/calendars"
affects: [04-09, 04-11, 04-12]
key-files:
  created:
    - scripts/namedays/build-sk.php
    - scripts/namedays/build-pl.php
    - database/data/namedays/sk.json
    - database/data/namedays/pl.json
    - database/data/namedays/SOURCES.md
    - database/data/namedays/LICENSE-sk.txt
    - database/data/namedays/source/sk/
    - database/data/namedays/source/pl/
    - app/Wishlist/Occasions/AnnualDate.php
    - app/Wishlist/Occasions/NameDayCalendar.php
    - tests/Unit/Wishlist/NameDayDataTest.php
    - tests/Unit/Wishlist/Occasions/AnnualDateTest.php
    - tests/Unit/Wishlist/Occasions/NameDayCalendarTest.php
decisions:
  - "PL gate passed: pages=12 days=366 names=3444; Task 2 checkpoint skipped as planned"
  - "Lowercase parentheticals are qualifiers and dropped; capitalised ones are alternative names kept; 'Wszyscy, w tym:' dropped"
metrics:
  completed: 2026-11-13
  tasks: 3
---

# Phase 4 Plan 03: Name-day data and annual date helpers Summary

Slovak (MIT, pinned commit 2a9e495) and Polish (12 Polish Wikipedia monthly pages, CC BY-SA 4.0, revids recorded) name days as reproducible versioned JSON, plus pure `AnnualDate` (Feb 29 observed Feb 28) and diacritics-insensitive `NameDayCalendar`.

## PL gate

PL-GATE PASS pages=12 days=366 names=3444 (SK: 362 non-empty days, 475 names). All 12 pages found, `--build` exits 0, thresholds pass. Checkpoint task 2 not triggered. Rebuild from stored wikitext is byte-identical (tested).

## Deviations from Plan

**1. [Rule 3 - Blocking] Wikipedia API 429 rate limit during fetch** - fetch now honours Retry-After, retries up to 5 times and sleeps 2s between pages.

**2. [Rule 1 - Parser structure] Parser adjusted to the stored wikitext structure** (while writing the parser, before the gate was evaluated): day headings use `==` or `===`; qualifier "(w latach nieprzestępnych)" dropped; "Wszyscy, w tym:" on 1 Nov dropped. No data was added or edited by hand; every name comes from the fetched wikitext.

Note: MediaWiki link trails (e.g. `[[Kornel]]i`) are kept as written in the source.

## Verification

Full Pest (686 passed), Vitest (686 passed), Pint clean on own files.

## Commits

- 9f50b3b data and build scripts and validation test
- 18702c0 AnnualDate and NameDayCalendar

## Self-Check: PASSED
