---
phase: 15-bulk-medical-document-upload-issue-14
plan: 10
subsystem: batch upload frontend (gap closure)
tags: [gap-closure, layout, polling, inertia-reload]
requires: [15-06, 15-07, 15-09]
key-files:
  modified:
    - resources/js/Health/Batch/BatchUploader.vue
    - resources/js/Health/Batch/useBatchStatus.js
    - resources/js/Pages/Health/UploadBatch.vue
    - tests/js/health/BatchUploader.test.js
    - tests/js/health/UploadBatchPage.test.js
metrics:
  completed: 2026-11-18
---

# Phase 15 Plan 10: Staging defects in the batch page (gap closure)

Refused-file rows now keep the action button under the name and error, the review refreshes whenever the polled current document differs from the rendered page props, and polling stops when Delete batch is requested.

## Root causes

1. **Refused-file layout.** The row was a flex line (icon, text column, button). The button ("Remove file") was a sibling of the text column in a narrow card, took its width, and left the name a few characters; `overflow-wrap: anywhere` then broke it per character. Fix: the action button moved inside the text column (own line, `align-self: flex-start`), the name and error use `min-width: 0; overflow-wrap: break-word` (break inside a word only when it cannot fit).
2. **Review not appearing.** The reload watch fired only on a change of the current item's `state` AND only when `props.current?.item` existed with the same id. With no `current` props (first document of an empty batch, current document id changed by the server) the condition was never true, so the page kept the title-and-count fallback. Also a mismatch present at mount never retriggered. Fix: watch the mismatch itself (no current props, other id, or other state) together with the polled `id:state` key, so it fires on any polled change while the props are stale (also when an earlier reload answered with an older state, and after a re-scan finishes).
3. **Delete 403.** `useBatchStatus` gained `halt()`/`resume()`; `remove()` halts polling before the DELETE, ignores an in-flight poll answer, resumes only if the delete fails. The state-change reload is also suppressed while removing.

## Tests

- Vitest `tests/js/health`, `tests/js/brand`, `tests/js/i18n`: 50 files, 3493 tests pass.
- New: empty-batch/no-current reload, other-document reload, review shows after props follow poll, delete stops polling and ignores late 403, uploader button placement (button inside text column). The reload tests fail with the old watch condition.
- Layout was verified structurally (DOM placement), not by screenshot at 360/820/1280 px.
- `npm run build`: the full script failed with EACCES because `public/build/assets` is root-owned (leftover from an earlier container build, git-ignored); not touched. `vite build` to a scratch outDir compiled successfully and `scripts/check-sw-precache.mjs` printed SW-PRECACHE OK. Re-run `npm run build` after fixing ownership of that directory.
- No PHP touched, Pint not needed.

## User documentation

No user-visible change: no label, field, rule or text changed; layout fix and refresh timing only. No `resources/manual/` files touched.

## Deviations

None beyond the build-directory permission note above.
