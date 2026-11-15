---
phase: 08-ai-extraction-platform
plan: 09
subsystem: health
tags: [ai-02, provenance, verify, privacy]
requires: [08-01, 08-06]
provides: [health.{visits,medications,allergies,vaccinations}.verify routes, provenance prop on person page record rows]
affects: [08-13]
key-files:
  created:
    - routes/web/health-verify.php
    - app/Http/Controllers/Health/RecordVerifyController.php
    - tests/Support/EndpointMatrix/health-verify.php
    - tests/Support/MedicalPrivacy/visits_verify.php
    - tests/Feature/Health/RecordVerifyTest.php
  modified:
    - app/Medical/PersonPage.php
decisions:
  - "Toast key is health.ai.provenance.verified_toast (plan said health.ai.label.verified_toast; corrected per 08-01)."
  - "provenance.verification is manual|unverified|verified: Provenance::present reports manual rows as verified, so PersonPage maps source=manual to manual."
  - "Already verified or manual but visible-with-Edit records redirect back with the toast and no change; found via XQuery::find at Edit level after verify() returns false."
metrics:
  tasks: 2
  completed: 2026-11-14
---

# Phase 8 Plan 09: Verify routes and record provenance Summary

Members with Edit verify AI-extracted visits, medications, allergies and vaccinations through four scoped POST routes with an identical 403 for every other case, and every record row on the person page carries `provenance: {source, verification}`.

## Delivered
- `RecordVerifyController` (visit, medication, allergy, vaccination): `SubjectQuery::findVisible(Edit)`, then `XQuery::verify`; on false, `XQuery::find(..., Edit)` distinguishes a visible no-op (redirect) from hidden, unknown or malformed ids (403). No route model binding.
- Routes in `routes/web/health-verify.php`, `access:module,health,edit` and per-route throttle.
- `PersonPage` provenance helper applied to visit, allergy, medication and vaccination mappers.
- Matrix rows (parent 302, others 403, guest 302), leak-net area file `visits_verify.php` (AI rows on visible and canary AI rows on hidden subject), `RecordVerifyTest` (13 tests: verify plus audit row, no-op cases, 403 shapes for view-only/non-member/hidden/random/malformed, page props).

## Deviations
None beyond the key corrections above. The first full Pest run showed 3 failures in unrelated wishlist tests while sibling plans were editing the shared tree; a rerun was fully green (2116 passed, 24178 assertions). Peak memory was within phpunit's limit (ran with -d memory_limit=1G; phpunit.xml unchanged).

## Not run
Vitest and `npm run build` were not run: this plan changes no JS or Vue files (per instruction, only Pest and Pint were required).

## Self-Check: PASSED
