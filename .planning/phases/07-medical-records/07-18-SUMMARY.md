---
phase: 07-medical-records
plan: 18
subsystem: health
tags: [medical, emergency-card, print, audit]
requires: ["07-16", "07-17"]
provides:
  - "GET /health/people/{person}/emergency-card (health.emergency.show, View level, audited, no-store)"
  - "App\\Medical\\EmergencyCard::props"
  - "Health/EmergencyCard page in PrintLayout, EmergencySummaryCard, header Emergency card button"
affects: [07-19, 07-20]
key-files:
  created:
    - app/Medical/EmergencyCard.php
    - app/Http/Controllers/Health/EmergencyCardController.php
    - routes/web/health-emergency.php
    - tests/Support/EndpointMatrix/health-emergency.php
    - tests/Support/MedicalPrivacy/emergency.php
    - tests/Feature/Health/EmergencyCardTest.php
    - resources/js/Pages/Health/EmergencyCard.vue
    - resources/js/Health/EmergencySummaryCard.vue
    - tests/js/health/EmergencyCard.test.js
  modified:
    - resources/js/Health/PersonHeader.vue
    - resources/js/Pages/Health/Person.vue
    - tests/js/health/Person.test.js
key-decisions:
  - "PROVISIONAL (per plan, T-07-93): the emergency card needs View, not Edit; content is limited to allergies, medications taken today, blood type and contacts. Owner confirms in 07-20."
  - "Cache-Control no-store, private is set by the route middleware (cache.headers:no_store;private)"
metrics:
  tasks: 2
  completed: 2026-11-14
---

# Phase 7 Plan 18: Emergency card Summary

Every member who can view a person opens an authenticated, audited, one-page printable emergency card (allergies severe first with the severity word printed, medications taken today, blood type, up to 3 contacts), reachable from a new summary card and a header button on the person page. No public link, QR, signed URL or token exists.

## Commits

- dacda1d feat(07-18): emergency card route, props and audit
- 25490ac feat(07-18): emergency card page, summary card and header button

## What was built

- `EmergencyCard::props` reads only through AllergyQuery::forSubject, MedicationQuery::current and ContactQuery::forSubject (View scope); props are exactly person{id,name,ageYears,birthDate,bloodType}, allergies, medications, contacts, printedOn. No notes, diagnoses or visits.
- Controller resolves with `SubjectQuery::findVisible(..., View)`, 403 on null (hidden, unknown, malformed identical), then writes `medical_exported` meta `{subject, scope: emergency, format: html}` before building (exception not caught, fail closed).
- Route has `access:module,health,view`, throttle 60/min and no-store, private cache headers.
- Matrix row (parent 200, others 403, guest 302) and an empty privacy area file; the leak harness lists the route as a surface with the shared {person} provider and the hidden canary allergies, medications and contacts of the other areas.
- EmergencyCard.vue in PrintLayout: single h1, name 24px, age and birth date, bordered Blood type block (Unknown when empty), three sections with h2, empty sentences, severity as UiTag text plus icon, tel: links, "Printed {date}" footer, no share/QR/link controls.
- EmergencySummaryCard is first in the side column; PersonHeader shows an "Emergency card" button to editors and view-only members (the edit-only buttons keep hiding on narrow containers, the emergency button stays visible).

## Verification

- Full Pest: 1965 passed. Full Vitest: 119 files, 2835 passed (health axe in en/sk/pl, card with and without data, light; person page editor and view-only light/dark). `npm run build` SW-PRECACHE OK. Pint clean on own PHP files. Compose project fh0718 removed with `down -v`.

## Deviations from Plan

1. **[Rule 3]** `tests/js/health/Person.test.js` modified: the side column now has 4 cards instead of 3 (assertion updated).
2. Task 1's Inertia assertions need the page file, so the page component existed in the working tree before the task 1 commit; the two commits are still split as planned (backend, then frontend), and task 1's tests only pass with the task 2 commit present.
3. No new lang keys were needed; all emergency strings already existed in en, sk and pl.
4. The age on the card is computed in the same family-timezone way as PersonPage (duplicated small calculation, no shared helper added to avoid touching PersonPage owned by earlier plans).

## Known Stubs

None.

## Threat Flags

None beyond the plan's register (T-07-90 to T-07-93 covered by tests; T-07-94 no-store set here, service worker proof is 07-19).

## Self-Check: PASSED
