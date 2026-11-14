---
phase: 07-medical-records
plan: 01
subsystem: i18n
tags: [i18n, vue-i18n, lang, audit, notifications, medical]
requires: []
provides:
  - "health.* copy (343 keys incl. 30 specialty labels, 10 pipe plurals) in en/sk/pl"
  - "audit.detail.medical_* labels for the six medical audit events and their meta values"
  - "notifications.vaccination_title|body|due_in|action in en/sk/pl"
  - "six medical AuditEvent cases (health group, content-free meta allowlists)"
affects: [07-02, 07-03, 07-07, 07-09, 07-16, 07-17, 07-18, 07-19, 07-20]
tech-stack:
  added: []
  patterns: ["nested lang JSON (health.* under a top-level health object)", "pipe plurals en 2 / sk,pl 4 segments"]
key-files:
  created: [tests/js/i18n-phase7.test.js, tests/Unit/MedicalLangTest.php]
  modified: [lang/en.json, lang/sk.json, lang/pl.json, lang/en/notifications.php, lang/sk/notifications.php, lang/pl/notifications.php, app/Audit/AuditEvent.php, tests/Unit/NotificationsLangTest.php]
key-decisions:
  - "Specialty keys are the research's 30 keys; EN label of `other` is 'Other'"
  - "Lang JSON is nested, so keys are addressed as health.x.y (flattened by the tests)"
metrics:
  tasks: 2
  completed: 2026-11-14
---

# Phase 7 Plan 01: Health copy, medical audit events Summary

All Phase 7 strings exist once, in EN, SK and PL with identical keys, and parity plus plural rules are enforced by tests. The six medical audit events exist in `AuditEvent`.

## What was built

- **Copy:** 343 `health.*` keys per locale (screens, panels, confirms, uploader, emergency card, export, AI consent, access admin, Home card, 30 `health.specialty.*`). Plurals: the 10 UI-SPEC keys, en 2 segments, sk/pl exactly 4.
- **Audit:** `audit.detail.medical_*` for the six events plus one phrase per `change`, `scope`, `format`, `value` meta value (21 keys). `AuditEvent` gained `MedicalSubjectCreated|Deleted|AccessChanged|AdminSelfAdded|Exported|ConsentChanged`, group `health`, meta allowlists `subject`, `change`, `scope`, `format`, `value` (opaque; no names).
- **Notifications:** vaccination title (person only), body (`:person` and `:date`, no vaccine placeholder), `due_in` trans_choice (same shape as `gift_in_days`), action.
- **Tests:** `tests/js/i18n-phase7.test.js` (196 tests: key parity, placeholders, no `!`/`...`/`<`, compile and render, plural segments and picked form for 0,1,2,4,5,12,22,25 on all 10 keys), `tests/Unit/MedicalLangTest.php`.
- Probe: removing one sk segment of `health.folder.visits` made the Vitest fail (6 tests), reverted before commit.

## Commits

- 87ec27c feat(07-01): add Health copy in EN, SK and PL
- 06ac04a feat(07-01): medical audit events and Phase 7 copy tests

## Deviations from Plan

1. **[Rule 1 - Bug] `tests/Unit/NotificationsLangTest.php` key list** asserted the exact notification key set; added the four `vaccination_*` keys (committed with Task 1). File not in the plan list but directly broken by the plan's own change.
2. **[Rule 3] Acceptance greps** (`grep -c '"health.specialty.'` equals 30) cannot match literally because lang files are nested objects; verified instead by flattening (343 health keys and 30 specialties in each of en/sk/pl).
3. **`health.upload.uploading` is "Uploading" without the three dots** (UI-SPEC says "Uploading..."): the parity convention forbids `...` in copy. 07-xx may render the ellipsis itself if wanted.
4. **Vaccination mail body carries the date** (plan: "person and date only"), while the UI-SPEC privacy section says no date in notifications; the plan was followed. Push title is person only. Owner/07-xx can drop `:date` from `vaccination_body` if the stricter reading is preferred.
5. Full-suite runs: first Pest run had 1 failure (`Unit/Wishlist/ImageRuntimeTest`, `upload_max_filesize`) and first Vitest run 1 failure in a file this plan does not own; both passed on the single re-run (sibling 07-02 was changing shared files at the time). Final: Pest 1640+ passed, Vitest 104 files / 2395 tests passed, `npm run build` printed SW-PRECACHE OK.

## Needs native review (flag for 07-20)

All SK and PL copy in this plan is a translation without a native check: the 30 specialty labels (taken from the research table, marked `[ASSUMED]`), medical terms (Dermatovenerológia, Choroby wewnętrzne, Diabetológia and so on), the AI consent and privacy wording, form errors, export labels, and the plural forms of `{n}` strings. Also the gendered audit verbs ("Pridal(a)", "Dodał(a)", "Usunął(-ęła)") follow the existing finance pattern.

## Known Stubs

None.

## Threat Flags

None. T-07-01 (no vaccine placeholder), T-07-02 (no `<` in health.* values) and T-07-03a (content-free meta) are asserted by tests.

## Self-Check: PASSED

Files and both commits verified present.
