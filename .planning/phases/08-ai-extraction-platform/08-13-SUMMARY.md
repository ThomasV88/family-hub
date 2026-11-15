---
phase: 08-ai-extraction-platform
plan: 13
subsystem: ai
tags: [ai-run, consent, queue, status, leak-net]
requires: ["08-01", "08-09", "08-12"]
provides:
  - AiRunQuery (start, cancel, statusMap, noticeAcknowledged), StartResult
  - Routes health.documents.ai.run, health.ai.cancel, health.people.ai.status
  - PersonPage prop `ai` {canRun, available, noticeAcknowledged, statuses}
  - tests/Support/MedicalPrivacy/zz_ai.php, EndpointMatrix/health-ai.php
affects: [08-15, 08-16, 08-17]
key-files:
  created:
    - app/Medical/Queries/AiRunQuery.php
    - app/Medical/Ai/StartResult.php
    - app/Http/Requests/Health/AiRunRequest.php
    - app/Http/Controllers/Health/AiRunController.php
    - routes/web/health-ai.php
    - tests/Support/EndpointMatrix/health-ai.php
    - tests/Support/MedicalPrivacy/zz_ai.php
    - tests/Feature/Health/AiRunTest.php
  modified:
    - app/Medical/PersonPage.php
requirements-completed: [AI-01, AI-03, AI-04]
completed: 2026-11-14
---

# Phase 8 Plan 13: AI run start, cancel and status Summary

An Edit member can start, cancel and follow an AI run on a PDF or image document. Consent, availability, kind, notice and one-active-run are enforced in one transaction under a FOR SHARE lock on the person row, and the job is dispatched after commit with an id-only payload.

## Commits
- 52d9cae feat(08-13): add AI run start, cancel and status routes
- ee186e0 feat(08-13): add AI props to the person page

## Verification
- AiRunTest (19), SensitiveGateMatrixTest, MedicalPrivacyTest, MedicalWriteParityTest: 48 passed.
- Full Pest (1G on the command line, 205 s): 2206 passed, 1 failed. The failure was NotificationSettingsTest (admin section order now includes `ai_calls`), caused by the parallel plan 08-14 and fixed by that plan in 8a956d3. Not re-run after that commit, nothing in this plan touches it.
- Pint clean on all 9 PHP files. Peak memory not measured; phpunit.xml unchanged (the run used `-d memory_limit=1G`).
- Vitest and `npm run build` not run: this plan changes no JS or Vue files, and the host has Node 18.

## Decisions (PROVISIONAL, for 08-22 review)
- `AiRunRequest::authorize()` also resolves the document at Edit level, not only the person, so a hidden or unknown document with an invalid body gives the same 403 as the plan demands (a person-only check would have returned 422 for a visible person with a bad document id).
- Order of start checks: denied, unsupported, consent_off, unavailable, notice_required, active, then audit, extraction, call row, ack. `active` is detected by a scoped pre-check; a lost race on the partial unique index (SQLSTATE 23505) rolls the whole transaction back (audit row included) and answers `active` with the winner's id.
- Ack is written with `insertOrIgnore` only when not yet present; a later run without `acknowledged` works.
- Cancel locks the extraction row FOR UPDATE inside the Edit scope, then branches: queued becomes cancelled(user) (extraction and call get model 'default', audit `medical_ai_run_finished`), running sets `cancel_requested_at` once, any other status is returned unchanged with no writes.
- `statusMap` counts (`entryCount`, `pending`) require both module Edit and Edit membership on that person (a module-Edit member who is only a View member of the person gets no counts). `pending` is computed with a correlated subselect on `medical_ai_decisions` in the same scoped statement.
- `statuses` and the status route's `documents` are encoded as JSON objects even when empty.
- Status route uses `access:module,health,view`; `Cache-Control: no-store, private` comes from the existing NoStoreDynamicResponses middleware.
- Tests compare 403 responses by status and message only, since the debug body carries a stack trace with line numbers.

## Deviations from Plan
None of substance. `tests/Feature/Health/AiRunTest.php` was written in full in the first commit (including the page-prop tests, which pass only with the second commit's PersonPage change).

## Known Stubs
None.

## Threat Flags
None.

## Self-Check: PASSED
Files and commits 52d9cae, ee186e0 exist.
