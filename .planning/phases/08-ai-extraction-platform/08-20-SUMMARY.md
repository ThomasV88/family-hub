---
phase: 08-ai-extraction-platform
plan: 20
subsystem: health-ui
tags: [provenance, ai-02, vue, print-export]
requires: [08-09, 08-16, 08-23]
provides: [ProvenanceTags component, Mark as verified action in the four record panels]
affects: [resources/js/Health, resources/js/Pages/Health/ExportPrint.vue]
key-files:
  created: [resources/js/Health/ProvenanceTags.vue, tests/js/health/Provenance.test.js]
  modified: [AllergyList.vue, MedicationList.vue, VaccinationList.vue, TimelineList.vue, VisitPanel.vue, MedicationPanel.vue, AllergyPanel.vue, VaccinationPanel.vue, ExportPrint.vue]
decisions:
  - "Copy keys are health.ai.provenance.* (not health.ai.label.*), as in lang/en.json"
  - "ProvenanceTags also renders the optional verify button (canVerify prop, emits verify); each panel owns the router.post to health.<type>.verify"
  - "SpecialtyFolder needed no change: it renders TimelineList, which carries the tags"
metrics:
  tasks: 2
  completed: 2026-11-14
---

# Phase 8 Plan 20: Provenance tags and Mark as verified Summary

AI-extracted records show "AI-extracted" plus "Unverified" or "Verified" (text and icon, wrapping, forced-colors border) in side-card rows, timeline and folder entries, the panel header and the print export, and editors can verify an AI record from its panel.

## What was built
- `ProvenanceTags.vue`: unverified gives AI-extracted (Bot) and Unverified (TriangleAlert, warn); verified gives AI-extracted and Verified (Check, success); manual, null or missing renders nothing.
- Rows: allergy tags follow the severity tag; medication and vaccination rows and visit and vaccination timeline entries show the tags on their own line. Specialty folders get them through TimelineList.
- ExportPrint: unverified rows print the bordered words "AI-extracted, Unverified".
- Panels (visit, medication, allergy, vaccination): tags at the top; "Mark as verified" shown only when the record is unverified and the member can edit; posts once (loading guard) to `/health/people/{person}/{visits|medications|allergies|vaccinations}/{id}/verify` with preserveScroll. The toast comes from the server flash (08-09). Editing sends no verification fields, so it never verifies.

## Deviations from Plan
- [Rule 3] Plan said `health.ai.label.*`; used the real `health.ai.provenance.*` keys per the instruction.
- SpecialtyFolder.vue left unchanged (listed in files_modified, but no change was needed).

## Verification
- Provenance.test.js: 32 tests (display rules per location, order after severity, verify route per panel, view-only, verified, manual, no auto-verify on edit, axe en/sk/pl and dark).
- Full Vitest: 3710 of 3711 passed. The one failure, AiReview.test.js "lists the server errors inline on 422", came from 08-19 files being edited in the shared tree at that moment; it passed twice in isolation afterwards.
- `npm run build`: OK (SW-PRECACHE OK).
- Full Pest: 2242 passed, 1 failed: `tests/Feature/Health/MedicalLeakSuiteTest.php:142` (real-surface before/after comparison). Passes when run alone (7 passed). This plan changes no backend code; likely order-dependent or flaky, not investigated further.

## Known Stubs
None.

## Self-Check: PASSED
