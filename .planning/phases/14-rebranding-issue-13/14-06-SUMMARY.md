---
phase: 14-rebranding-issue-13
plan: 06
subsystem: release-readiness
tags: [branding, guard-test, ops, gate]
requires: [14-01, 14-02, 14-03, 14-04, 14-05]
provides: [old-name regression guard, APP_NAME release note]
key-files:
  created: [tests/js/brand/BrandName.test.js]
  modified: [docs/ops/server.md, resources/js/Components/BrandLogo.vue, resources/js/StyleGuide/Sections/05-Brand.vue, tests/js/todo/Board.test.js, tests/js/finance/Overview.test.js, tests/js/finance/Recurring.test.js]
status: PARTIAL (tasks 1-2 done, task 3 staging checkpoint pending)
---

# Phase 14 Plan 06: Guard, ops note and gate (partial)

Tasks 1 and 2 done; task 3 (staging verification, orchestrator) is pending.

## Task 1
- BrandName.test.js scans lang/, resources/manual, resources/help, resources/js, resources/views, public/offline.html, vite.config.js, README.md for "Family Hub" and asserts "FamilyHUB" in the three lang files and the en getting-started manual. Negative check done with a temporary file under resources/views (failed with the path in the message, then removed).
- docs/ops/server.md: subsection "Product name (Phase 14, FamilyHUB)" with the production APP_NAME step.
- Commit d2652c1.

## Task 2: gate results
- Pint --test: pass (1241 files).
- Pest full serial: 4012 passed, 1 skipped (36955 assertions), 670 s.
- Vitest full: 199 files, 6694 tests passed.
- `npm run build` + check-sw-precache: SW-PRECACHE OK, 14 precache entries; icons: familyhub-maskable-512x512.png, familyhub-mark.svg, familyhub-logo.svg, familyhub-logo-dark.svg, familyhub-apple-touch-180x180.png, familyhub-512x512.png, familyhub-192x192.png, plus offline.html.
- grep "Family Hub" in lang, resources, offline.html, vite.config.js, README.md: no output.
- Commit 5dedb0d carries `Fixes #13`.

## Deviations
1. [Rule 1 - Bug] `npm run build` failed: the Vue compiler turned the static `src="/icons/..."` in BrandLogo.vue and 05-Brand.vue (14-03) into imports that rolldown could not resolve. Fix: bind the paths as script constants (`:src`). Served URLs unchanged.
2. [Rule 1 - Bug] Three Vitest tests (todo Board, finance Overview, Recurring "renders as text only") asserted no `img` anywhere; the new top bar logo broke them. Narrowed to `img:not(.brand-logo__img)`.
3. [Environment] The first Pest run died with a memory fatal in AiJobLeakTest: the local untracked, gitignored storage/logs/laravel.log was 325 MB and that test reads all logs. Moved it to the session scratchpad (not deleted); the rerun passed. Not a code issue; the log keeps growing locally, so it should be rotated.

## Open points for the PR body
- Production: set APP_NAME="FamilyHUB" in /opt/family-hub/app.env at release (docs/ops/server.md).
- SK and PL manual and string drafts need native review.
- iPhone/iPad users must re-add the app to see the new icon (manual FAQ).

## Task 3
Pending: staging checkpoint (steps 0-9), no push or server access done here.

## User documentation
No user-visible change in this plan (manual changes are in 14-05).

## Task 3: staging verification (2026-11-18)

Staging ran image `sha-718736d` (CI run 44215571417: check and deploy success). Step 0 done by the orchestrator: `app.env` backed up as `app.env.pre-rebrand`, `APP_NAME="FamilyHUB"`, `app`/`worker`/`scheduler` recreated, `config('app.name')` prints FamilyHUB. The recreate left the staging nginx on the old app IP (502); fixed with `nginx -s reload` on the staging nginx only. A manual compose recreate needs `IMAGE_TAG` exported (deploy.sh does that).

| Step | Result | Evidence |
|---|---|---|
| 1 | PASS | login, forgot-password, invite: colour logo in light, white in dark; server title FamilyHUB; new favicon links |
| 2 | PASS | top bar logo in light, dark, System light, System dark; 403 page (demo child, /finance) shows it |
| 3 | PASS | primary #a64b0b light, #f2963e dark, focus rings follow; axe wcag2a/2aa 0 violations on Login, Home, Settings in both themes |
| 4 | PASS | manifest name FamilyHUB, 3 familyhub icons 200 image/png, favicon 200, old pwa-192 404 |
| 5 | PASS | new SW precache 14 entries (7 familyhub, offline.html, 0 old, 0 health/finance); offline page shows logo |
| 6 | PASS | invite mail en/sk/pl: FamilyHUB 5, "Family Hub" 0 |
| 7 | PASS | manual en/sk/pl: 0 old name, install section and old-icon FAQ present |
| 8 | PASS | 360 px sk/pl light/dark, 820 and 1280 en: no overlap or horizontal scroll |
| 9 | PASS | production `app.env` still has `APP_NAME="Family Hub"` (count 1): release step pending |

Items to judge (non-blocking):
1. Inertia pages set their own tab titles after hydration (e.g. "Sign in"); the initial HTML title is FamilyHUB.
2. Danger (#c4503f / #e0735f) versus primary hue gap is about 17-20 degrees: eyeball a danger button in the style guide.
3. The light colour lockup (with brush stroke) looks small next to the white one at the same height.
4. At 360 px the Add action is the existing floating "+" button, not in the top bar.

Open points for the PR body: production `APP_NAME="FamilyHUB"` at release; SK and PL manual, help and string drafts need native review; iPhone and iPad users must re-add the app to see the new icon.
