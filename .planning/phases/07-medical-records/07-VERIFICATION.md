---
phase: 07-medical-records
verified: 2026-11-14T10:00:00Z
status: human_needed
score: 5/5 roadmap success criteria verified in code and tests
gaps: []
human_verification:
  - test: "Plan 07-20 live ops on staging then production (image rebuild, medical volume, 11 MiB upload probe, backup with /medical.tar, restore drill, freshness, staging:audit)"
    expected: "Probe is not 413; RESTORE-DRILL MEDICAL OK; freshness passes"
    why_human: "Needs the live server; deferred by owner (DEFERRED.md)"
  - test: "Real-device checks with synthetic data: HEIC/JPEG/PDF upload on iPhone and iPad (Safari and PWA), A4 print of emergency card and export, vaccination push and mail, hidden person for a member without access, idle-lock then upload goes to Confirm it's you, real 320px layout"
    expected: "All behave as in 07-20 Task 2"
    why_human: "Real devices and layout measurement"
  - test: "Owner decisions A and B, WR-05, WR-06, WR-07, WR-11, WR-12 (see DEFERRED.md)"
    expected: "Owner chooses; code is changed only if option differs from current"
    why_human: "Product decisions, intentionally recorded rather than fixed"
  - test: "Native SK/PL review of Health copy and specialty labels; GDPR household-exemption note; server LOG_LEVEL=warning and APP_DEBUG=false"
    expected: "Reviewed and recorded"
    why_human: "Language and legal review, server env"
---

# Phase 7: Medical Records Verification Report

**Phase Goal:** The family can keep each person's medical history, documents and exports in one private place, fully usable by hand without any AI.
**Status:** human_needed (no code gaps; live-server and device items deferred by owner)
**Re-verification:** No, initial verification

## Automated runs (executed by the verifier, not taken from SUMMARY)

| Check | Command | Result |
|---|---|---|
| Full Pest | `docker compose -p fh0721 run --rm app php -d memory_limit=1G ./vendor/bin/pest` | 1998 passed (23422 assertions), 162.6 s, exit 0, no failures |
| Full Vitest | `npx vitest run` | 122 files, 3388 tests passed, exit 0 (jsdom canvas "Not implemented" noise only) |
| Build | `npm run build` | exit 0, SW-PRECACHE OK |
| Cleanup | `docker compose -p fh0721 down -v` | containers, network and volumes removed |

The review fixer's last fixture change and pint pass are therefore confirmed green against the whole suite.

## Observable Truths (ROADMAP success criteria)

| # | Truth | Status | Evidence |
|---|---|---|---|
| 1 | Person profile for non-user; per-person access grants | VERIFIED | `medical_subjects` migration, `Health/PersonController`, `Settings/HealthPeopleController` + `HealthPeopleMembersRequest`/`LinkRequest`, routes `settings-health-people.php`, `health.php`; leak-net and endpoint-matrix tests pass in Pest |
| 2 | Visits, medications, allergies, vaccinations, due reminder | VERIFIED | Controllers `VisitController`, `MedicationController`, allergy requests, `VaccinationController`; routes health-visits/medications/allergies/vaccinations; reminder through outbox covered by Pest (07-15) |
| 3 | PDF/photo/HEIC upload, optional visit, authorized-only delivery | VERIFIED | `DocumentController`, `StoreDocumentRequest`, health-documents routes; sniffing/HEIC/delivery/cache tests pass. Server HEIC decode is by design not supported (stored as original, "No preview") |
| 4 | Export by specialty, per person, AI Markdown with unverified labeling | VERIFIED | `ExportController`, `Medical/Export/{Exporter,JsonFormat,MarkdownFormat}`, `Medical/Provenance.php`, `ExportQuery`; tests pass |
| 5 | One-screen emergency card, AI consent default off | VERIFIED | `EmergencyCardController`; migration `ai_consent` boolean default false; `AiConsentController` + `AiConsentRequest`; tests pass |

**Score:** 5/5

## Requirements Coverage

MED-01 through MED-11: all SATISFIED in code and tests (mapping as in the table above: MED-01/02 criterion 1, MED-03/04/05 criterion 2, MED-06 criterion 3, MED-07/08/09 criterion 4, MED-10/11 criterion 5). No orphaned requirements. REQUIREMENTS.md checkboxes stay unticked until 07-20 by owner decision (DEFERRED.md); tick them after the live session.

## Anti-Patterns

TBD/FIXME/XXX grep over `app/` and `resources/js`: no hits. Working tree clean before the report.

## Deferred / Human

Plan 07-20 is deferred by owner decision. Open owner decisions (A, B, WR-05, WR-06, WR-07, WR-11, WR-12) are documented trade-offs, not code gaps. Note that WR-07 (any Edit member can enable AI consent for a person with their own account) and WR-06 (Edit-level hard delete) touch privacy, so settle them before the production release.

## Gaps Summary

None in code. Status is human_needed because live-server, real-device, owner-decision and native-language review items remain.

_Verified: 2026-11-14_
_Verifier: Claude (gsd-verifier)_
