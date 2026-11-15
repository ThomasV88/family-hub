---
phase: 09-multi-agent-quota-and-bulk-regeneration
plan: 11
subsystem: ai
tags: [status, waiting, provenance, notice, review]
requires: ["09-03", "09-09"]
provides:
  - Waiting runs counted as the active run (start returns active, cancel ends them)
  - Status payload availableAt, failureCode, agent, model
  - Provider-set notice acknowledgment and ai.providers prop
  - Review payload provenance and newerWaiting; waiting status block
  - zz_ai_agents.php leak-net area
key-files:
  modified:
    - app/Medical/Queries/AiRunQuery.php
    - app/Medical/Queries/AiReviewQuery.php
    - app/Medical/PersonPage.php
    - app/Http/Controllers/Health/AiRunController.php
    - tests/Feature/Health/AiReviewPageTest.php
  created:
    - tests/Feature/Health/AiWaitingStatusTest.php
    - tests/Support/MedicalPrivacy/zz_ai_agents.php
decisions:
  - "Default review lookup falls back to the newest ready proposal when the newest non-superseded run is waiting; opening a waiting run (by id or by default with no ready one) renders the review props with a waiting status block (empty sections, availableAt) instead of the Phase 8 redirect."
  - "AiRunQuery gained available() and providers() so PersonPage no longer needs AgentHealth (replaced by the registry through AiRunQuery); AiRunQuery dropped its AgentHealth dependency."
  - "Notice ack is upserted only when the viewer has not acknowledged every enabled provider; providers stored as sorted enabled keys joined by comma."
  - "New cases for AiRunTest were placed in the new AiWaitingStatusTest (own helpers) so the file runs standalone; the existing AiRunTest passes unchanged."
metrics:
  completed: 2026-11-14
---

# Phase 9 Plan 11: Waiting, provenance and provider notice Summary

Status, start, cancel, person-page and review payloads now understand `waiting`, failure codes (`quota_timeout`, `notice_required`, `quota_exhausted`, `no_agent`) and the producing agent and model, and availability and the notice follow the enabled providers.

## Tasks
1. AiRunQuery, PersonPage, AiRunController: ACTIVE includes waiting; cancel of waiting clears available_at only (waiting_since kept); statusMap availableAt/failureCode/agent/model; start refuses `unavailable` only when no enabled agent is runnable and writes the first candidate (else first enabled) as attempt-1 agent; provider-set notice; per-user cap excludes bulk rows, global cap counts all; QUOTA_FAILURES unchanged. Tests in AiWaitingStatusTest. Commit "feat(09-11): waiting, failure code, provenance and provider notice in AI run status and review".
2. AiReviewQuery provenance, newerWaiting, waiting status block; zz_ai_agents.php area (per person: ready Copilot proposal with newer waiting run on the same document, plus a waiting-only document; canary in the hidden person's payload); AiReviewPageTest cases. Same commit.

## Verification
Containers (project fh0911), only this plan's tests: AiWaitingStatusTest, AiRunTest, AiReviewPageTest 62 passed; MedicalPrivacyTest and MedicalLeakSuiteTest 14 passed. MedicalPrivacyTest also passes under `memory_limit=300M` (peak memory below 300 MiB; exact peak not measured). Pint clean on touched files. Project removed with `down -v`.

## Deviations from Plan
None of substance. (AiRunTest was not edited; see decisions.)

## Deferred
Pint reports a style issue in app/Medical/Queries/AiJobQuery.php (not in this plan's files, left untouched).

## Known Stubs
None.

## Self-Check: PASSED
