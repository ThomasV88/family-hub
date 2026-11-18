---
phase: 15-bulk-medical-document-upload-issue-14
plan: 08
subsystem: documentation
tags: [manual, help, i18n]
requires: [15-06, 15-07]
provides: [bulk-upload manual section x3, who-sees-what row x3, four help tips]
key-files:
  modified:
    - resources/manual/en/health.json
    - resources/manual/sk/health.json
    - resources/manual/pl/health.json
    - resources/manual/en/who-sees-what.json
    - resources/manual/sk/who-sees-what.json
    - resources/manual/pl/who-sees-what.json
    - resources/help/topics.json
    - resources/help/en.json
    - resources/help/sk.json
    - resources/help/pl.json
    - resources/js/Health/Batch/StartBatchControl.vue
    - resources/js/Health/Batch/AdjustmentPanel.vue
    - resources/js/Health/Batch/BatchReview.vue
    - resources/js/Health/Batch/BatchItemActions.vue
    - tests/js/manual/ManualCoverage.test.js
metrics:
  completed: 2026-11-18
---

# Phase 15 Plan 08: Manual and help for bulk upload Summary

Health manual section `bulk-upload` (after `documents`) in en, sk, pl, a who-sees-what row, and four green tips (health.batch_start, _adjust, _continue, _rescan) placed on the batch controls.

## Commits
- Task 1: manual section, who-sees-what row, coverage test (docs(15-08))
- Task 2: help registry, texts, HelpTip placements (feat(15-08))

## Manual content (all facts verified against 15-01..15-07)
Re-scan clears that document's marks without confirmation; Continue with undecided findings keeps them on the document; "Keep document without findings" only for failed documents; 5 re-scans; 30-day expiry (documents with accepted findings kept); consent withdrawal (queued/waiting cancelled, recognised text removed, documents and choices stay, continues on re-enable); 50 files, 10 MB, one open batch per person; adjustments only affect the AI copy; one document at a time; resume on any device. Buttons use the real UI labels (Upload many documents, Improve the scan, Scan again, Continue).

## Deviations
- AdjustmentPanel: heading wrapped in a flex `adjust__head` div so the tip sits outside the h4 (aria-labelledby stays clean); one scoped CSS line added.
- HelpPlacement.test.js not changed: it lists no per-screen placements; HelpCoverage already proves registry/text/language parity and the 400-char limit.
- Tip titles follow the UI labels ("Scan again", "Improve the scan"), not the plan's "Re-scan" wording.
- User-visible: the plan said "Re-scan"; the manual says "Scan again" to match the button.

## Verification
Vitest manual, help, brand, health: 44 files, 2614 tests pass. Pest tests/Feature/Manual and tests/Feature/Help: 49 pass. fh1508 stack removed.

## Native review needed (drafts)
SK and PL: the bulk-upload section and the documents-section pointer sentence in resources/manual/{sk,pl}/health.json; the new row in resources/manual/{sk,pl}/who-sees-what.json; resources/help/{sk,pl}.json (screen upload_batch and four health.batch_* topics); plus the 15-06 lang subtree (health.batch.* in lang/sk.json, lang/pl.json) and the two keys added in 15-07.

## Self-Check: PASSED
