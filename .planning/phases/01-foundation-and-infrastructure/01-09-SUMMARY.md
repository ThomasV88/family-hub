---
phase: 01-foundation-and-infrastructure
plan: 09
subsystem: pwa
tags: [vite-plugin-pwa, workbox, service-worker, manifest, cache-control, vue-i18n]
requires: [01-04, 01-07]
provides:
  - Precache-only service worker at /sw.js (scope /) with offline navigation fallback
  - Web manifest, icons, favicon, iOS meta tags
  - Build-time precache allowlist guard (scripts/check-sw-precache.mjs, part of `npm run build`)
  - NoStoreDynamicResponses middleware (Cache-Control no-store, private on web group)
  - PwaUpdateBanner (prompt-style update, translated sk/pl/en)
affects: [01-12]
key-files:
  created: [resources/js/sw.js, resources/js/pwa.js, resources/js/Components/PwaUpdateBanner.vue, scripts/check-sw-precache.mjs, app/Http/Middleware/NoStoreDynamicResponses.php, pwa-assets.config.js, public/offline.html, public/icons/*, tests/Feature/CacheHeadersTest.php, tests/js/sw-precache.test.js, tests/js/PwaUpdateBanner.test.js]
  modified: [package.json, vite.config.js, bootstrap/app.php, docker/nginx/snippets/laravel-locations.conf, resources/js/Layouts/BaseLayout.vue, resources/views/app.blade.php, public/favicon.ico]
key-decisions:
  - "Manifest is emitted by vite-plugin-pwa 2.0 into public/build/manifest.webmanifest; nginx serves it at /manifest.webmanifest via try_files (no-cache kept), no copy step"
  - "sw.js is emitted straight to public/sw.js with scope / (outDir public, buildBase /), so no Service-Worker-Allowed header was needed"
duration: ~40min
completed: 2026-11-13
requirements: [FOUND-03]
---

# Phase 1 Plan 09: PWA slice Summary

Installable PWA with a precache-only Workbox worker (vite-plugin-pwa 2.0.0 injectManifest), a build-failing allowlist guard, `no-store, private` on all dynamic Laravel responses, an offline page and a translated update prompt.

## Tasks

| Task | Commit | Result |
|------|--------|--------|
| 1 Failing tests | 66e6ae4 | Vitest guard test RED (module missing) |
| 2 Worker, manifest, icons, guard, middleware | dcd4761 | Vitest 41/41, build prints SW-PRECACHE OK |
| 3 Registration, meta tags, banner | 229b7cc | Vitest 41/41, Pest 35/35, SMOKE-LOCAL OK |

## PWA 2.0 spike result

vite-plugin-pwa 2.0.0 works with vite 8 and laravel-vite-plugin 3.2.1 with `strategies: 'injectManifest'`, `outDir: 'public'`, `buildBase: '/'`, `base: '/'`, `scope: '/'`. The worker is written to `public/sw.js` (scope `/`). One quirk: the manifest goes to `public/build/manifest.webmanifest` (laravel plugin's outDir), while the injected precache entry is `manifest.webmanifest`. Handled in nginx (`try_files /manifest.webmanifest /build/manifest.webmanifest =404`). The injected list has 12 entries, all inside the allowlist (hashed build assets, workbox-window chunk, icons, offline.html, manifest). Build prints a harmless Rolldown warning (`inlineDynamicImports` deprecated) from the plugin itself.

## Observed in headless Chrome (localhost, compose project fh0109, port 18109)

- Application: /sw.js registered, scope `/`, state `activated`.
- Cache Storage: a single cache `workbox-precache-v2-...` holding only build assets, icons, offline.html and manifest. No pages or JSON.
- Offline navigation (nginx container stopped): the static trilingual offline page rendered.
- `curl -I`: `/sw.js` 200 `Cache-Control: no-cache`; `/` `Cache-Control: no-store, private`; page contains `rel="manifest"` and `apple-touch-icon`.

## Deviations from Plan

**1. [Rule 3 - Blocking] Icon generator output names.** `@vite-pwa/assets-generator` wrote `maskable-icon-512x512.png` and `icons/favicon.ico`; renamed/moved to the planned `maskable-512x512.png` and `public/favicon.ico` (steps noted in pwa-assets.config.js).

**2. Manifest location** handled in nginx, as the plan's fallback allowed (see key-decisions). No `Service-Worker-Allowed` header required.

**3. Test environment.** Fresh stack needed `php artisan migrate` (via fh_owner, as documented in compose.yaml) before smoke-local would return 200. Port 8080 was held by another stack, so the compose override lived in the scratchpad (no repo change). Stack removed with volumes afterwards. The test used a stale `app-CD94iIDR.css` still lying in the gitignored public/build; irrelevant to the guard.

## Needs owner / real devices (not self-approved)

- Real-device install on iPhone, Android and iPad, standalone launch, apple-touch-icon appearance, theme colour: planned for plan 01-12.
- Visual check of the update banner styling (only unit-tested; the "update available" flow with a real new deploy has not been exercised end to end) and of the maskable icon safe zone.
- Server nginx template `default.conf.template` already includes the changed snippet, but the live deploy has not been touched (no live-server changes).

## Known Stubs

None.

## Threat Flags

None. T-01-43 (guard + 8 unit cases), T-01-44 (middleware + 3 Pest tests), T-01-45 (no-cache sw.js, prompt update, user-triggered skipWaiting), T-01-46 (static, script-free offline.html) mitigated. Note: CSP `worker-src` from plan 01-04 was not re-checked here; worker registered fine under the dev nginx.

## Self-Check: PASSED

Commits 66e6ae4, dcd4761, 229b7cc exist; listed files present.
