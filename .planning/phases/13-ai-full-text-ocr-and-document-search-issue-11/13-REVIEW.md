---
phase: 13-ai-full-text-ocr-and-document-search-issue-11
reviewed: 2026-11-17T00:00:00Z
depth: deep
files_reviewed: 61
findings:
  critical: 0
  warning: 6
  info: 8
  total: 14
status: issues_found
---

# Phase 13: Code Review Report (backend)

**Reviewed:** 2026-11-17 (the reviewer returned the body in its report; the orchestrator saved it here verbatim in substance)
**Depth:** deep
**Status:** issues_found

## Summary

Verified sound:
- **Search access:** the search SQL starts from `Visibility::apply` on `medical_documents`, which excludes archived documents and archived or hidden subjects. The person filter and the title match sit inside that same statement. No count query. A malformed or hidden person filter answers like "no match".
- **tsquery safety:** bound tokens are folded to `[a-z0-9]+` before the SQL fold, so no tsquery operator can be injected.
- **Text endpoints:** read and remove go through `Visibility` and the document-find helper; removal plus its audit row share one transaction.
- **Consent purge:** `setConsent` purges the person's texts in the same transaction as the flag change; `save()` takes `FOR SHARE` on the subject while `setConsent` takes `FOR UPDATE`.
- **Leak paths:** no export, doctor export or emergency card reads `medical_document_texts`; `DocumentText` is not an Eloquent model; job payloads carry the extraction id only; `ScrubbedQueryReport` covers `medical_*` QueryExceptions.
- **Bulk `no_text`:** never calls `finishReady`; the `text_mode_check` CHECK forbids ready or superseded rows in text mode; `AiReviewQuery` excludes `mode='text'`.
- **Eval tooling:** `DocumentPreprocessor` and `SkewEstimator` are reachable only from `app/Medical/Ai/Eval`; the command refuses outside staging and testing, needs `--confirm-synthetic`, and does no database writes.
- **DB role grants and migration `down()` guards** are fine.

Main concerns (all warnings): in-flight jobs ignore the OCR flag, a cancel race can still store text, the text notice can be satisfied by a dialog that never showed it, and the Copilot prompt cut is silent.

## Warnings

### WR-01: Queued, waiting and in-flight full/text runs ignore `ai.ocr.enabled=false`
**File:** `app/Medical/Ai/RunExtractionJob.php:125-199` (also `DocumentTextStore.php:44`)
**Issue:** The mode is read from the row at claim time and the flag is never re-read. If the owner switches `AI_OCR_ENABLED=false` as a rollback, the restarted ai-worker still claims `queued` rows and resumes `waiting` rows that carry `mode` full or text, so step 1 runs and stores recognised text while the feature is "off"; bulk `no_text` is held by the tick but already-enqueued text rows are not; notice acknowledgements are no longer enforced yet text is written. The spec says that with the flag off behaviour is identical to before.
**Fix:** in `RunExtractionJob::run`, right after `$mode` is read: if `$mode !== 'entries'` and the flag is off, a `text` run finishes as cancelled and a `full` run falls back to `entries`; also make `DocumentTextStore::save` refuse while the flag is off.

### WR-02: `DocumentTextStore::save` does not verify the run is still running or not cancel-requested
**File:** `app/Medical/Queries/DocumentTextStore.php:44-95`, called from `RunExtractionJob.php:131,315`
**Issue:** `save()` checks only consent and archive state. `finishReady` and `finishTextSaved` check status and `cancel_requested_at` under a row lock. (a) Cancel race: the user cancels after the post-CLI `recheck()` and before `save()`: the batch is still written for a cancelled run. (b) Reaped run: the stale reaper marks the row `failed(worker_lost)` while the job is alive; the zombie keeps saving batches and upserts over a newer run's text because the upsert key is `document_id`.
**Fix:** inside the `save()` transaction lock the extraction row and refuse unless it is `running` and not cancel-requested (skip for the seeder, which creates its row as `text_saved`).

### WR-03: The member text notice can be satisfied by a dialog that never showed the statement
**File:** `app/Medical/Queries/AiRunQuery.php:41-56,175-186`; `app/Http/Controllers/Health/AiRunController.php:40-44`
**Issue:** The admin bulk start requires an explicit `textNotice` claim; the member path does not. A member who never acknowledged loads the page with the flag off (dialog without the stored-text statement), the flag is switched on, the member confirms with `acknowledged=true` and the provider set, and the same upsert writes `text_notice_acked_at`. The `textNotice => true` hint is only returned on the 409 branch.
**Fix:** while `ai.ocr.enabled`, require `textNotice=true` in the run request before writing `text_notice_acked_at`; otherwise return `notice_required` with `textNotice: true` as the admin path does.

### WR-04: Copilot step 2 silently cuts the text but the run reports every page read
**File:** `app/Medical/Ai/Transcribe/ExtractFromTextTask.php:79-91`; `RunExtractionJob.php:209-226`
**Issue:** `inline()` cuts the body to `max_prompt_bytes` (90 kB) while `$textPages`/`$textTotal` come from the stored text, so `finishReady` records the full `pages_read` and the UI shows no partial marker; entries after the cut are missing and the reviewer is not told. Codex sends the whole text, so the same document yields different proposals per agent.
**Fix:** have the task report how many whole pages fit (`pagesIncluded()`) and pass `min($textPages, $included)` as `pages_read`, or fail with a content-free code when the text does not fit the Copilot budget.

### WR-05: A partial new run can overwrite a complete earlier text (upsert is by document)
**File:** `app/Medical/Queries/DocumentTextStore.php:140-150`, `RunExtractionJob.php:277-293`
**Issue:** A new run starts at `offset=0` because `savedText()` matches only its own id; its first `save()` replaces a complete earlier text with a one-batch partial. If the run then fails, is cancelled or the budget ends after batch 1, the document keeps a worse text than before (and `no_text` will never retry it).
**Fix:** refuse to overwrite a `complete=true` current-version text with a partial one, or write first batches under a staging key.

### WR-06: `pages_total` CHECK of 10000 and an unvalidated PDF page count
**File:** `database/migrations/2026_12_03_100000_add_text_mode_to_medical_ai_extractions.php:25`, `RunExtractionJob.php:172`
**Issue:** `$pages['total']` is passed straight into `save()` and `finishTextSaved`. A PDF reporting more than 10000 pages trips the CHECK; the QueryException is swallowed by the job's catch and the run ends as `worker_lost` instead of a clean code.
**Fix:** `$pagesTotal = min($pages['total'], 10000)` (or fail with `UnsupportedDocument` above it).

## Info

- **IN-01** `TextNormalizer.php:25` keeps zero-width and line-separator characters (U+200B-U+200D, U+2060, U+FEFF, U+2028, U+2029): a zero-width space inside a word splits it for search and can hide text. Strip them.
- **IN-02** `SearchTerms.php:33` drops tokens shorter than 2 characters and tokens whose ASCII fold is not `[a-z0-9]+` without telling the user ("vitamin C" becomes "vitamin"). Return the dropped count to the UI or document it in the help text.
- **IN-03** `TextNormalizer.php:42-50`, `RunExtractionJob.php:285`: 20 pages x 12000 chars exceeds the 200000-char total; pages that do not fit are dropped and re-transcribed on resume. Make `max_pages * max_page_chars <= max_total_chars` or stop the loop once the body is full.
- **IN-04** `2026_12_02_100000_create_medical_document_texts_table.php:14-21`: both catch branches throw; simplify to one catch.
- **IN-05** `2026_12_03_120000_add_text_notice_to_ai_notice_acks.php:22-25`: `down()` drops acknowledgements silently while the other migrations refuse when data would be lost; refuse when any row is non-null or note it in the runbook.
- **IN-06** `scripts/restore-drill.sh:104-107`: the `search is not null` check can never fail (generated column); compare a `@@ to_tsquery` probe or drop the check.
- **IN-07** `AiOcrEval.php:66-91,111-116`, `EvalGuardQuery.php`: the "worker free" check is not held for the run (take a `pg_advisory_lock`); the report file is created with the process umask and then chmod 0600 (create with touch + chmod first).
- **IN-08** `CopilotRunner.php:36`: step 2 puts up to 90 kB of recognised text on the process argv (visible in `/proc/<pid>/cmdline` inside the ai-worker container). Accepted CLI constraint; record it in the 13-RESEARCH privacy table and keep the ai-worker single-purpose.
