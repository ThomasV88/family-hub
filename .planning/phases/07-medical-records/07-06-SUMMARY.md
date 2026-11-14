---
phase: 07-medical-records
plan: 06
subsystem: health
tags: [medical, privacy, leak-suite, arch, static-scan]
requires: ["07-03"]
provides:
  - "tests/Support/MedicalPrivacy harness with one area file per slice (tests/Support/MedicalPrivacy/*.php)"
  - "differential, canary, query-log and write-parity leak tests over run-time enumerated health.* routes"
  - "arch rules, medical table scan, mutation scan and file-store scan with planted-mutant self-tests"
affects: [07-05, 07-07, 07-10, 07-12, 07-17, 07-19]
key-files:
  created:
    - tests/Support/MedicalPrivacy.php
    - tests/Support/MedicalPrivacy/subjects.php
    - tests/Feature/Health/VisibilityTest.php
    - tests/Feature/Health/MedicalPrivacyTest.php
    - tests/Feature/Health/MedicalWriteParityTest.php
    - tests/Unit/Arch/MedicalPrivacyArchTest.php
    - tests/Unit/Health/MedicalTableScanTest.php
    - tests/Unit/Health/MedicalMutationScanTest.php
decisions:
  - "Hidden subjects B and C are created by the subjects area hidden seed, not the scenario, so the differential has a real before and after"
  - "The membership regex refuses to borrow user_id from the own-profile branch after a union"
metrics:
  tasks: 2
  completed: 2026-11-14
---

# Phase 7 Plan 06: Medical leak net Summary

A failing test, not a reviewer, now catches the first unscoped medical query, write, file-store call or layering breach a later plan adds. The harness enumerates every `health.*` GET route (plus `home`, `settings.index`, `settings.members`) at run time, throws on any route parameter without an area provider, and compares status, Content-Type, Content-Disposition, ETag, Cache-Control and body before and after hidden people exist.

## Commits

- 6080947 test(07-06): visibility, arch, table and mutation scans
- adce010 test(07-06): MedicalPrivacy harness, differential, canary, query-log and write parity

## Verification

- Full Pest (php -d memory_limit=1G, with the peak probe): 1741 passed, 19243 assertions, 146 s.
- Vitest: 108 files, 2523 tests passed. `npm run build`: SW-PRECACHE OK. Pint clean on own files.
- With a temporary probe area (uuid `visit` params) the write parity ran against 07-05's real `health.visits.*` routes: 9 passed, incl. mismatched pair and zero medical statements for the malformed id. The probe file was deleted.
- Mutation probes (reverted): a `\App\Models\MedicalSubject::query()->count()` added to HomeController fails the arch test; removing `->where('user_id', ...)` from the members branch of `Visibility::subjectIds` fails the differential, the query-log test and the regex self-test.
- Compose project fh0706 removed with `down -v`.

## Suite memory

Suite memory: 245.0 MiB (256901120 bytes, `memory_get_peak_usage(true)`, allocated-chunk measure) peak of 256M effective limit (phpunit.xml ini overrides -d). This exceeds the 200 MiB threshold and leaves about 11 MiB of headroom, so 07-19 should decide whether to raise the limit or trim fixtures. The same full run passed. Phpunit.xml was not changed.

## Deviations from Plan

1. **[Rule 3] Arch allowlists include the Health form requests.** `App\Http\Requests\Health` was added to both allowlists and `App\Http\Requests\Settings\HealthPeople` to the queries one: 07-05's `VisitRequest` resolves ids through `SubjectQuery` and `MedicalSubject` (the layer mirrors `App\Http\Requests\Finance`), and the plan list omitted it.
2. **Table scan also allows `Models/Medical*.php` and `Audit/AuditEvent.php`** (models name their own table; AuditEvent holds slugs like `medical_subject_created`). Both documented in the test.
3. **People-store insert exemption.** `health.people.store` inserts a new `medical_subjects` row and the creator's `medical_subject_members` row by design, with no earlier predicated statement; the write-parity test exempts exactly those two INSERT patterns for that route only.
4. **Regex hardening.** The MEMBERSHIP regex first matched the own-branch `user_id = ?` across a `union` when the members branch lost its filter; a negative lookahead for `union` plus a self-test now pins this.
5. **Parity vacuity.** With no record-id routes at plan time the hidden-id loop asserts `>= 0` checked routes; it is the net for 07-05 and later routes and ran for visits in the probe above.
6. **Mid-wave failure by design.** Until 07-05's `tests/Support/MedicalPrivacy/visits.php` landed, `MedicalWriteParityTest` threw "No medical privacy provider for parameter {visit}" (the loud-failure behaviour). The final full run passed with it present.

## Known Stubs

None.

## Self-Check: PASSED

All eight created files exist and both commits are in the log.
