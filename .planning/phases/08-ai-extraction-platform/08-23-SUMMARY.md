---
phase: 08-ai-extraction-platform
plan: 23
subsystem: health-ui
tags: [ai, vue, i18n]
requires: [08-16]
provides: [AI status tag on document rows, consent control without the obsolete unavailable line]
affects: [TimelineList, SpecialtyFolder, Person, AiConsentControl]
key-files:
  modified:
    - resources/js/Health/TimelineList.vue
    - resources/js/Health/SpecialtyFolder.vue
    - resources/js/Pages/Health/Person.vue
    - resources/js/Health/AiConsentControl.vue
    - lang/en.json
    - lang/sk.json
    - lang/pl.json
    - tests/js/health/AiConsent.test.js
    - tests/js/i18n-phase8.test.js
  created:
    - tests/js/health/AiDocumentRows.test.js
decisions:
  - "Rows render AiStatusTag only (status, cancelReason, entryCount); no counts text, no actions"
  - "A 'superseded' status or a missing run renders nothing"
metrics:
  completed: 2026-11-14
---

# Phase 8 Plan 23: AI status on document rows Summary

Document rows in the timeline and in the "documents without a visit" folder now show the document's AI status tag from the page's single poller, and the Phase 7 "AI reading is not available yet" line and its key are gone.

## Commits
- 76060a8 feat(08-23): AI status tags on document rows in lists
- 4bd0de9 feat(08-23): remove the obsolete AI not-available consent line

## What changed
- TimelineList and SpecialtyFolder take an optional `aiStatuses` prop (default `{}`); Person.vue passes `aiStatuses.statuses.value` (the 08-16 `useAiStatus` instance) to both, so poll results update rows without remounting.
- Documents attached to a visit appear only through visit rows in folders, so only document rows (timeline, unattached folder) carry the tag.
- AiConsentControl no longer renders the line; `health.ai.not_available` removed from en, sk, pl. The grep gate for the key over lang, resources/js and tests returns nothing.

## Decisions
- Hide the tag for status `superseded` and for documents absent from the map (server already reports the latest run per document).

## Deviations from Plan
**[Rule 3 - Blocking] tests/js/i18n-phase8.test.js edited (not in files_modified).** It asserted the key still existed ("for plan 08-16 to remove"), so it would fail after removal and block the grep gate; it now asserts the key is absent in all three languages (built from a joined string so the grep gate stays clean).

## Verification
- AiDocumentRows (9), Visits, Documents, AiConsent, i18n-phase7/8 green.
- Full Vitest (node:22-alpine, `--testTimeout=30000`): 127 files, 3609 tests passed. `npm run build` passed (SW-PRECACHE OK). Full Pest: 2243 passed (25651 assertions).

## Known Stubs
None.

## Threat Flags
None. Rows render the status word only (T-08-102); the stale copy is removed (T-08-103).
