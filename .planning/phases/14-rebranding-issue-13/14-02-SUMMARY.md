---
phase: 14-rebranding-issue-13
plan: 02
subsystem: pwa-branding
tags: [brand, icons, pwa, manifest, favicon]
requires: []
provides: [familyhub icon and logo files in public/icons, FamilyHUB manifest]
affects: [14-03 (uses familyhub-logo.svg, familyhub-logo-dark.svg, familyhub-mark.svg)]
tech-stack:
  added: []
  patterns: [cache-busting by file rename, kit SVGs rewritten without CSS vars]
key-files:
  created:
    - public/icons/familyhub-mark.svg
    - public/icons/familyhub-logo.svg
    - public/icons/familyhub-logo-dark.svg
    - public/icons/familyhub-192x192.png
    - public/icons/familyhub-512x512.png
    - public/icons/familyhub-maskable-512x512.png
    - public/icons/familyhub-apple-touch-180x180.png
    - tests/js/brand/BrandAssets.test.js
  modified:
    - public/favicon.ico
    - vite.config.js
    - resources/views/app.blade.php
    - resources/js/sw.js
  deleted:
    - public/icons/icon.svg, pwa-192x192.png, pwa-512x512.png, maskable-512x512.png, apple-touch-icon-180x180.png
    - pwa-assets.config.js
decisions:
  - "pwa-assets.config.js removed: its only input was the old icon.svg; the brand kit is now the icon source. @vite-pwa/assets-generator devDependency left in package.json."
metrics:
  tasks: 2
  completed: 2026-11-18
---

# Phase 14 Plan 02: Brand icons, favicon and manifest Summary

FamilyHUB F mark replaces the blue FH icons everywhere the browser and OS show an icon: favicon, iOS home screen, PWA manifest (192, 512, maskable 512) and push notifications, all with new file names for cache-busting.

## Commits
- bbaadc5: brand icons, logos, old icons removed, asset test
- 37387ce: vite manifest, app.blade.php head, sw.js
- 9c41cfe: test fix (web paths resolved relative to public/)

## Details
- Maskable icon: kit icon-1024 fitted to 560 px on a 1024 `#fff4eb` canvas, flattened to RGB (checked visually, mark fully inside safe zone). Apple-touch: mark 136 px on white 180 px, RGB.
- SVGs rewritten by a scratchpad script: CSS var colours inlined, style/aria-label/data-preview-id removed, title set to FamilyHUB; viewBox and path data unchanged.
- Manifest: name and short_name FamilyHUB, theme `#ffffff`, background `#fffaf5`. Head: favicon `/favicon.ico?v=familyhub` (32x32), mark SVG, new apple-touch icon, apple title FamilyHUB. Push fallback title and icon/badge updated.
- Precached icon URLs (build output): icons/familyhub-maskable-512x512.png, familyhub-mark.svg, familyhub-logo.svg, familyhub-logo-dark.svg, familyhub-apple-touch-180x180.png, familyhub-512x512.png, familyhub-192x192.png, plus offline.html. `npm run build` passes, SW-PRECACHE OK.
- Tests: BrandAssets (14), sw-precache and push tests pass.

## Deviations from Plan
- The BrandAssets test file was written in full in task 1 (including the wiring tests planned for task 2), so the task 1 commit alone has wiring assertions that only pass after task 2. A follow-up commit fixed a path-resolution bug in my own test (leading slash made `path.resolve` absolute).
- Manifest icon entries keep no explicit `purpose: 'any'` (default is any), as in the existing config.

## User documentation
No resources/manual or resources/help file touched here; icon change and reinstall advice are covered by plan 14-05.

## Known Stubs
None.

## Self-Check: PASSED
Files verified present, commits bbaadc5, 37387ce, 9c41cfe exist.
