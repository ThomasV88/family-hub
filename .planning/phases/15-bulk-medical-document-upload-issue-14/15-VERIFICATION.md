---
phase: 15-bulk-medical-document-upload-issue-14
verified: 2026-11-18T00:00:00Z
status: human_needed
score: 5/5 roadmap criteria verified in code and staging; 3 items need a human
gaps: []
human_verification:
  - test: "Native review of Slovak and Polish texts (lang health.batch.*, audit labels, manual bulk-upload section, who-sees-what row, four help tips)"
    expected: "Natural wording in sk and pl"
    why_human: "Drafts by design (CLAUDE.md rule); only a native speaker can judge"
  - test: "Observe a live quota pause and self-resume on staging"
    expected: "On a real usage-limit the batch parks, then resumes by itself via ai:resume-waiting without losing finished work"
    why_human: "Never exercised live (no limit hit on staging); only a seeded paused row and fake-codex tests exist"
  - test: "Staging step 5: review mix with an undecided finding"
    expected: "Documents with 3+ findings (approve, reject, leave one undecided): Continue saves approved, discards rejected, undecided stays reviewable"
    why_human: "Staging AI returned only 2 findings per page so this path was not exercised live (local Pest tests cover it)"
---

# Phase 15: Bulk medical document upload - Verification

**Goal:** Owner uploads many medical documents at once and reviews AI results one by one, resumably (GitHub #14).

Not run by me: the local PHP runtime fails platform_check, so Pest could not be executed here. Test results rest on 15-09-SUMMARY (4143 Pest passed, 7018 Vitest passed) and the staging run; I verified code existence and wiring directly.

## Success criteria

| # | Criterion | Status | Evidence |
|---|---|---|---|
| 1 | 20+ files, one batch, one person, one at a time, visible status | VERIFIED | `config/medical.php` upload_batches max_files 50; `UploadBatchJobQuery::advance` + `ai:upload-batch-tick` every minute (`routes/console.php`); staging: 22 files uploaded, max 1 active extraction per batch via polling and SQL |
| 2 | Approve/reject per finding, Continue saves approved, re-scan | VERIFIED (partial live) | `UploadBatchQuery::saveChoices/continue/requestRescan`; routes `choices`, `continue`, `rescan`; UploadBatchContinueTest; staging step 5 Continue saved 1 and discarded 1 (undecided path not live, see human items) |
| 3 | Adjustments only on AI copy, original unchanged, remembered | VERIFIED | `app/Medical/Documents/AiCopyAdjuster.php` used by `RunExtractionJob`; `adjustments` route; staging: stored original sha256 identical before and after adjusted re-scan |
| 4 | Reload / other device resumes | VERIFIED | Choices, adjustments, cursor (`current` route) stored in DB; staging: marks and edit visible in a second browser context after reload |
| 5 | Quota pause and self-resume, 30-day expiry, access, consent, audit, no content in logs | VERIFIED in code, live pause not observed | waiting run counts as active, `ai:resume-waiting` every minute; `expire_days` 30 and hourly `medical:expire-upload-batches` -> `expireDue`; `access:module,health,edit` on all 10 routes with uniform 403; consent off paused and on resumed within 10 s on staging; `UploadBatchPrivacyTest` plus staging greps: zero content in logs, audit_logs, ai_calls, failed_jobs |

## Owner decisions

- One person per batch: batch created under `people/{person}`; one open batch per person. VERIFIED.
- Server-side persistence, 30-day expiry: migration `2026_12_04_100000_create_medical_upload_batches`, `expires_at`, `ExpireUploadBatches`. VERIFIED.
- Continue saves approved findings per document via the accept path; adjustments only on the AI copy; quota pause/resume; access, consent, audit, no content in logs: VERIFIED as above.

## Requirements BULK-01..09

All nine are claimed by plans and marked Complete in REQUIREMENTS.md. BULK-01 to 08 SATISFIED by the evidence above. BULK-09: manual (`resources/manual/{en,sk,pl}/health.json` bulk-upload, who-sees-what) and help (`resources/help/topics.json` batch_start/adjust/continue/rescan plus en/sk/pl) exist; coverage tests reported green. SATISFIED with sk/pl drafts pending native review. No orphaned requirements.

## Anti-patterns

No TBD/FIXME/XXX in the batch query files or batch Vue files. No v-html on batch screens (per 15-09). Gap-closure 15-10 fixed the three staging defects (row layout, stale review, delete 403) and was re-verified on staging.

## Warnings (non-blocking)

- `phpunit.xml` memory_limit raised to 1024M; full Pest suite takes about 11 minutes.
- AI helper used 18 calls on staging against a plan of about 5 (budget overrun, no functional impact).
- A re-scan stays queued while another item of the same batch is paused on quota (by design; a UI hint is a possible later improvement).
- 15-10 layout fix verified structurally in tests and on staging at 360/820/1280 px.
- ROADMAP.md still shows "4/9 plans executed" and unchecked boxes for 15-05..15-09; bookkeeping only, should be updated by the orchestrator.

## Status

human_needed: all must-haves are verified in code and on staging; three items remain that need a human (native SK/PL review, live quota pause/resume, undecided-finding path).
