---
phase: 07-medical-records
plan: 19
subsystem: health
tags: [medical, privacy, leak-suite, mutation-sanity, axe, validation, staging-audit]
requires: ["07-04", "07-07", "07-15", "07-18"]
provides:
  - "Leak-net completeness and mutation sanity for the medical surfaces (read, aggregate, weak-scope, file, export and write mutants)"
  - "Admin privacy, no-store, audit/log/outbox canary and staging-audit tests for Phase 7"
  - "Phase-wide Health axe sweep (sk/pl/en, light/dark, 320px, +40% text) and a signed-off 07-VALIDATION.md"
affects: [07-20]
key-files:
  created:
    - tests/Feature/Health/MedicalLeakSuiteTest.php
    - tests/Feature/Health/AdminMedicalPrivacyTest.php
    - tests/Feature/Health/MedicalCacheHeadersTest.php
    - tests/Feature/Health/MedicalAuditContentTest.php
    - tests/Feature/Health/StagingAuditMedicalTest.php
    - tests/js/health/a11y-health.test.js
    - tests/js/health/sw-health.test.js
    - tests/js/health/history-health.test.js
  modified:
    - tests/Feature/Health/MedicalWriteParityTest.php
    - app/Console/Commands/StagingAudit.php
    - scripts/check-sw-precache.mjs
    - resources/js/Components/ui/UiDetailPanel.vue
    - .planning/phases/07-medical-records/07-VALIDATION.md
decisions:
  - "phpunit.xml left at 512M: the measured peak of 275 MiB is 54% of the effective limit (see Suite memory)"
  - "No lang keys added: no 07-xx SUMMARY recorded keys that are still missing"
  - "Write-route minimums are 19 id routes and 20 scoped-mutation routes, the real counts, not the plan's 23 and 24"
metrics:
  tasks: 3
  completed: 2026-11-14
---

# Phase 7 Plan 19: Leak-suite completeness, axe sweep, validation sign-off Summary

The medical leak net is now shown to be both complete and sensitive: a test registers planted mutants named `health.zz_mutant_*` (an unscoped `count(*)` over visits, a grouped vaccination count, a weak-scope members subselect, an unscoped document stream, an all-subjects export, and unscoped UPDATE, INSERT ... SELECT and DELETE writes) and each is caught by the differential, canary, query-log or scoped-mutation check while every real surface stays identical and canary-free.

## Commits

- 53762f1 test(07-19): medical leak-suite completeness, mutation sanity and admin privacy
- 93c7d53 test(07-19): cache headers, audit canary, staging audit, service worker and history checks
- 05cceff test(07-19): phase-wide Health axe sweep, 320px and +40% text runs
- de64316 docs(07-19): sign off 07-VALIDATION map

## What was built

- **MedicalLeakSuiteTest**: route inventory (all 8 GET surfaces, 19 id-carrying write routes for parity, 20 for the scoped-mutation check, `health.people.store` the only id-free one), unknown-parameter throw, five read mutants plus three write mutants, and a check that no `zz_` route exists in `app` or `routes`. Every raw mutant statement carries a `zz_mutant` SQL comment, so the test asserts that the only unscoped statements in the whole capture are the mutants'.
- **AdminMedicalPrivacyTest**: an admin with a Health Edit grant and no membership sees an empty state, an identical 403 for a known person id, names and member levels only on Settings > Members (key order of the prop asserted, no contacts), and no subject name or id on any other surface. Adding themselves is audited as `medical_admin_self_added` and the person then appears. An admin without a Health grant gets 403. A view-only member gets 403 on all 20 write routes and `canEdit`/`canCreate` false.
- **MedicalCacheHeadersTest**: every health surface and panel variant is `no-store, private`; 403 for hidden, unknown and malformed ids on every id-carrying GET is no-store; authorised image previews are `private, no-cache` with an ETag and the 304 keeps it, the original file is no-store; stale-session refusals (302 to confirm and 423 JSON) are no-store on every health surface and write.
- **MedicalAuditContentTest**: one flow drives person create/update, visits, allergies, medications, vaccinations (including mark given), uploads with canary titles and file names, rename, delete, consent on/off, grant, link, admin self-access, print/json/md exports, the emergency card, reminder enqueue and send, a validation refusal with canary input, and delete of every record. No audit_logs column, MessageLogged record or notification_outbox payload, dedupe_key or last_error contains any canary, and the six expected medical audit events (and at least four export rows) are asserted present.
- **StagingAudit**: `medical_subjects` fails when created by a null or real user or linked to a real user, `medical_subject_members` fails for real users (only that one file names medical tables, so the 07-06 one-command table scan stays green).
- **check-sw-precache.mjs**: explicit `FORBIDDEN_URL` rule for health and finance URLs on top of the allowlist; `sw-health.test.js` self-tests it. **history-health.test.js** asserts no `remember`/`rememberKey`/`useRemember` in any Health page, component or the print layout (the 07-04 clearHistory assertions are referenced, not duplicated).
- **a11y-health.test.js**: 57 named states (person page folders, timeline, filters, empty, view-only, header menu, delete confirms, every panel create/edit/read-only, mark given, attach dialog, document preview loading/failed/pdf/heic, uploader, emergency card, export form states, print export, AI consent states, settings access card and panel, Home card). 522 tests: sk/pl/en x light/dark (print views light only), a 320px pass, a +40% pseudo-long pass for sk and pl, and a source scan of the Health styles. Each state asserts that its target element exists.

## Suite memory

- 07-06 peak: 245.0 MiB (256901120 bytes, `memory_get_peak_usage(true)`).
- 07-19 peak: 275.0 MiB (288358400 bytes, same probe via `-d auto_prepend_file`, full Pest run of 1986 tests).
- Effective limit: 512M. phpunit.xml's `<ini name="memory_limit">` is applied by PHPUnit at startup and overrides the `php -d memory_limit=1G` flag, so the 1G flag does not change the budget. The plan was written for a 256M limit; a later change had already raised phpunit.xml to 512M.
- Decision: phpunit.xml left unchanged. The peak is 54% of the 512M limit with 237 MiB of headroom, so the plan's "raise to 384M above 200 MiB" rule (written for 256M) does not apply, and lowering the limit would only reduce headroom. The suite grew about 30 MiB over 07-06 across 13 plans; at that rate the budget lasts many more phases.

## Verification

- Pest full (php -d memory_limit=1G, with probe): 1986 passed, 23360 assertions, 158 s.
- Vitest full: 122 files, 3388 tests passed. `npm run build`: SW-PRECACHE OK.
- `scripts/check-compose.sh` CHECK-COMPOSE OK, `scripts/check-nginx-template.sh` CHECK-NGINX OK, `scripts/test-backup-local.sh` TEST-BACKUP OK.
- Pint (`--test`) clean on app/Medical, app/Http/Controllers/Health, StagingAudit and tests/Feature/Health.
- Compose project fh0719 removed with `down -v`. The other `fh0*` volumes and containers on the host belong to earlier plans and were left alone.
- MED requirements were not marked complete (07-20 is still pending). STATE.md, ROADMAP.md, REQUIREMENTS.md and `.planning/config.json` were not touched.

## Deviations from Plan

**1. [Rule 1 - Bug] Shared detail panel rendered a second banner landmark in sheet and overlay modes.**
- **Found during:** Task 3, the 320px and pseudo-long runs failed for every panel state with `landmark-no-duplicate-banner` and `landmark-unique` on `.topbar`.
- **Issue:** `UiDetailPanel.vue` teleports its modal (sheet/overlay) variant to `body` using `<header>` and `<footer>`, which axe counts as banner and contentinfo landmarks next to the shell top bar. The per-slice sweeps only ran at 1280px, where the panel is docked inside an `aside`, so this was never seen.
- **Fix:** the teleported modal branch uses `div` for the header and footer (same classes, no style change). The docked branch keeps `header`/`footer` inside the `aside`. Full Vitest (3388) stays green.
- **Files modified:** resources/js/Components/ui/UiDetailPanel.vue
- **Commit:** 05cceff
- This is a shared Phase 1 component outside the plan's file list. It affects every module's panels at phone width; it was needed to meet the plan's own 320px acceptance criterion.

**2. Plan text vs reality, no action needed beyond the test numbers:**
- Write-route minimums: the plan said 23 id routes and 24 scoped-mutation routes; the router has 19 and 20 (listed route names match the plan, only the count was off). The tests assert the real minimums plus the full named inventory.
- Memory limit: plan assumed 256M and a 384M raise; phpunit.xml was already 512M (see Suite memory).
- 07-VALIDATION row 07-15-02 named `tests/Feature/Wishlist/WishOccasionOutboxTest.php`, which never existed; the map points to `OccasionReminderTest.php`.

**3. Mutant file ordering:** the file mutant orders by `title desc` rather than recency, because uuid7 ids created within one millisecond sort unreliably and made the first version flaky.

## Provisional decisions

- 07-VALIDATION.md is signed off with every 07-01..07-19 task marked green on the strength of the full local run of 2026-11-14; the 07-20 rows are pending by design.
- The 320px "no horizontal overflow" check is a jsdom proxy (no inline fixed widths, source scan of Health styles for fixed widths above 320px outside container/media queries, no ellipsis). jsdom computes no layout, so real-device 320px checking stays in 07-20.
- No lang keys were added: 07-14 recorded two missing keys and fixed them itself; no other SUMMARY lists any still missing. The 07-01 parity tests are unchanged and green.

## Known Stubs

None.

## Threat Flags

None. The new StagingAudit rules and tests add no network endpoints or trust-boundary changes.

## Self-Check: PASSED

All created files exist and the four commits (53762f1, 93c7d53, 05cceff, de64316) are in the log.
