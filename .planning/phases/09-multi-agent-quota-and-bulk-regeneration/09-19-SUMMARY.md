---
phase: 09-multi-agent-quota-and-bulk-regeneration
plan: 19
subsystem: testing
tags: [e2e, privacy, canary, cache, service-worker, a11y, validation, phase-gate]
requires: ["09-02", "09-08", "09-14", "09-15", "09-17", "09-18"]
provides:
  - End-to-end proof of fallback, waiting/resume, the 24 h cap, provider acknowledgment and a bulk run, against fake CLIs
  - Canary and name scans across every Phase 9 admin payload, table and log
  - no-store and service-worker exclusion for the agents page and bulk poll
  - Bulk finish guard that sees accepted decisions on any extraction of the document
  - Signed-off 09-VALIDATION.md (all but the live plan 09-20)
key-files:
  created: [tests/Feature/Ai/AiPhase9EndToEndTest.php]
  modified:
    - app/Medical/Queries/AiJobQuery.php
    - tests/Feature/Ai/FinishReadyAtomicTest.php
    - tests/Feature/Ai/AiPrivacyDifferentialTest.php
    - tests/Feature/Health/AdminMedicalPrivacyTest.php
    - tests/Feature/Health/MedicalCacheHeadersTest.php
    - tests/js/health/a11y-health.test.js
    - tests/js/health/sw-health.test.js
    - .planning/phases/09-multi-agent-quota-and-bulk-regeneration/09-VALIDATION.md
    - .planning/phases/09-multi-agent-quota-and-bulk-regeneration/DEFERRED.md
requirements-completed: []
completed: 2026-11-14
---

# Phase 9 Plan 19: Phase gate Summary

Phase 9 is proven end to end against the fake CLIs and every automated gate is green, run alone in the foreground: full Pest 2592 passed, full Vitest 4752 passed, build, check-compose, deploy guards, image checks and Pint on all Phase 9 PHP files. Requirements stay unticked until 09-20.

## Commits

- f2180b1 fix(09-19): bulk finish guard sees accepted decisions on any extraction of the document
- af859bb test(09-19): end-to-end fallback, waiting and bulk flows with privacy and canary proofs
- 67775a2 test(09-19): no-store for agents and bulk, service worker exclusion, a11y of Phase 9 health states
- docs(09-19): summary (this commit, with 09-VALIDATION.md and DEFERRED.md)

## What was built

- **AiPhase9EndToEndTest (6 tests):** HTTP Run AI with a Codex quota fake and a Copilot golden gives a ready run by `copilot`, with two attempt rows in Settings > AI calls and the quota tag on the first; both agents limited gives `waiting` with `availableAt`, a time jump, `ai:resume-waiting` and a Codex answer gives `ready` with provenance codex on the review page; a run waiting past the cap and `ai:reap-stuck` gives `failed quota_timeout`; a Codex-only acknowledgment never invokes Copilot after it is enabled while waiting; a bulk run goes dry run, start, three ticks (before the off-peak hour, 01:05, 01:20, 01:35) to completion with items `read, read`, the document holding accepted data (on a superseded row only) never enqueued, the record tables and every `medical_ai_decisions` row and the accepted document's extraction rows byte-equal before and after, call-log rows `bulk: true, person: null` and the person filter returning no rows; and the new guard case (decision appears on a superseded row after enqueue gives `cancelled(decided)`, nothing written).
- **AiPrivacyDifferentialTest:** the hidden-vs-never-existed differential already covers waiting and Copilot-produced runs because `zz_ai_agents.php` is part of the hidden seed; the test now asserts that state exists. New canary test runs a whole bulk flow and scans the dry-run and start JSON, agents page, bulk poll, AI calls, audit page, audit meta of the new events, `ai_bulk_*`, `ai_agent_settings`, `ai_agent_status`, `ai_calls`, `jobs`, `failed_jobs` and the log files for canaries, `jpeg-bytes` and person and member names.
- **AdminMedicalPrivacyTest:** an admin who is a member of nobody sees no canary, person name or id on the agents page, bulk poll and dry run, and the dry run counted real documents.
- **MedicalCacheHeadersTest:** the agents page, bulk poll and every agent/bulk JSON response (including refusals, 404s and validation errors) are no-store and private; non-admin refusals are no-store; the extended status poll stays no-store, private and without ETag with waiting runs present.
- **sw-health.test.js:** the precache check rejects the agents page, bulk poll and bulk controls; the source and built worker mention none.
- **a11y-health.test.js:** 16 new states in light/dark, 320 px and +40% text for en/sk/pl: waiting with time and soon, quota timeout, quota exhausted, provenance (Copilot with model, Codex default), the three provider lines, the provider-aware notice (via the run control and the dialog, multi and github), review with provenance and newer waiting run, review waiting block, AI calls with attempts, quota tag and bulk row.

## Phase gate evidence (run alone, foreground)

- Pest: 2592 passed, 28078 assertions, 0 failed, 248.4 s. Nothing failed, so no re-run was needed and there is no flaky or deterministic failure to report.
- Peak memory (shutdown hook `memory_get_peak_usage`): 358,088,704 bytes allocated (341.5 MiB), 289.8 MiB used. Below the 410 MB threshold, so `phpunit.xml` stays at 512M (not changed).
- Build green with `SW-PRECACHE OK`; Vitest after it: 135 files, 4752 passed, 136.5 s.
- `check-compose.sh`: CHECK-COMPOSE OK. `test-deploy-guards.sh`: 25 passed, GUARDS OK.
- Image checks against the existing `family-hub-ai:dev` and `family-hub-app:dev` (Dockerfile and `docker/ai` unchanged since 09-02): AI image has `copilot` 1.0.112, `.extraction-complete`, `LICENSE.md`, `/copilot`, `codex`, no `node`; app image has none of copilot, `/opt/copilot`, codex, and has `pdftoppm`.
- Pint: 91 files touched by Phase 9 commits (plus the new test) pass.
- Compose project `fh0919` removed with `down -v`. No extra connections were left open (the canary test and the differential afterEach purge `btlock`).

## Deviations from Plan and extra items

1. **[Rule 1 - Bug] Accepted-data guard gap (extra item 1).** `AiJobQuery::hasAcceptedDecision` joined only `ready` extractions; eligibility and the 09-14 tick recheck use any extraction. Now it matches any extraction of the document. The existing 09-10 tests stayed green unchanged (their decision sits on a ready row). New test: a decision on a superseded row only, with a newer ready row without decisions, gives `cancelled(decided)`, payload null, the ready row untouched. File: `app/Medical/Queries/AiJobQuery.php`, commit f2180b1.
2. **Pint (extra item 2).** `AiJobQuery.php` is clean now (the earlier finding no longer reproduces). `config/security.php` was last touched in Phase 6 and by no Phase 9 commit, so it is not Phase 9 work and was left alone, as instructed for unrelated old files. Pint fixed style in the two new/extended test files only.
3. **Call-log expectation narrowed.** The plan text says a bulk person's name appears nowhere in the AI calls props. The page's person filter dropdown (`options.people`, Phase 8 admin exception) lists every person, with or without bulk runs, exactly as Settings > Members does. The tests therefore assert the `calls` rows and filter results (no name, no canary, no title), not the dropdown. This reveals nothing about which people bulk processed. Worth confirming under owner decision A10 if the dropdown should also go.
4. **No concurrent-lock test (extra item 3).** Recorded as a known limit in 09-VALIDATION.md; no flaky test invented.
5. **phpunit.xml untouched (extra item 4)**, peak below threshold.
6. **VALIDATION signed off (extra item 5):** status approved, `nyquist_compliant: true`, `wave_0_complete: true`, rows 09-01..09-19 green, 09-20 rows pending, evidence table filled.
7. **DEFERRED.md (extra item 6):** the riskiest SK/PL strings from the 09-01 summary and the Polish plural agreement flag are recorded as two native-review tasks for 09-20.
8. The a11y states 'AI run waiting for quota ...' use `Date.now()` offsets for the time display; axe results do not depend on the exact time.

## Known Stubs

None.

## Threat Flags

None. T-09-74 (canary and name scans), T-09-75 (differential with waiting and Copilot runs), T-09-76 (record and decision invariants across the bulk flow), T-09-77 (no-store, service worker exclusion) and T-09-78 (full suites alone, connection purge, peak memory recorded) are covered.

## Self-Check: PASSED

Files exist (`tests/Feature/Ai/AiPhase9EndToEndTest.php`, the two planning files) and commits f2180b1, af859bb, 67775a2 exist.
