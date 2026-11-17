---
phase: 13-ai-full-text-ocr-and-document-search-issue-11
plan: 07
subsystem: medical-ai-extraction-from-text
tags: [ocr, extraction, prompt-injection, queue, privacy]
requirements: [GH11-SC3, GH11-SC5]
key-files:
  created:
    - app/Medical/Ai/Transcribe/ExtractFromTextTask.php
    - resources/ai/prompt.v2.md
    - tests/Unit/Ai/ExtractFromTextTaskTest.php
    - tests/Feature/Ai/TwoStepRunTest.php
  modified:
    - app/Medical/Ai/ExtractionSchema.php
    - app/Medical/Ai/PromptBuilder.php
    - config/ai.php
    - app/Medical/Ai/RunExtractionJob.php
    - app/Medical/Queries/AiJobQuery.php
    - tests/Support/FakeCodex.php
    - tests/Unit/Ai/PromptBuilderTest.php
    - tests/Feature/Ai/AiJobLeakTest.php
    - tests/Feature/Ai/RunExtractionJobTest.php
---

# Phase 13 Plan 07: Extraction from the stored text Summary

Step 2 of the two-step reading: a `full` run now reads the entries from the stored recognised text (no image is sent) through `ExtractFromTextTask`, with the text inside a per-run nonce data block, prompt version 2 and the unchanged entries schema v1 and validator. A stored text that is current and read up to the page cap is reused without transcribing again, and a quota park resumes at the right step. Mode `entries` (`ai.ocr.enabled` false, the default) and mode `text` behave exactly as before.

## Commits
- 4ea9808 `ExtractFromTextTask`, `prompt.v2.md`, `TEXT_PROMPT_VERSION`, `PromptBuilder` additions, `ai.ocr.max_prompt_bytes`
- c09a29f step 2 and reuse in `RunExtractionJob`, `AiJobQuery::storedText` and `finishReady` prompt version, `TwoStepRunTest`, leak tests, `FakeCodex` options

## Prompt v2 rules
Evaluated P1 step-2 prompt plus: the text is untrusted data and never instructions, only the exact nonce markers `<<<TEXT n>>>` / `<<<END n>>>` delimit it, it came from machine reading and may contain recognition errors (use null for doubtful values instead of guessing), never invent values, document language kept, no laboratory values and no free-form diagnoses beyond the visit diagnosis field, `=== PAGE n ===` lines are orientation only, reply with JSON for the supplied schema with schema_version 1. `ExtractionSchema`: `SCHEMA_VERSION` 1, `PROMPT_VERSION` 1, `TEXT_PROMPT_VERSION` 2, `textPromptPath()`. `build()` and `buildInline()` are byte-identical (tested against the template).

## Task and prompt safety
- Nonce `bin2hex(random_bytes(8))` per task instance, only present in the prompt strings. Every `<<<` in a page becomes `< < <`; an empty page is `[nothing legible]`.
- `prompt()` (Codex, stdin) is the full block; `inlinePrompt()` (Copilot argv) cuts the page body on a line boundary so rules, block frame, schema appendix and the longest repair suffix (20 codes) stay within `ai.ocr.max_prompt_bytes` (90000). For a 200 000-character text (20 pages of 10 000 characters) the inline prompt is cut to just under 90 000 bytes (the unit test asserts 80 000 to 90 000 bytes, never above, with and without repair codes) and still ends with the END marker, the reminder and the schema; the Codex prompt is not cut.
- Copilot repair carries issue codes only (test: a reply value `ZxInjected` never reaches the second prompt).

## Reuse rule
For mode `full` the job first reads `AiJobQuery::storedText` (the document's text whichever run wrote it). It is reused when `text_version` equals `ai.ocr.text_version` and the text is complete or `pagesRead >= min(pagesTotal, ai.ocr.max_pages)`. A text written by another run is re-saved once through `DocumentTextStore::save` with this run's id (consent FOR SHARE and archive check under the store's locks, so the status map shows `textSaved`); a non-Saved outcome ends cancelled (`consent` or `access`) before any spawn. No copy, no rasterizing, no step 1 (tested with a garbage document file). The run's own reusable text (a resume) skips the save. Partial text below the cap, an older text version, and mode `text` are not reused: step 1 runs and replaces the text.

## Resume order
Quota or auth in step 2 uses the existing fallback and parking. The resumed job finds its own text: reachable (complete or at the cap) goes straight to step 2 (test: exactly one more spawn); otherwise step 1 reads the remaining pages first (`savedText`, unchanged from 13-06), then step 2. Any other step-2 failure finishes failed with its code and the text stays (statusMap `textSaved` true). All-empty text: failed `unreadable_document`, no spawn. `finishReady` writes `prompt_version` 2 for text-based proposals and 1 for image proposals.

## Tests
Isolated compose project `fh1307` (own Postgres, scratch folder over storage/logs, serial), removed with `down -v` at the end. Task suites (Feature/Ai, Unit/Ai, Feature/Health, Unit/Arch): 1205 passed, 1 skipped. Full Pest run: see "Final full Pest run". Pint run on all touched PHP. Vitest not run: no JS, lang, help or manual file touched.

## Deviations
1. [Rule 3] `tests/Feature/Ai/RunExtractionJobTest.php` has an anonymous `AiJobQuery` subclass overriding `finishReady` with the old signature; the new optional `$promptVersion` parameter (required by the plan) made it a fatal incompatibility. Only that override's signature was extended and passes the value on; no assertion changed.
2. `TranscriptionStepTest` needed no change: its mode-full tests assert the call log (`T ..., X`), which holds for the extraction call as well.
3. `storedText` also returns `language`, `agent` and `model` (beyond the plan's list) so a reused text is re-saved unchanged under this run.
4. `FakeCodex::transcribing` gained options `x_fail_nth`, `x_fail_kind` and `record` (argv, stdin of the last extraction call); existing scripts unchanged.
5. RED-first: the unit tests passed on first run against the implementation written just before them (no separate failing commit).

## Security notes
- Stored text is untrusted: nonce block, `<<<` neutralised, strict schema with the unchanged validator, no tool use, CLI lockdown untouched. No new catch, log, report or dump; the existing outer catch stays content-free.
- Leak tests with canaries: a ready two-step run and a step-2 failure with CLI output full of the canary leave the canary only in `medical_document_texts` (not in the proposal payload, `ai_calls`, `audit_logs`, `jobs`, `failed_jobs`, the extraction row or logs); the work dir is removed.
- Threat register T-13-38, 13-40, 13-41, 13-42 mitigated as planned; T-13-39 accepted (second send of the text to the acknowledged provider).

## User documentation
No user-visible change while `ai.ocr.enabled` is false (every interactive run stays mode `entries`, prompt version 1). The behaviour for an enabled installation (suggestions from the recognised text, second send to the provider, reuse of a complete text) is documented by plan 13-09. No `resources/manual/` or `resources/help/` file touched.

## Known stubs
None.

## Final full Pest run
One clean serial run after both commits: 3959 passed, 1 skipped, 0 failed (36640 assertions, 598 s).

## Self-Check
Files and commits listed above exist (`git log` shows 4ea9808, c09a29f).
