---
phase: 07-medical-records
fixed_at: 2026-11-14T00:00:00Z
review_path: .planning/phases/07-medical-records/07-REVIEW.md
iteration: 1
findings_in_scope: 7
fixed: 7
skipped: 0
status: all_fixed
---

# Phase 7: Code Review Fix Report

**Source review:** .planning/phases/07-medical-records/07-REVIEW.md
**Scope:** WR-01, WR-02, WR-03, WR-04, WR-08, WR-09, WR-10, plus cheap Info items IN-03, IN-04, IN-05.
Work was done directly on `main` in the main working tree (the tree was clean), one commit per finding.

## Fixed Issues

### WR-03: partial PATCH wiped stored fields
Visit and person updates now write only the fields the client sent (`array_key_exists`); a field sent as null still clears it. Tests: VisitTest, PersonManageTest. Commit ee419e0. Status: fixed.

### WR-01: admin self-link without the self-added audit event
`link()` records `MedicalAdminSelfAdded` when the actor links themselves, and refuses (same `link_failed` error) an account without the Health module. Tests: HealthPeopleAdminTest; the existing link test and the endpoint-matrix fixture now give the linked member the Health module. Commits 085f312, plus the matrix fixture commit. Status: fixed.

### WR-02: reads audited without subject, unthrottled previews
`HealthRead`/`HealthWrite` allow `subject` and `document` meta, filled from UUID-shaped route params. Thumbnail previews no longer write a row (the person page and file download do). `preview` throttled 120/min, `file` 30/min. Deviation from the review: no new `MedicalOpened` event, the existing read row carries the ids. Test: DocumentServeTest. Commit d823ad0. Status: fixed.

### WR-04: Safari revoked the blob URL too early
New shared `resources/js/Security/saveBlob.js` (revoke after 30 s) used by DocumentPanel and Export. It is a new module, not part of `securityTransfer.js`, because the Vitest suites mock that module. Tests updated. Commit 528000f. Status: fixed.

### WR-08: orphan sweep without a circuit breaker
`medical:prune-orphans` aborts (exit 1, nothing deleted) when candidates exceed 20 and 25 percent of the inventory (config `medical.orphan_max_delete`, `orphan_max_ratio`), or when candidate files exist while `medical_documents` is empty, or candidate directories exist while `medical_subjects` is empty. Added `--dry-run` and `--force`, and logged counts. Tests: DocumentManageTest. Commit d321ccf. Status: fixed.

### WR-09: exception text could write medical content to the log
`ScrubbedQueryReport` (wired in `bootstrap/app.php`) logs a QueryException on a `medical_*` table without bindings or the Postgres DETAIL line and skips the default report. `.env.example` notes the production `LOG_LEVEL=warning`, `APP_DEBUG=false` setting. The server `.env` is outside the repo and still needs that change. Test: MedicalLogScrubTest (verified to fail when the hook is disabled). Commit 2ccc417. Status: fixed.

### WR-10: TOCTOU on the document cap and FK 500 on update
`DocumentQuery::create` takes a scoped row lock on the subject and re-checks the cap inside one transaction; the controller deletes the already written files on `DocumentRejected`. `update` runs lookup and UPDATE in one transaction and maps SQLSTATE 23503 to the `visit_id` error. Tests: DocumentUploadTest. Commit d9ba5fd. Status: fixed. The cap race itself is covered by the in-transaction re-check test, not a true concurrent test.

### IN-03, IN-04, IN-05
IN-03: afterCommit directory removal failure is reported content-free instead of raising (9b022eb). IN-04: a passive header no longer skips the idle touch on non-safe methods; the stale-refusal behaviour is unchanged, which keeps the existing matrix tests intact (527fb76). IN-05: ETag is a keyed HMAC prefix (9ed8cfb).

## Skipped / not in scope

- IN-01 (HEIC/PDF metadata wording) and IN-02 (backup retention wording): need UI copy in three locales, left for the native review.
- WR-05, WR-06, WR-07, WR-11, WR-12: owner decisions, recorded in DEFERRED.md under "Review findings left for owner decision".

## Verification

Full Pest: 1997 passed, then the single failure (EndpointMatrix link fixture) was fixed and the file re-run green (155 passed). Full Vitest: 3388 passed. `npm run build` OK. Pint run on changed PHP files.

_Fixer: Claude (gsd-code-fixer)_
