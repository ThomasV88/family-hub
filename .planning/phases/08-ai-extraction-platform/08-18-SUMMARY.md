---
phase: 08-ai-extraction-platform
plan: 18
subsystem: ai
tags: [ai-review, vue, accept-reject-undo, a11y]
requires: ["08-11", "08-15", "08-16"]
provides:
  - Pages/Health/AiReview.vue (review page)
  - Health/Ai/ReviewPaneToggle, DocumentPane, SuggestionList, SuggestionEntry
  - SuggestionEntry emits `edit-requested` ({key, errors|null}); page hook `onEditRequested` is where 08-19 plugs in
affects: [08-19, 08-21, 08-22]
key-files:
  created:
    - resources/js/Pages/Health/AiReview.vue
    - resources/js/Health/Ai/ReviewPaneToggle.vue
    - resources/js/Health/Ai/DocumentPane.vue
    - resources/js/Health/Ai/SuggestionList.vue
    - resources/js/Health/Ai/SuggestionEntry.vue
    - tests/js/health/AiReview.test.js
requirements-completed: [AI-02]
completed: 2026-11-14
---

# Phase 8 Plan 18: AI review screen Summary

The review page shows the document (image, or PDF one page at a time with a pager) beside the proposed entries on wide screens, or stacked with a Document / Suggestions toggle, and lets a member Accept, Reject and Undo each entry against the server, with text-only rendering of all model output.

## Commits
- b51c3b3 feat(08-18): review pane toggle and document pane
- 9bd10d4 feat(08-18): side-by-side AI review page with accept, reject and undo

## Verification
- AiReview.test.js: 65 tests pass (layout, pane toggle with arrows and `?pane=` replace, PDF pager, no-preview card, preview recovery, header lines, sections and order, literal-HTML entries, accept, duplicate, 409, 422, network, 423, reject and undo with focus, no storage writes, v-html grep, axe in en/sk/pl for pending, accepted, superseded, consent-off, pdf, card, empty, loading, error; light and dark; 320 px with +40% titles and a busy and failed entry on both panes).
- Full Vitest (container node:22-alpine, `--testTimeout=30000`): 128 files, 3676 tests passed.
- `npm run build` passed (SW-PRECACHE OK).
- Full Pest in the foreground (1G): 2243 passed (25651 assertions), 0 failed, 200 s. No PHP changed.
- `grep v-html` over the page and `Health/Ai` returns nothing; no storage API in these files. Compose project fh0818 removed with `down -v`.

## Decisions (PROVISIONAL, for 08-22 review)
- **Entry markup:** `<li>` containing a `UiCard` with an inner `<div role="group" aria-labelledby aria-describedby>`, not `<li role="group">`, because a `ul` whose `li` carries a non-listitem role breaks axe list rules and the owner rule is axe-clean. Same accessible group semantics.
- **Layout switch** is JS (`useFrameWidth` on the page root, threshold 800 px) rather than a pure CSS container query, because both layouts need different DOM (toggle, skip link, `hidden`). Both panes are always mounted (`v-show` plus `hidden`).
- **Accessible names use the section heading as the type word** ("Accept Medications: Ibuprofen"), since the lang files have no singular type nouns and `lang/*.json` belongs to 08-23 in this wave. A singular-noun key set would fix the wording later.
- **Compare line for PDF/HEIC without page images:** no `health.ai.review` key exists, so the card shows the 07 "No preview" tag and `no_preview_body` plus "Open in new tab"; the UI-SPEC line "Open the document in a new tab to compare." is not shown.
- **Superseded link** uses the existing `open_newest` copy ("Open the newest run"), linking to `?extraction=<newest id>`, or the plain review URL when none is ready.
- **Loading and load-error states** are driven by an optional `loading` prop and a missing `person/document/extraction` prop (retry = `router.reload()`); the server normally renders the page complete.
- Announcements: accept reads "Added to the record. {n} suggestions left to review" (or the completion text at zero); reject, undo as in the lang keys. Accept with a server answer of `rejected` shows Rejected and focuses Undo.
- Back links and "Open in record" are plain anchors (`UiButton href`), so they do a full navigation, consistent with other Health links.
- Edit button emits `edit-requested` with `errors: null`; a 422 emits it with the server errors and also lists the translated messages inline. The page's `onEditRequested` is an intentional empty hook for 08-19.

## Deviations from Plan
**1. 08-15 Pest assertion not tightened.** `tests/Feature/Health/AiReviewPageTest.php` (`component('Health/AiReview', false)`) is not in this plan's files_modified, so it was left alone. The page now exists, so it can become `component('Health/AiReview')` (and drop the `false`) in 08-22 or 08-21.
**2. `tests/js/a11y-sweep.test.js` untouched**, as instructed; 08-21 registers the page.
Otherwise the plan ran as written.

## Known Stubs
- `onEditRequested` in `AiReview.vue` is an empty hook; Edit does nothing visible until 08-19 (the 422 path shows inline errors meanwhile). Resolved by 08-19.

## Threat Flags
None. T-08-84 to T-08-87 mitigated as planned (text interpolation only with literal-HTML test and v-html gate, no storage APIs, always-visible warning with no Accept all, no optimistic UI and duplicate answers shown as real state).

## Self-Check: PASSED
Files exist; commits b51c3b3 and 9bd10d4 are in the log.
