---
phase: 07-medical-records
plan: 04
subsystem: ui
tags: [nav, home, audit, inertia-history, forbidden, medical]
requires: [07-01]
provides:
  - "Health nav item linked to /health (no badge), static HomeHealthCard"
  - "Forbidden.vue 'Back to Health' link for /health and /health/* derived from the URL only"
  - "AuditTable rows for the six medical events with change/scope/format/value phrases"
  - "Inertia::clearHistory() on the confirm-access page"
affects: [07-03, 07-06]
key-files:
  created: [resources/js/Dashboard/HomeHealthCard.vue, tests/js/settings/AuditHealth.test.js, tests/Feature/Health/HealthHistoryTest.php]
  modified: [resources/js/Shell/modules.js, resources/js/Pages/Home.vue, resources/js/Pages/Errors/Forbidden.vue, resources/js/Components/Settings/AuditTable.vue, app/Http/Controllers/Security/ReauthController.php, tests/js/shell/moduleAccess.test.js, tests/js/shell/AppShell.test.js, tests/js/Home.test.js, tests/js/errors/Forbidden.test.js]
metrics:
  tasks: 2
  completed: 2026-11-14
---

# Phase 7 Plan 04: Health nav, Home card, audit rows, history clearing Summary

Health is reachable from nav and a static prop-less Home card, the 403 page for /health URLs links back to Health from the URL alone, admins read the six medical audit events, and the idle gate clears browser history.

## Commits
- ed58c05 feat(07-04): Health nav link, static Home card and 403 back link
- f063c91 feat(07-04): medical audit rows and clearHistory on confirm-access

## Notes
- Forbidden: `/health` or `/health/...` only (`/healthy` stays generic); no server prop, bootstrap/app.php untouched.
- AuditTable: explicit branches for access_changed, exported, consent_changed (enum allowlist, unmapped meta prints nothing; subject uuid never printed); the other three use the default `audit.detail.<event>` path. Note the pre-existing default branch prints the raw key path for any event with no label (not changed). Neutral HeartPulse tag for `medical_*` rows; group label is "Health data".
- ReauthController::show calls `Inertia::clearHistory()`; encryptHistory and logout behaviour already existed and are now asserted in HealthHistoryTest.

## Deviations
1. [Rule 3] With Health now a link, AppShell.test's coming-soon panel test (which clicked the Health nav button) was rewritten to open the panel through `useShell()`; added a nav test for href /health with no badge. Home test h3 empty-state slice reduced to three (Health card has no empty state).
2. First full Pest run had 6 failures in sensitive-route matrix tests (EndpointMatrix, while sibling 07-03 was adding routes); the single re-run passed.

## Verification
Pest 1679 passed, Vitest 106 files / 2452 tests passed, `npm run build` SW-PRECACHE OK.

## Known Stubs
None.

## Self-Check: PASSED
