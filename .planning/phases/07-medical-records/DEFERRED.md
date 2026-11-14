# Phase 7 deferred live-ops work (owner decision 2026-11-13: staging and production are done together at the end)

Plan 07-20 (staging/production release, server checks, real-device checks, owner decisions, native SK/PL review, GDPR note) is deferred with the other live plans.
MED-01..MED-11 stay unticked in REQUIREMENTS.md until then. Do not push `develop` or `main` before that session (a push triggers a deploy).

## Server work (07-20 Task 1 and Task 4)
Image rebuild (gd/exif, bcmath), `medical` volume (app user, 0700), upload limit probe (11 MiB POST must not be 413), manual backup with `/medical.tar` and tags `<env>`, `medical`, `medical-manual`, restore drill (`RESTORE-DRILL MEDICAL OK`), freshness after the nightly, `staging:audit`. Staging first, production only via PR develop -> main. Record results without values in docs/ops/server.md.

## Real-device checks (Task 2, synthetic data only)
HEIC/JPEG/PDF upload on iPhone and iPad (Safari and PWA), print emergency card and doctor export on one A4 page, vaccination reminder push and mail, person hidden from a member without access, idle 15 min then upload/download goes to "Confirm it's you". Also a real 320px check (jsdom cannot measure layout).

## Owner decisions (Task 3), ask Decision A and B first
- **Decision A:** the Health module grant stays a prerequisite even for a linked member's own record. This narrows the locked CONTEXT line "a linked member always sees their own record". A1 confirm / A2 restore the locked wording.
- **Decision B:** any member with Edit on a person can hard-delete the person and all their files (irreversible outside backups). B1 confirm / B2 stricter level / B3 soft delete or trash. Whole behaviour is in `SubjectQuery::delete` and the `PersonController::destroy` route level.
- Export needs Edit; emergency card needs View only (T-07-93).
- PDFs/HEIC/downloads are `no-store`; only image derivatives are `private, no-cache` with ETag.
- Admin sees person names only on the settings page.
- No app-level file encryption in v1; originals are not bundled in exports.
- SK/PL specialty labels and Health copy need native review.
- AI Markdown free text may contain the person's name (accepted limit).
- Server cannot decode HEIC (GD only): HEIC is stored as the original with "No preview".
- GDPR household-exemption and children's data position (STATE blocker) to be recorded.

## Notes
- Shared component `UiDetailPanel.vue` modal variant now uses `div` instead of `header`/`footer` (07-19 axe fix); affects panels in every module at phone width, sanity-check visually.
- Suite peak memory 275 MiB against the 512M phpunit limit.
