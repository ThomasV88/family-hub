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
