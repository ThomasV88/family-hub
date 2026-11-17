---
phase: 13-ai-full-text-ocr-and-document-search-issue-11
plan: 06
subsystem: medical-ai-transcription
tags: [ocr, transcription, queue, privacy, consent]
requirements: [GH11-SC3, GH11-SC5]
key-files:
  created:
    - database/migrations/2026_12_03_100000_add_text_mode_to_medical_ai_extractions.php
    - app/Medical/Ai/Transcribe/TranscribeTask.php
    - app/Medical/Ai/Transcribe/TranscriptionValidator.php
    - tests/Unit/Ai/TranscriptionValidatorTest.php
    - tests/Feature/Ai/TextSavedStatusTest.php
    - tests/Feature/Ai/TranscriptionStepTest.php
  modified:
    - config/ai.php
    - app/Medical/Ai/RunExtractionJob.php
    - app/Medical/Queries/{AiJobQuery,AiRunQuery,AiReviewQuery,AiBulkEligibility,AiBulkJobQuery}.php
    - tests/Support/FakeCodex.php
    - tests/Feature/Ai/AiJobLeakTest.php
    - tests/Feature/Health/{DocumentTextSchemaTest,DocumentTextStoreTest,DocumentSearchTest}.php
    - tests/Unit/Arch/DocumentTextArchTest.php
---

# Phase 13 Plan 06: Transcription step Summary

Step 1 of the two-step reading as the evaluation decided it: a run in mode `full` or `text` transcribes the document (images and converted HEIC as one page, PDFs at the existing 1800 px in batches of 4 pages per call, at most 20 pages, no pre-processing), stores the text after every validated batch with the `complete` flag, resumes after a quota park at the next unread page, and a text-only run ends in the new terminal status `text_saved`. With `ai.ocr.enabled` false (default) every new run is mode `entries` and behaves exactly as before. Step 2 of a `full` run is still the existing image extraction (prompt v1); 13-07 replaces it.

## Commits
- 440d6ef mode column, `text_saved` status, status sites, ocr config numbers, schema and arch tests
- 7c1cd23 `TranscribeTask`, `TranscriptionValidator`, `FakeCodex::transcribing`
- 4be7055 step 1 in `RunExtractionJob`, step and leak tests, forced-error test switched to `pages_total` 0, two `DocumentSearchTest` expectations

## Tests
Isolated compose project `fh1306` (own Postgres 18, scratch folder over storage/logs through an override file, serial), removed with `down -v` at the end. Pint `--test`: pass (1231 files). Task suites green: Feature/Ai + Unit/Ai + Feature/Health + Unit/Arch (1173 passed after fixing the two search expectations). Full Pest results: see "Final full Pest run". Vitest not run: no JS, lang, help or manual file touched.

## Status sites (grep of `'ready'`, `'superseded'`, `status IN`, `whereIn('status'` in app/ and every `medical_ai_extractions` user)
| Site | text_saved / mode treatment |
|---|---|
| `AiRunQuery::start` | writes `mode` = `full` when `ai.ocr.enabled`, else `entries` |
| `AiRunQuery::busy` | cool-down counts `text_saved` like `ready` |
| `AiRunQuery::statusMap` | per entry `mode` and `textSaved` (EXISTS of a text row with this extraction id, a subselect inside the Visibility-scoped statement, same flag for View and Edit); a `text_saved` row is skipped while the document has a `ready` row; `pagesRead/pagesTotal` already shipped for every status; `readyExtractionId` unchanged |
| `AiReviewQuery::review` (newest run) and `newerWaiting` | ignore `mode = 'text'` rows |
| `AiReviewQuery` decide paths | unchanged: they act on `ready` only, a `text_saved` id is refused |
| `AiBulkEligibility` cool-down | counts `text_saved` |
| `AiBulkEligibility` `last_failed` | orders only rows with `mode <> 'text'` |
| `AiBulkEligibility` `no_proposal` | unchanged (only `ready`/`superseded` are proposals; 13-08 adds `no_text`) |
| `AiBulkJobQuery::reconcile` | `text_saved` gives outcome `read` |
| `AiBulkJobQuery` (active counts, cancel) and `EvalGuardQuery` | queued/running/waiting only: unaffected |
| `AdminAiBulkQuery::tally` | joins on `i.outcome` first, `e.status` only for waiting/inflight: unaffected |
| `AiJobQuery::finishReady` supersede | only `status = 'ready'` rows: a `text_saved` run is never superseded |
| `AiJobQuery::reapStuck` | running/queued/waiting only |
| `StagingAudit`, `MedicalAiExtraction` model | no status list |
| Vue status sites (`AiStatusTag`, `RunAiControl`, `TimelineList`, `DocumentPanel`, `AiReview`) | not touched here, belong to 13-09 |

## Job-side API (for 13-07 and 13-08)
- `AiJobQuery::claim` also returns `mode`.
- `savedText(id): ?array{pages, pagesRead, pagesTotal, complete, language}` only when the text row's `extraction_id` is the run.
- `closeStep(id, model, durationMs)` closes the open call as `succeeded` (extraction stays running).
- `finishTextSaved(id, pagesRead, pagesTotal, agent, model, durationMs): bool` (row lock, `mode = 'text'`, not cancel-requested, consent FOR SHARE, person and document not archived, text row of this run exists; never supersedes).
- `RunExtractionJob`: `transcribe()` (step 1) and `attempt()` (one agent call: provider filter, rechecks, abort loop, quota/auth fallback, parking; returns `ok`, `failed` or `end`). Step 2 in 13-07 should call `attempt()` with its own `AiTask`. `AgentInput` takes the batch images, the work dir keeps all page images.
- `FakeCodex::transcribing($stateFile, $logFile, $prefix, ['extract' => json, 'fail_nth' => n, 'fail_kind' => invalid|quota|error])`: one page per image (`<prefix> <absolute page>`), log lines `T <images>` / `X`.

## Decisions applied
preprocess none, `ai.pdf.scale` 1800 untouched, 4 pages per call, max 20 pages, 14.52 s per page, `text_reliability` reliable, timeouts 300/660 untouched, `ai.ocr.enabled` false. The budget rule: with text already stored a batch runs only if `deadline - now >= pages * 14.52 + (full ? 120 : 0)`. No reference to `Documents\Preprocess` anywhere in the job (arch assertion added).

## Deviations
1. [Rule 1] `ai.ocr.text_reliability` default change to `reliable` (plan, decision block) broke two 13-04 expectations in `DocumentSearchTest` (default-config assertions); updated those two to `reliable`. The `nonsense` cases still read `uncertain`.
2. [Rule 1] `statusMap` entries carry an extra `mode` key besides `textSaved` (the plan selects mode; 13-09 may need it).
3. `FakeCodex::transcribing` is a new method (additive) rather than an option of `bySchema`; existing scripts unchanged.
4. Consent-withdrawn-between-batches and archived-between-batches tests use a `DB::listen` hook on the second batch's call-row insert (the CLI is a shell script and cannot reach the test process). The consent test calls the real `SubjectQuery::setConsent`, so the 13-03 purge removes the first batch's text.
5. A text run that fails with a terminal code after the first batch ends `text_saved` with the stored partial text (as planned); the failed call row keeps its code (`invalid_output` in the test).
6. The `config/ai.php` ocr block is committed with task 1 (the schema test pins the default), not task 2.

## Security notes
- No catch around `DocumentTextStore::save`; the outer catch stays `RuntimeException('Extraction job failed.')` with no previous. A forced trigger error carrying `NEW.body` ends as `worker_lost` and the canary is absent from failed_jobs, jobs, ai_calls, audit_logs, the extraction row, logs and the exception (message, previous, trace).
- Transcription replies are validated with exact keys, exact page set, per-page and total caps and a file cap; issue codes name places only (canary test). `TextNormalizer` still strips control and bidi characters in `save`.
- CLI lockdown untouched (read-only sandbox, `env -i`, no API keys, same runners). Bulk rows keep Codex-only (test: Copilot never started).
- Threat register: T-13-31 to T-13-35 and T-13-37 mitigated as planned; T-13-36 (Copilot transcription quality untested) accepted, best effort through the existing fallback rules and notice.

## User documentation
No user-visible change: `ai.ocr.enabled` is false by default so every run stays mode `entries` and behaves as before; mode `text` rows are only created by the bulk scope of 13-08; the `text_saved` tag, run detail lines, notice and manual wording come with 13-08 and 13-09 in the same phase and PR. The `text_reliability` default change has no visible effect because no text row exists in production. No `resources/manual/` or `resources/help/` file touched.

## Known stubs
None.

## Final full Pest run
First clean serial run: 3927 passed, 1 skipped, 1 failed. The failure was `Feature/Health/SubjectTest` (`creates a person`), which asserts that a random-content audit row does not contain the substring `Ada`; the file is untouched by this plan and passed twice on rerun (32 passed each). Second full serial run, one clean pass: 3928 passed, 1 skipped, 0 failed (36439 assertions).

## Self-Check
Files and commits listed above exist (`git log` shows 440d6ef, 7c1cd23, 4be7055).
