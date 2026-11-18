---
phase: 14-rebranding-issue-13
plan: 05
subsystem: docs
tags: [manual, branding, i18n]
requires: []
provides: [manual uses FamilyHUB, old-icon FAQ section]
key-files:
  modified:
    - resources/manual/{en,sk,pl}/getting-started.json
    - resources/manual/{en,sk,pl}/shopping.json
    - resources/manual/{en,sk,pl}/faq.json
metrics:
  tasks: 1
  files: 9
completed: 2026-11-18
---

# Phase 14 Plan 05: Manual rebrand Summary

Manual renamed to FamilyHUB in SK/PL/EN, install section names the orange/red/blue F logo, and a new FAQ section `old-icon` tells installed-app users how to refresh the icon.

## Touched files
All nine `resources/manual/{en,sk,pl}/{getting-started,shopping,faq}.json`. SK and PL texts are drafts: need native review.

## Verification
- Section ids identical across en/sk/pl for the three topics (checked with script: all True).
- No "Family Hub" left in resources/manual.
- Vitest manual + HelpCoverage: 77 passed. Pest tests/Feature/Manual: 10 passed.
- help: no tip affected (grep of resources/help shows only store-logo tips, no product name or app icon). `resources/help` unchanged.

## Deviations
None.

Commit: task commit with `Fixes #13` trailer.
