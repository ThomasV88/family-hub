---
phase: 08-ai-extraction-platform
plan: 19
subsystem: ai
tags: [ai-review, vue, edit-in-place, a11y]
requires: ["08-15", "08-18"]
provides:
  - Health/Ai/EntryEditForm.vue (per-type Phase 7 field set, server error flags, dirty tracking)
  - In-place edit, edit-accept and 422 flagging in SuggestionEntry.vue
  - Page-level single-edit coordination and discard confirm in Pages/Health/AiReview.vue
affects: [08-21, 08-22]
key-files:
  created:
    - resources/js/Health/Ai/EntryEditForm.vue
    - tests/js/health/AiReviewEdit.test.js
  modified:
    - resources/js/Health/Ai/SuggestionEntry.vue
    - resources/js/Pages/Health/AiReview.vue
    - tests/js/health/AiReview.test.js
requirements-completed: [AI-02]
completed: 2026-11-14
---

# Phase 8 Plan 19: Edit-in-place for AI suggestions Summary

Edit expands a suggestion into the Phase 7 field set for its type, saves the edited values through the accept endpoint (`{values}`), flags server 422 fields inside the form, and enforces one open edit with discard confirms.

## Commits
- 4fe7a30 feat(08-19): per-type edit form and in-place editing of AI suggestions
- e484fb0 feat(08-19): one edit at a time on the review page with discard confirm and tests

## Verification
- AiReviewEdit.test.js plus AiReview.test.js: 97 tests pass (edit field sets, specialty_other toggle, save body, edited line, focus, network/409/423, 422 flagging for allergy severity, vaccination date and `values.`-prefixed keys, Enter, Esc, dirty and clean cancel, discard and keep editing, one edit at a time, pane toggle keeps form, no storage, axe en/sk/pl light and dark with each type open with and without errors, 320 px with long values).
- Full Vitest (container node:22-alpine, `--testTimeout=30000`): 130 files, 3743 tests passed.
- `npm run build` passed (SW-PRECACHE OK).
- Full Pest in the foreground (1G): 2242 passed, 1 failed (`EndpointMatrixTest` "reads real grants: revoking a grant flips a todo probe from 200 to 403", line 83) in the first full run. It passed on two isolated reruns of the file (169 passed). No PHP was changed by this plan; 08-20 ran Pest concurrently against the same Postgres, so this looks like cross-run interference, not a regression. Flagging it for the orchestrator.
- Compose project fh0819 removed with `down -v`. The Vitest and build containers were run with `docker run` (`node:22-alpine`), since the compose file has no node service.

## Decisions (PROVISIONAL, for 08-22 review)
- **provide/inject instead of props.** `SuggestionList.vue` is not in this plan's files, so the page `provide`s `reviewEdit` ({editingKey, errors, specialties, close, setDirty}) and `SuggestionEntry` injects it. Without a provider an entry has no edit form.
- **Discard dialogs.** Dirty Cancel or Esc inside an entry uses an entry-level `UiConfirmDialog`; switching to another entry while dirty uses a page-level one. Both show "Discard your edit?" with "Keep editing" (default focus) and "Discard edit" (danger). `UiConfirmDialog` requires a body and the lang files have no discard body key (lang belongs to 08-23), so the body shows the entry's name (doctor, medication, substance or vaccine). A real body sentence would be a later copy addition.
- **Keep editing** restores focus into the open form via `setTimeout(0)`, after the dialog's focus trap returns focus to its opener.
- **Save button label** uses the existing `save_<type>` keys ("Add visit" etc.), Cancel uses `cancel_edit` ("Cancel edit").
- **Notes fields** are included for visit, medication and vaccination (server requests accept them, proposals carry them, and the entry shows them), though the 07 vaccination and medication panels have no notes input. Label reuses `health.visit.notes`.
- **No client validation** (T-08-88): the form posts whatever is entered (empty strings as null) and only displays server errors. Allergy severity 422 shows `needs_severity`. Keys with a `values.` prefix are normalized. Unmapped errors show the generic `health.error.load` alert in the form.
- **Edited flag.** A successful Save marks the entry edited locally even if the server answer omitted `edited`.
- A 423 or 409 on Save closes the form and discards the draft; nothing is replayed.
- A superseded run closes any open form.
- Singular type nouns were not added; accessible names keep the plural section heading convention from 08-18.

## Deviations from Plan
**1. [Rule 3 - Blocking] Updated two 08-18 tests in `tests/js/health/AiReview.test.js`** (not in files_modified, but the plan's own verify runs it). "lists the server errors inline on 422" and "emits the edit request ... changes nothing yet" asserted the 08-18 empty-hook behavior; they now assert that the form opens flagged and that Edit opens the form. Otherwise 08-18 tests are unchanged.
**2. `tests/Feature/Health/AiReviewPageTest.php` not tightened.** It is not in files_modified; left for 08-21 per instruction.

## Known Stubs
None. The 08-18 `onEditRequested` hook is now implemented.

## Threat Flags
None. T-08-88 mitigated (server is the only validator, form only displays errors). T-08-89 mitigated (drafts only in component memory, discarded on 423, storage and v-html grep in tests).

## Self-Check: PASSED
Files exist; commits 4fe7a30 and e484fb0 are in the log.
