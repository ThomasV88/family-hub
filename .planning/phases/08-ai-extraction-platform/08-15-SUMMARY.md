---
phase: 08-ai-extraction-platform
plan: 15
subsystem: ai
tags: [ai-review, accept, idempotent, provenance, leak-net]
requires: ["08-06", "08-08", "08-13"]
provides:
  - AiReviewQuery (review, accept, reject, undo)
  - Routes health.documents.ai.review, health.ai.entries.accept, health.ai.entries.reject, health.ai.entries.undo
  - Inertia props for Health/AiReview (page itself is 08-17)
affects: [08-17, 08-20, 08-22]
key-files:
  created:
    - app/Medical/Queries/AiReviewQuery.php
    - app/Http/Requests/Health/AiDecisionRequest.php
    - app/Http/Controllers/Health/AiReviewController.php
    - tests/Feature/Health/AiReviewPageTest.php
    - tests/Feature/Health/AiAcceptTest.php
  modified:
    - routes/web/health-ai.php
    - tests/Support/EndpointMatrix/health-ai.php
    - tests/Support/MedicalPrivacy/zz_ai.php
    - tests/Unit/Health/MedicalMutationScanTest.php
requirements-completed: [AI-02]
completed: 2026-11-14
---

# Phase 8 Plan 15: AI review backend Summary

The review page props and the accept, edit-accept, reject and undo endpoints: a proposal entry becomes a record only through a member's explicit decision, validated with the manual form's field rules, created through the Phase 7 query with source 'ai', exactly once.

## Commits
- 7f85930 feat(08-15): add AI review page and per-entry decision endpoints
- b688f06 test(08-15): atomic, idempotent accept, reject and undo
- d4afc12 fix(08-15): keep visible leak surfaces canary-free, allow the review query the store path

## Verification
- AiReviewPageTest (13), AiAcceptTest (22), SensitiveGateMatrixTest, MedicalPrivacyTest, MedicalLeakSuiteTest, MedicalWriteParityTest, tests/Unit/Health pass.
- Full Pest in the foreground (1G on the command line): 2243 passed, 0 failed, 216 s. Peak memory not measured; phpunit.xml unchanged.
- Pint clean on all plan files. Vitest and `npm run build` not run (PHP-only plan, host Node 18; the parallel frontend plans 08-16 and 08-17 own JS).
- Compose project fh0815 removed with `down -v`.

## Decisions (PROVISIONAL, for 08-22 review)
- **PROVISIONAL: AI allergy with `unknown` or null severity.** The manual rule `in:mild,moderate,severe` is untouched. Accept validates the entry with `AllergyRequest::fieldRules()` as is, so an unknown or missing severity returns 422 with a `severity` error (message key `health.allergy.err_severity`), no record and no decision. The member must pick a real severity (accept with `values.severity`), which is then recorded as an edited accept. No silent accept and no `unknown` severity rows are created by the app.
- The 422 is a plain Laravel ValidationException thrown inside the transaction, so nothing is left behind.
- Edited accept: `values` keys are filtered to the record type's rule keys, an empty string means a cleared field, `edited` is true when any supplied value differs from the proposal. A repeat accept (even with different values) returns the existing decision unchanged.
- Idempotency: extraction row FOR UPDATE through the Edit scope, existing-decision short circuit, decision PK; a 23505 inside a savepoint rolls the whole transaction back (record included) and the current state is returned.
- Every denial from the decision routes (hidden, view-only, unknown, malformed id or entry, non-ready run) uses the one message of a failed authorize(), so the 403 body is identical across all causes.
- Undo of a superseded proposal answers 409 like accept and reject. Undo of an accepted entry returns accepted and writes no audit row.
- Review without `?extraction` opens the newest non-superseded run; one that is not ready (queued, running, failed, cancelled) or none at all redirects to the person page panel.
- `pane` passthrough is limited to `document` or `entries`, else null.

## Deviations from Plan
**1. [Rule 3 - Blocking] `consentOn` read through the viewer scope, not `AiConsent::allows`.** `AiConsent::allows` reads `medical_subjects` without the membership predicate, which MedicalPrivacyTest flags. The page reads `ai_consent` inside the same Edit scope instead (same value, no cache).

**2. [Rule 3 - Blocking] Decision reads and deletes scoped through the extraction.** `medical_ai_decisions` has no subject_id (08-04 note), so each statement carries `extraction_id in (extractions in the viewer's Edit scope)`.

**3. [Rule 3 - Blocking] zz_ai.php payload shape and canary.** The 08-13 seed used a stale `entries` list; it now uses the real `visits/medications/...` shape. The harness requires visible surfaces to be canary-free, so only the hidden person's proposal carries the canary (the plan text said the visible review page would show it). The documents area's file also gets a ready proposal so the review route renders a page in the replay.

**4. [Rule 3 - Blocking] `tests/Unit/Health/MedicalMutationScanTest.php` (not in files_modified):** `Medical/Queries/AiReviewQuery.php` added to `MD_STORE_USERS` and to the `absolutePath` allowlist (needed for the PDF page count, same pattern as 08-08/08-12).

**5.** Tests assert the Inertia component with `component('Health/AiReview', false)` because the Vue page is built by 08-17 in parallel; 08-17 or 08-22 may tighten that.

An accidental Pint run over `app/Http` and `tests/Support` touched two unrelated files (ThrottleAuthRoutes.php, FakePasskeys.php); both were reverted before the first commit.

## Known Stubs
None.

## Threat Flags
None.

## Self-Check: PASSED
Files exist and commits 7f85930, b688f06, d4afc12 are in the log.
