---
phase: 07
slug: medical-records
status: approved
nyquist_compliant: true
wave_0_complete: true
created: 2026-11-14
approved: 2026-11-14
---

# Phase 07 — Validation Strategy

> Per-phase validation contract for feedback sampling during execution. Signed off by plan 07-19 after the full local run (Pest 1986 passed, Vitest 3388 passed, build SW-PRECACHE OK, ops scripts OK). Plan 07-20 (live staging and production, real-device checks, owner decisions) stays pending.

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | Pest 4 (PHP, in app container) + Vitest (frontend) |
| **Config file** | phpunit.xml, vitest.config.js |
| **Quick run command** | `docker compose run --rm app ./vendor/bin/pest --compact` |
| **Full suite command** | `docker compose run --rm app php -d memory_limit=1G ./vendor/bin/pest && npx vitest run` |
| **Estimated runtime** | Pest ~160 s, Vitest ~60 s |

Note: phpunit.xml sets its own `memory_limit` ini (512M), which PHPUnit applies at startup and which overrides the `-d memory_limit=1G` flag; the effective test budget is the phpunit.xml value.

---

## Sampling Rate

- **After every task commit:** Run only the task's own tests (the command in the map below)
- **After every plan wave:** Full Pest and Vitest once per plan in its last task
- **Before `/gsd-verify-work`:** Full suite must be green
- **Max feedback latency:** about 10 s per task command, about 3.5 min for the full suite

---

## Per-Task Verification Map

| Task ID | Plan | Wave | Requirement | Threat Ref | Secure Behavior | Test Type | Automated Command | File Exists | Status |
|---------|------|------|-------------|------------|-----------------|-----------|-------------------|-------------|--------|
| 07-01-01 | 01 | 1 | MED-01,MED-03,MED-05,MED-10,MED-11 | T-07-01..03 | Health copy exists in all three locales with matching keys and plural forms; audit events carry ids only (All Health copy in EN, SK and PL) | script | `node -e "for (const l of ['en','sk','pl']) { const j=require('./lang/'+l+'.json'); const k=Object.keys(j).filter(x=>x.startsWith('health.')); console.log(l,k.length); if(!j['health.specialty.gastroenterology']) process.exit(1) }"` | ✅ | ✅ green |
| 07-01-02 | 01 | 1 | MED-01,MED-03,MED-05,MED-10,MED-11 | T-07-01..03 | Health copy exists in all three locales with matching keys and plural forms; audit events carry ids only (Medical audit events, parity and plural tests, full verification) | pest+vitest | `npx vitest run tests/js/i18n-phase7.test.js && docker compose -p fh0701 run --rm app ./vendor/bin/pest tests/Unit/MedicalLangTest.php --compact` | ✅ | ✅ green |
| 07-02-01 | 02 | 1 | MED-06 | T-07-04..09 | Medical files live on a dedicated volume outside the web root and are in backups; restore drill proven (Medical disk, volume, image ownership, PHP and nginx limits) | script | `bash scripts/check-compose.sh && bash scripts/check-nginx-template.sh && docker compose -p fh0702 run --rm app ./vendor/bin/pest tests/Feature/Health/MedicalDiskTest.php --compact` | ✅ | ✅ green |
| 07-02-02 | 02 | 1 | MED-06 | T-07-04..09 | Medical files live on a dedicated volume outside the web root and are in backups; restore drill proven (Medical snapshot in backups, freshness, restore drill, local proof and runbook) | script | `bash -n scripts/backup.sh && bash -n scripts/backup-freshness.sh && bash -n scripts/restore-drill.sh && bash scripts/test-backup-local.sh` | ✅ | ✅ green |
| 07-02-03 | 02 | 1 | MED-06 | T-07-04..09 | Medical files live on a dedicated volume outside the web root and are in backups; restore drill proven (Full verification) | pest+vitest | `docker compose -p fh0702 run --rm app php -d memory_limit=1G ./vendor/bin/pest --compact && npx vitest run` | ✅ | ✅ green |
| 07-03-01 | 03 | 2 | MED-01,MED-02 | T-07-10..17 | A hidden, unknown or malformed person id answers one identical 403; scope is the membership subquery (Subject schema, scope and SubjectQuery with privacy tests) | pest | `docker compose -p fh0703 run --rm app ./vendor/bin/pest tests/Feature/Health/SubjectTest.php --compact` | ✅ | ✅ green |
| 07-03-02 | 03 | 2 | MED-01,MED-02 | T-07-10..17 | A hidden, unknown or malformed person id answers one identical 403; scope is the membership subquery (Health routes, controller, create request, identical 403 and matrix rows) | pest | `docker compose -p fh0703 run --rm app ./vendor/bin/pest tests/Feature/Health/SubjectTest.php tests/Feature/Security/SensitiveGateMatrixTest.php --compact` | ✅ | ✅ green |
| 07-03-03 | 03 | 2 | MED-01,MED-02 | T-07-10..17 | A hidden, unknown or malformed person id answers one identical 403; scope is the membership subquery (Person page, switcher, header, create panel; full verification) | vitest | `npx vitest run tests/js/health/Person.test.js` | ✅ | ✅ green |
| 07-04-01 | 04 | 2 | MED-02 | T-07-18..21 | Health is gated server-side; history is cleared on idle lock; Home card carries no data (Nav link, static Home card and the Health 403 back link) | vitest | `npx vitest run tests/js/shell tests/js/Home.test.js tests/js/errors/Forbidden.test.js` | ✅ | ✅ green |
| 07-04-02 | 04 | 2 | MED-02 | T-07-18..21 | Health is gated server-side; history is cleared on idle lock; Home card carries no data (Medical audit rows, history clearing on idle lock; full verification) | pest+vitest | `npx vitest run tests/js/settings/AuditHealth.test.js && docker compose -p fh0704 run --rm app ./vendor/bin/pest tests/Feature/Health/HealthHistoryTest.php tests/Feature/Security/ReauthTest.php --compact` | ✅ | ✅ green |
| 07-05-01 | 05 | 3 | MED-03 | T-07-22..26 | Visits are scoped through the visible subject; folders and timeline show only visible data (Visit schema, taxonomy, scoped query, routes and controller) | pest | `docker compose -p fh0705 run --rm app ./vendor/bin/pest tests/Feature/Health/VisitTest.php tests/Feature/Security/SensitiveGateMatrixTest.php --compact` | ✅ | ✅ green |
| 07-05-02 | 05 | 3 | MED-03 | T-07-22..26 | Visits are scoped through the visible subject; folders and timeline show only visible data (Folders, timeline and visit panel on the person page) | pest+vitest | `npx vitest run tests/js/health/Visits.test.js && docker compose -p fh0705 run --rm app ./vendor/bin/pest tests/Feature/Health --compact` | ✅ | ✅ green |
| 07-06-01 | 06 | 3 | MED-02 | T-07-27..31 | Leak net enumerates every health route at run time; an unscoped query, write or file call fails a test (Visibility tests, arch rule and static scans) | pest | `docker compose -p fh0706 run --rm app ./vendor/bin/pest tests/Feature/Health/VisibilityTest.php tests/Unit/Arch/MedicalPrivacyArchTest.php tests/Unit/Health --compact` | ✅ | ✅ green |
| 07-06-02 | 06 | 3 | MED-02 | T-07-27..31 | Leak net enumerates every health route at run time; an unscoped query, write or file call fails a test (MedicalPrivacy harness, subjects area, differential/canary/query-log and write parity; full verification) | pest | `docker compose -p fh0706 run --rm app php -d memory_limit=1G ./vendor/bin/pest tests/Feature/Health/MedicalPrivacyTest.php tests/Feature/Health/MedicalWriteParityTest.php --compact` | ✅ | ✅ green |
| 07-07-01 | 07 | 3 | MED-01,MED-02 | T-07-32..37 | Only an admin changes access; an admin sees names and levels but no medical content (Admin query, routes, requests, audit and safety-net classification) | pest | `docker compose -p fh0707 run --rm app php -d memory_limit=1G ./vendor/bin/pest tests/Feature/Health/HealthPeopleAdminTest.php tests/Feature/Security/SensitiveGateMatrixTest.php --compact` | ✅ | ✅ green |
| 07-07-02 | 07 | 3 | MED-01,MED-02 | T-07-32..37 | Only an admin changes access; an admin sees names and levels but no medical content (Health access card, person access panel and permissions hint; full verification) | vitest | `npx vitest run tests/js/settings/HealthAccess.test.js tests/js/settings/MembersPage.test.js tests/js/settings/PermissionsTab.test.js` | ✅ | ✅ green |
| 07-08-01 | 08 | 4 | MED-04 | T-07-37..41 | Allergies are scoped by subject; writes need Edit; unverified AI rows are labelled (Allergy schema, query, routes and controller) | pest | `docker compose -p fh0708 run --rm app ./vendor/bin/pest tests/Feature/Health/AllergyTest.php tests/Feature/Security/SensitiveGateMatrixTest.php --compact` | ✅ | ✅ green |
| 07-08-02 | 08 | 4 | MED-04 | T-07-37..41 | Allergies are scoped by subject; writes need Edit; unverified AI rows are labelled (Allergies card and panel on the person page; full verification) | pest+vitest | `npx vitest run tests/js/health/Allergies.test.js && docker compose -p fh0708 run --rm app php -d memory_limit=1G ./vendor/bin/pest tests/Feature/Health --compact` | ✅ | ✅ green |
| 07-09-01 | 09 | 4 | MED-11 | T-07-41..45 | AI consent is per person, off by default, audited, and never read from a request body (Consent storage, system query, AiConsent contract and route) | pest | `docker compose -p fh0709 run --rm app ./vendor/bin/pest tests/Feature/Health/AiConsentTest.php tests/Feature/Security/SensitiveGateMatrixTest.php --compact` | ✅ | ✅ green |
| 07-09-02 | 09 | 4 | MED-11 | T-07-41..45 | AI consent is per person, off by default, audited, and never read from a request body (AI consent control in the person header; full verification) | vitest | `npx vitest run tests/js/health/AiConsent.test.js tests/js/health/Person.test.js` | ✅ | ✅ green |
| 07-10-01 | 10 | 4 | MED-06 | T-07-46..52 | Uploads are sniffed by bytes, re-encoded, size-limited and stored outside the web root (Sniffer, store, generalised ImageProcessor and fixtures) | pest | `docker compose -p fh0710 run --rm app ./vendor/bin/pest tests/Unit/Health/DocumentSnifferTest.php tests/Unit/Wishlist/ImageProcessorTest.php --compact` | ✅ | ✅ green |
| 07-10-02 | 10 | 4 | MED-06 | T-07-46..52 | Uploads are sniffed by bytes, re-encoded, size-limited and stored outside the web root (Documents schema, upload route and limit probe; full verification) | pest | `docker compose -p fh0710 run --rm app php -d memory_limit=1G ./vendor/bin/pest tests/Feature/Health/DocumentUploadTest.php tests/Feature/Security/SensitiveGateMatrixTest.php tests/Feature/Health/MedicalWriteParityTest.php --compact` | ✅ | ✅ green |
| 07-11-01 | 11 | 5 | MED-04 | T-07-52..56 | Medications are scoped by subject; writes need Edit (Medication schema, query, routes and controller) | pest | `docker compose -p fh0711 run --rm app ./vendor/bin/pest tests/Feature/Health/MedicationTest.php tests/Feature/Security/SensitiveGateMatrixTest.php --compact` | ✅ | ✅ green |
| 07-11-02 | 11 | 5 | MED-04 | T-07-52..56 | Medications are scoped by subject; writes need Edit (Medications card and panel; full verification) | pest+vitest | `npx vitest run tests/js/health/Medications.test.js && docker compose -p fh0711 run --rm app php -d memory_limit=1G ./vendor/bin/pest tests/Feature/Health --compact` | ✅ | ✅ green |
| 07-12-01 | 12 | 5 | MED-06 | T-07-57..63 | Previews and files are served only to authorised viewers with hardened headers and identical refusals (Preview and file delivery with headers, cache split and identical refusals) | pest | `docker compose -p fh0712 run --rm app php -d memory_limit=1G ./vendor/bin/pest tests/Feature/Health/DocumentServeTest.php tests/Feature/CacheHeadersTest.php tests/Feature/Health/MedicalPrivacyTest.php tests/Feature/Security/SensitiveGateMatrixTest.php --compact` | ✅ | ✅ green |
| 07-12-02 | 12 | 5 | MED-06 | T-07-57..63 | Previews and files are served only to authorised viewers with hardened headers and identical refusals (Rename, attach, delete and the daily orphan sweep) | pest | `docker compose -p fh0712 run --rm app php -d memory_limit=1G ./vendor/bin/pest tests/Feature/Health/DocumentManageTest.php tests/Feature/Health/MedicalWriteParityTest.php tests/Feature/Security/SensitiveGateMatrixTest.php tests/Unit/Health --compact` | ✅ | ✅ green |
| 07-12-03 | 12 | 5 | MED-06 | T-07-57..63 | Previews and files are served only to authorised viewers with hardened headers and identical refusals (Transfer helpers, shared refusal handler and consent-by-document; full verification) | pest+vitest | `npx vitest run tests/js/security/securityTransfer.test.js tests/js/health/healthRefusal.test.js && docker compose -p fh0712 run --rm app php -d memory_limit=1G ./vendor/bin/pest tests/Feature/Health --compact` | ✅ | ✅ green |
| 07-13-01 | 13 | 6 | MED-05 | T-07-63..67 | Vaccinations are scoped by subject; public ids only; due status computed server-side (Vaccination schema, query with due status, mark-as-given, routes) | pest | `docker compose -p fh0713 run --rm app ./vendor/bin/pest tests/Feature/Health/VaccinationTest.php tests/Feature/Security/SensitiveGateMatrixTest.php --compact` | ✅ | ✅ green |
| 07-13-02 | 13 | 6 | MED-05 | T-07-63..67 | Vaccinations are scoped by subject; public ids only; due status computed server-side (Vaccinations card, alert, panel and timeline entries; full verification) | pest+vitest | `npx vitest run tests/js/health/Vaccinations.test.js tests/js/health/Visits.test.js && docker compose -p fh0713 run --rm app php -d memory_limit=1G ./vendor/bin/pest tests/Feature/Health --compact` | ✅ | ✅ green |
| 07-14-01 | 14 | 7 | MED-06 | T-07-68..72 | Document UI never persists data in history or browser storage; refusals go to the shared handler (Document props, uploader and document panel) | pest+vitest | `npx vitest run tests/js/health/DocumentUploader.test.js && docker compose -p fh0714 run --rm app ./vendor/bin/pest tests/Feature/Health/DocumentPageTest.php --compact` | ✅ | ✅ green |
| 07-14-02 | 14 | 7 | MED-06 | T-07-68..72 | Document UI never persists data in history or browser storage; refusals go to the shared handler (Attach from visits, folders and timeline documents; full verification) | vitest | `npx vitest run tests/js/health` | ✅ | ✅ green |
| 07-15-01 | 15 | 7 | MED-05 | T-07-73..77 | Reminders carry ids only, are re-checked at send time and never name a vaccine or date (Recipients, planner, command and schedule) | pest | `docker compose -p fh0715 run --rm app ./vendor/bin/pest tests/Feature/Health/VaccinationReminderTest.php --compact` | ✅ | ✅ green |
| 07-15-02 | 15 | 7 | MED-05 | T-07-73..77 | Reminders carry ids only, are re-checked at send time and never name a vaccine or date (Send-time handler, notification and registry; full verification) | pest | `docker compose -p fh0715 run --rm app php -d memory_limit=1G ./vendor/bin/pest tests/Feature/Health/VaccinationReminderTest.php tests/Feature/Wishlist/OccasionReminderTest.php tests/Unit/Arch/MedicalPrivacyArchTest.php tests/Feature/Notifications --compact` | ✅ | ✅ green |
| 07-16-01 | 16 | 7 | MED-07,MED-08,MED-09 | T-07-78..84 | Exports are audited before the file is built, scoped to the visible subject and never cached (Provenance, export query, exporter and formats) | pest | `docker compose -p fh0716 run --rm app ./vendor/bin/pest tests/Unit/Health/ExportFormatTest.php --compact` | ✅ | ✅ green |
| 07-16-02 | 16 | 7 | MED-07,MED-08,MED-09 | T-07-78..84 | Exports are audited before the file is built, scoped to the visible subject and never cached (Export routes, audit-first controller, form, print page and PrintLayout; full verification) | pest+vitest | `npx vitest run tests/js/health/Export.test.js && docker compose -p fh0716 run --rm app php -d memory_limit=1G ./vendor/bin/pest tests/Feature/Health/ExportTest.php tests/Feature/Health/MedicalPrivacyTest.php tests/Feature/Security/SensitiveGateMatrixTest.php --compact` | ✅ | ✅ green |
| 07-17-01 | 17 | 8 | MED-01,MED-10 | T-07-84..89 | Contacts and person delete are scoped, transactional and audited with ids only (Contacts schema, person update and hard delete) | pest | `docker compose -p fh0717 run --rm app php -d memory_limit=1G ./vendor/bin/pest tests/Feature/Health/PersonManageTest.php tests/Feature/Health/MedicalWriteParityTest.php tests/Feature/Security/SensitiveGateMatrixTest.php --compact` | ✅ | ✅ green |
| 07-17-02 | 17 | 8 | MED-01,MED-10 | T-07-84..89 | Contacts and person delete are scoped, transactional and audited with ids only (Person panel edit mode, header actions and delete confirm; full verification) | pest+vitest | `npx vitest run tests/js/health && docker compose -p fh0717 run --rm app php -d memory_limit=1G ./vendor/bin/pest tests/Feature/Health --compact` | ✅ | ✅ green |
| 07-18-01 | 18 | 9 | MED-10 | T-07-90..94 | Emergency card is audited per open, scoped, and shows only the declared fields (Emergency card route, props and audit) | pest | `docker compose -p fh0718 run --rm app php -d memory_limit=1G ./vendor/bin/pest tests/Feature/Health/EmergencyCardTest.php tests/Feature/Health/MedicalPrivacyTest.php tests/Feature/Security/SensitiveGateMatrixTest.php --compact` | ✅ | ✅ green |
| 07-18-02 | 18 | 9 | MED-10 | T-07-90..94 | Emergency card is audited per open, scoped, and shows only the declared fields (Card page, summary card and header button; full verification) | vitest | `npx vitest run tests/js/health` | ✅ | ✅ green |
| 07-19-01 | 19 | 10 | MED-01,MED-02,MED-03,MED-04,MED-05,MED-06,MED-07,MED-08,MED-09,MED-10,MED-11 | T-07-100..99 | Leak-net completeness, mutation sanity, admin privacy, no-store, canary scan, staging audit, axe sweep (Leak-suite completeness, mutation sanity, admin privacy) | pest | `docker compose -p fh0719 run --rm app php -d memory_limit=1G ./vendor/bin/pest tests/Feature/Health/MedicalLeakSuiteTest.php tests/Feature/Health/AdminMedicalPrivacyTest.php tests/Feature/Health/MedicalWriteParityTest.php --compact` | ✅ | ✅ green |
| 07-19-02 | 19 | 10 | MED-01,MED-02,MED-03,MED-04,MED-05,MED-06,MED-07,MED-08,MED-09,MED-10,MED-11 | T-07-100..99 | Leak-net completeness, mutation sanity, admin privacy, no-store, canary scan, staging audit, axe sweep (Cache headers, audit canary, staging audit, service worker and history checks) | pest+vitest | `docker compose -p fh0719 run --rm app php -d memory_limit=1G ./vendor/bin/pest tests/Feature/Health/MedicalCacheHeadersTest.php tests/Feature/Health/MedicalAuditContentTest.php tests/Feature/Health/StagingAuditMedicalTest.php --compact && npx vitest run tests/js/health/sw-health.test.js tests/js/health/history-health.test.js` | ✅ | ✅ green |
| 07-19-03 | 19 | 10 | MED-01,MED-02,MED-03,MED-04,MED-05,MED-06,MED-07,MED-08,MED-09,MED-10,MED-11 | T-07-100..99 | Leak-net completeness, mutation sanity, admin privacy, no-store, canary scan, staging audit, axe sweep (Phase-wide axe sweep, copy fixes, validation sign-off; full verification) | pest+vitest | `npx vitest run tests/js/health/a11y-health.test.js && docker compose -p fh0719 run --rm app php -d memory_limit=1G ./vendor/bin/pest --compact && npx vitest run` | ✅ | ✅ green |
| 07-20-01 | 20 | 11 | MED-01..MED-11 | T-07-101.. | Staging release and server checks (live) | script | `ssh my-server-agent 'sudo docker ps --format "{{.Names}}"' \| grep -c staging` | n/a (live) | ⬜ pending |
| 07-20-02 | 20 | 11 | MED-01..MED-11 | - | Real-device checks on staging (deferred, with owner) | manual | `grep -n "Phase 7" docs/ops/server.md` | n/a (live) | ⬜ pending |
| 07-20-03 | 20 | 11 | MED-01..MED-11 | - | Owner decisions, native SK/PL review, GDPR note (deferred, with owner) | manual | `grep -n "PROVISIONAL\|Phase 7 decisions" docs/ops/server.md` | n/a (live) | ⬜ pending |
| 07-20-04 | 20 | 11 | MED-01..MED-11 | - | Production release and records (live) | script | `gh pr list --state merged --base main --limit 5` | n/a (live) | ⬜ pending |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

Notes on the map:
- 07-15-02 lists `tests/Feature/Wishlist/OccasionReminderTest.php`; the plan text named `WishOccasionOutboxTest.php`, which never existed. The stale name was corrected here, and the file that actually covers the shared outbox registry is the one listed.
- The Test Type column classifies the command: `pest`, `vitest`, `pest+vitest` or `script`.

---

## Wave 0 Requirements

- [x] `tests/Support/MedicalPrivacy.php` and `tests/Support/MedicalPrivacy/*.php` — leak harness and one area file per slice (07-06 and later)
- [x] `tests/Support/MedicalFixtures.php` — subjects, grants and matrix params
- [x] `tests/Support/MedicalFileFixtures.php` — PDF, HEIC, AVIF, BMP and tail fixtures built in code
- [x] Matrix files — Health rows in the sensitive-gate and endpoint matrices (`tests/Support/EndpointMatrix*`, `SensitiveGateMatrixTest`)
- [x] `tests/Unit/Arch/MedicalPrivacyArchTest.php` plus `tests/Unit/Health/*` static scans — arch rule, table scan, mutation scan, file-store scan
- [x] `tests/js/i18n-phase7.test.js` — SK/PL/EN key parity and plural forms for all `health.*` copy

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| Real HEIC and PDF uploads from phone and iPad | MED-06 | Needs real devices and camera files; CI uses synthetic bytes | Plan 07-20 task 2 on staging |
| Printing the export and emergency card on real devices | MED-07, MED-10 | Browser print dialogs and paper output are not scriptable | Plan 07-20 task 2 |
| Native Slovak and Polish review of Health copy | MED-01..MED-11 | Needs a native reader | Plan 07-20 task 3 |
| Live medical volume, backup snapshot and restore on the VPS | MED-06 | Needs the real server and volumes | Plan 07-20 tasks 1 and 4 |

---

## Validation Sign-Off

- [x] All tasks have `<automated>` verify or Wave 0 dependencies
- [x] Sampling continuity: no 3 consecutive tasks without automated verify
- [x] Wave 0 covers all MISSING references
- [x] No watch-mode flags
- [x] Feedback latency acceptable (per-task commands run in seconds)
- [x] The nyquist flag is set to true in the frontmatter

**Approval:** approved 2026-11-14
