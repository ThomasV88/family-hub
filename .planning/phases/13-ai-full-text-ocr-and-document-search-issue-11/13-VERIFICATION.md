---
phase: 13-ai-full-text-ocr-and-document-search-issue-11
verified: 2026-11-17T00:00:00Z
status: human_needed
score: 6/6 must-haves verified (code level); 4 human items outstanding
overrides_applied: 0
human_verification:
  - test: "Admin screens on staging: Settings > AI calls, Settings > AI agents bulk panel (no_text scope dry run, Text only history tag)"
    expected: "Scope option 'no_text' is offered only with ai.ocr.enabled on; dry run counts documents without text; history shows the Text only tag"
    why_human: "No admin session during staging verification; covered only by Pest/Vitest (BulkNoTextTest, bulk panel tests), not live"
  - test: "Staging: second run reuse of a complete text, archive and restore of a document with text, 360 px in pl and dark mode, manual page and '?' tips render"
    expected: "No re-transcription; archived document disappears from search and returns on restore; no overflow; texts render"
    why_human: "Not done in a live browser (13-10 SUMMARY lists these as not verified live)"
  - test: "Read 3-5 real (non-synthetic) documents by eye next to the stored text"
    expected: "Text acceptable; otherwise set AI_OCR_TEXT_RELIABILITY=uncertain"
    why_human: "Evaluation corpus was synthetic and clean (CER 0.0 %), real phone photos, handwriting and stamps were not measured (stated in 13-EVALUATION.md)"
  - test: "Native review of Slovak and Polish drafts (lang keys, manual sentences, help tips)"
    expected: "Correct natural wording"
    why_human: "sk/pl are drafts by design (CLAUDE.md rule)"
---

# Phase 13: AI full-text OCR and document search - Verification Report

**Goal:** Evaluate and, if it holds up, change AI document reading to transcribe first, store the full recognised text for search, then extract structured JSON from that text; measure pre-processing.
**Status:** human_needed (no code gaps found; remaining items cannot be verified programmatically)
**Re-verification:** No, initial.

## Observable truths

| # | Truth (ROADMAP SC) | Status | Evidence |
|---|---|---|---|
| 1 | Evaluate first (how OCR works, Codex contrast handling, pre-processing candidates) | VERIFIED | `13-EVALUATION.md` with decisions block `status: complete`; resolution probe confirms Codex downsizes to 2048 px and does no contrast change; eval tooling under `app/Medical/Ai/Eval` |
| 2 | Measured with/without pre-processing, accuracy, time, quota, recommendation | VERIFIED | Tables of CER/WER/diacritics/recall per arm, 148 of 150 calls, time p90 14.52 s, token totals; decision `preprocess: none`. Caveat: synthetic clean corpus (ceiling at 0.0 %), recorded for the owner |
| 3 | Two-step reading, text stored, then entries extracted from text, schema validated | VERIFIED | `RunExtractionJob` (modes entries/full/text), `Transcribe/TranscribeTask`, `TranscriptionValidator`, `ExtractFromTextTask`; real staging run: 2-page PDF, mode full, ready, 2 AI calls, text exact |
| 4 | Full-text search limited by per-person access rules | VERIFIED | `DocumentSearchQuery` starts from `Visibility::apply(medical_documents)` (archived documents and hidden/archived subjects excluded), one statement, no count query, tsquery tokens folded to `[a-z0-9]+` and bound; table has generated tsvector + GIN with `fh_unaccent`; tests `DocumentSearchPrivacyTest`, endpoint matrix `health-search.php`, `MedicalPrivacy/zz_search.php` |
| 5 | Privacy decision recorded before implementation; access, retention, deletion, archive, no logs/exports, backups, notice, manual | VERIFIED | `.planning/ISSUES-6-11-OWNER-DECISIONS.md` Phase 13 section; composite FK `ON DELETE CASCADE` to documents; text view and removal go through Visibility; no export/doctor export/emergency card reads the table (only AiBulkEligibility, AiRunQuery, AiJobQuery, text store/query, search query, StagingAudit); `DocumentTextLeakTest`, `AiJobLeakTest`, `DocumentTextArchTest`, `MedicalPrivacyArchTest`; staging leak counts 0 in ai_calls, audit_logs, jobs, logs; backups note and restore drill in `docs/ops/server.md`; per-member notice re-ack (`text_notice_acked_at`) |
| 6 | Bulk can backfill text within quota; OCR remains explicit per-document with consent | VERIFIED | Scope `no_text` in `AdminAiBulkQuery`/`AiBulkJobQuery`, refused when `ai.ocr.enabled` off; text-only rows use mode `text`, CHECK forbids ready rows; `BulkNoTextTest`; per-document run still needs consent and acknowledgement |

## Specific checks requested

- **Stored text table with access rules:** migration `2026_12_02_100000_create_medical_document_texts_table.php` (CHECKs for size, pages, language; FK cascade; down() refuses while rows exist). VERIFIED.
- **Search scoped by Visibility:** see truth 4. VERIFIED.
- **Two-step pipeline:** VERIFIED in code and on staging.
- **Flag `ai.ocr.enabled` honoured in worker:** `RunExtractionJob.php:129` (text run cancelled, full run downgraded to entries), `DocumentTextStore::save` returns Disabled while off, bulk and run query gates. Review WR-01 fixed (commit 90a80ed). VERIFIED. Config default is false (`config/ai.php`); production env does not enable it (checked on staging verification).
- **Consent withdrawal purge:** `SubjectQuery::setConsent` calls `purgeForSubject` in the same transaction; staging showed search no longer finds the text after turning AI off. VERIFIED.
- **Bulk scope no_text:** VERIFIED.
- **Manual and help in en/sk/pl:** `resources/manual/{en,sk,pl}/health.json` contain search and recognised-text sections; help keys `health.search`, `health.ai_run`, `settings.ai_bulk` present in all three help files (3 matches each plus recognised-text topic in registry); parity and length tests exist (13-10). sk/pl are drafts needing native review. VERIFIED (structure), human for wording.
- **No leaks to logs/exports:** VERIFIED by code scan (no logging in store/query, `ScrubbedQueryReport`, job payload carries ids only) and staging counts.

## Code review status

13-REVIEW.md: 0 critical, 6 warnings, 8 info. 13-REVIEW-FIX.md: all warnings fixed with tests (WR-01 to WR-06 backend; UI warnings fixed). Skipped by decision: backend IN-04 (cosmetic, migration already pushed), IN-05 (runbook note: do not roll back the notice-ack migration where members acknowledged), IN-07 (eval command lock/report umask; staging/testing only with `--confirm-synthetic`). None affect the goal. Info-level, not blockers.

## Test evidence (not re-run by this verification, per instruction)

Per summaries: full Pest 4005 passed / 0 failed after review fixes; Vitest 193 files / 6606 passed; build OK. Not re-executed here.

## Anti-patterns

No debt markers examined as blockers; "Known stubs: None" in 13-10 SUMMARY; no stub found in the inspected files.

## Gaps

None blocking. Observations for the owner:
1. The accuracy evidence comes from clean synthetic documents only; `text_reliability` can be switched to `uncertain`.
2. Some "Nothing was saved." run-state strings (`failed_detail`, `cancelled_detail`, `consent_detail`, `quota_*_detail`) still read ambiguously after text was saved (known wording tension in 13-10 SUMMARY, proposal only).
3. Staging browser checks of admin screens were not done live; covered by Pest/Vitest tests.

_Verifier: Claude (gsd-verifier)_
