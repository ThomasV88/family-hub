---
phase: 02-identity-and-authorization-core
plan: 03
subsystem: auth
tags: [laravel, authorization, middleware, pest, inertia, vue]
requires: [02-02]
provides:
  - module_permissions table (CHECK on module and level, unique user+module)
  - Module, AccessLevel, Preset enums (presets locked per CONTEXT)
  - AccessGate (scoped) with requireAccess/allows/level/levels/isAdmin/forget
  - access:<rule> middleware and fail-closed EnsureRouteDeclaresAccess with Fortify route map
  - routes/web/*.php loader, tests/Support/EndpointMatrix/*.php loader
  - shared prop auth.access, Errors/Forbidden page, visibleModules()
affects: [02-04, 02-05, 02-06, 02-07, 02-08, 02-09]
tech-stack:
  added: []
  patterns: [one deny-by-default gate, route rule via middleware alias, hand-written role-by-endpoint matrix, per-feature route and matrix files]
key-files:
  created:
    - app/Access/Module.php
    - app/Access/AccessLevel.php
    - app/Access/Preset.php
    - app/Access/AccessGate.php
    - app/Models/ModulePermission.php
    - app/Http/Middleware/RequireAccess.php
    - app/Http/Middleware/EnsureRouteDeclaresAccess.php
    - database/migrations/2026_11_13_110000_create_module_permissions_table.php
    - routes/web/.gitkeep
    - tests/Support/EndpointMatrix.php
    - tests/Support/EndpointMatrix/core.php
    - tests/Support/Roles.php
    - tests/Feature/Access/{AccessGateTest,PresetTest,ForbiddenPageTest,EndpointMatrixTest}.php
    - resources/js/Pages/Errors/Forbidden.vue
    - tests/js/shell/moduleAccess.test.js
    - tests/js/errors/Forbidden.test.js
  modified:
    - app/Models/User.php
    - database/factories/UserFactory.php
    - database/seeders/StagingSeeder.php
    - app/Http/Middleware/HandleInertiaRequests.php
    - app/Providers/AppServiceProvider.php
    - app/Providers/FortifyServiceProvider.php
    - bootstrap/app.php
    - routes/web.php
    - config/filesystems.php
    - resources/js/Shell/{modules.js,SidebarNav.vue,BottomNav.vue}
    - resources/js/Pages/Home.vue
    - resources/js/Components/ui/UiErrorState.vue
decisions:
  - "Fortify route rules live in EnsureRouteDeclaresAccess::FORTIFY_RULES (public constant, read by the matrix test)"
  - "Matrix ignores routes by URI (up, _inertia/devtools prefix), not by name, because those routes are unnamed"
  - "Guest on a JSON request gets 401; Inertia/HTML guests are redirected to /login"
  - "The 419 redirect is handled in the HttpException renderer because Laravel hands render callbacks the prepared 419 exception, not TokenMismatchException"
metrics:
  tasks: 3
  completed: 2026-11-13
---

# Phase 2 Plan 03: Deny-by-default policy layer Summary

Every route now declares an `access:` rule or is refused with 403; per-member module grants live in `module_permissions`, one `AccessGate::requireAccess` decides, a hand-written role-by-endpoint Pest matrix fails CI for a missing rule or row, and members see a 403 page and a nav limited to their modules.

## Tasks

| Task | Commit | Notes |
|------|--------|-------|
| 1 Grants, AccessGate, middleware, fail-closed guard, 403/419/session_ended | 872757b | 98 Pest tests green |
| 2 Matrix harness and tests | 9790f79 | 113 Pest tests green |
| 3 Forbidden page, access-aware nav | b73c36d | Vitest 305 green, build SW-PRECACHE OK |

## Verification

- Pest 113 passed; Vitest 305 passed (27 files); `npm run build` prints SW-PRECACHE OK; compose project `fh0203` removed with `down -v`.
- Pint: only `routes/console.php` flags (pre-existing).
- Matrix guard confirmed once: temporarily adding an unnamed and a named-but-matrix-less route made "has a matrix row for every named route" fail with `UNNAMED:tmp-unnamed, tmp.named`; the temporary route file was removed.
- Acceptance greps: `function requireAccess` 1, `Gate::before` 0, `'auth'` in routes/web.php 0, `grep -c "middleware()"` in core.php 0.
- Direct `MODULES` consumers left: `ModuleCard.vue` and `ComingSoonPanel.vue` look up a single module by key (not a rendered list); the Home page only renders cards allowed by `visibleModules`.
- Threats T-02-11 to T-02-15 covered (fail-closed test, admin none for finance/health, scoped gate test, literal matrix plus mutation test, CHECK/unique constraint tests).

## Deviations from Plan

**1. [Rule 2 - Security] Disabled framework `storage.local` route**
- Issue: `config/filesystems.php` local disk had `serve => true`, registering `storage/{path}` GET and PUT routes outside the web group, so outside the access guard and unnamed-route check.
- Fix: `serve => false` (the app does not use temporary URLs). Later upload phases must use an authenticated controller anyway.
- Commit: 9790f79.

**2. [Rule 1 - Bug] Fortify `password.confirm` GET returned 500**
- Issue: Fortify registers the confirm-password routes regardless of features; with no view registered a signed-in GET threw 500.
- Fix: `Fortify::confirmPasswordView(fn () => abort(404))`; the three confirm routes are also in the Fortify map (authenticated) and the matrix.
- Commit: 9790f79.

**3. [Rule 3 - Blocking] Inertia devtools routes**
- Unnamed `_inertia/devtools/*` routes exist in testing; they are web-group routes (the fail-closed guard answers 403) and are excluded from the matrix by URI prefix with a reason comment.

**4. [Minor] Beyond plan**
- `UiErrorState` got an `action` slot (default stays the Retry button) so Forbidden can link home.
- BottomNav uses `grid-auto-flow: column` so fewer than five modules fill the bar.
- Existing Vitest mocks (Home, AppShell) default `auth.access` to full access; new tests cover nav and card filtering.
- Logout expectation in the matrix is 303 for signed-in users and locale.update is 303 (both written from real behavior).

## Auth gates

None.

## Known Stubs

None. `RequireAccess::afterDecision()` is an intentionally empty hook that plan 05 fills with audit writes.

## Threat Flags

None beyond the `storage.local` route removed above.

## Requirements

AUTH-07 is delivered in full. AUTH-05 (data model only; the permission editor UI is a later plan) and AUTH-06 (presets defined; applying them via invitations is later) are only partly delivered and were not ticked.

## Notes for later plans

- Add routes in `routes/web/<feature>.php` with an `access:` middleware and a matching `tests/Support/EndpointMatrix/<feature>.php`; the matrix test needs every role listed per row.
- After saving grants call `AccessGate::forget($user)`.
- Human check at end of phase (not run): sign in as a Guest-preset member on a phone; only Overview and To-do appear; `/settings/members` shows the access-denied page in the shell.

## Self-Check: PASSED

Files verified and commits 872757b, 9790f79, b73c36d present.
