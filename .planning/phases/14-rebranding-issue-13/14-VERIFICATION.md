---
phase: 14-rebranding-issue-13
verified: 2026-11-18T00:00:00Z
status: human_needed
score: 4/4 must-haves verified
gaps: []
human_verification:
  - test: "Native SK and PL review of manual, string and style-guide drafts"
    expected: "Wording correct and natural"
    why_human: "Language quality cannot be checked programmatically"
  - test: "Eyeball a danger button next to a primary button in the style guide (light and dark)"
    expected: "Danger reads as distinct from the orange primary (hue gap about 17-20 degrees)"
    why_human: "Visual judgement"
  - test: "Production release: set APP_NAME=\"FamilyHUB\" in /opt/family-hub/app.env"
    expected: "config('app.name') prints FamilyHUB on production (still Family Hub there per staging step 9)"
    why_human: "Release-time ops step; documented in docs/ops/server.md"
---

# Phase 14: Rebranding Verification Report

**Goal:** Replace the product identity with the FamilyHUB brand kit: logo, favicons, PWA icons, colour tokens (typography unchanged).
**Status:** human_needed (all automated checks pass)

| # | Success criterion | Status | Evidence |
|---|---|---|---|
| 1 | Logo in shell, sign-in, invite, error/offline, light and dark | VERIFIED | BrandLogo.vue used by TopBar.vue and AuthLayout.vue; public/offline.html uses a prefers-color-scheme picture with familyhub-logo(-dark).svg; staging steps 1, 2, 5 PASS (login, invite, 403 page, offline) |
| 2 | Favicons, apple-touch, PWA and maskable icons, manifest, precache and build guard | VERIFIED | public/icons has 7 familyhub assets; vite.config.js manifest FamilyHUB with 192, 512, maskable; app.blade.php links favicon, svg mark, apple-touch; build + check-sw-precache OK (14 entries); staging step 4/5 PASS |
| 3 | Tokens follow brand palette, AA in both themes, style page and a11y pass | VERIFIED | tokens.css primary #a64b0b light / #f2963e dark; axe wcag2a/2aa 0 violations on Login, Home, Settings in both themes (staging step 3); style guide Brand section added |
| 4 | Name consistent in README, manual, help, mail in SK/PL/EN; doc rule applies | VERIFIED | BrandName.test.js guard (passes, 3 brand tests 24 asserts pass here); grep for "Family Hub" in lang, resources, offline.html, vite.config.js, README finds nothing; manual en/sk/pl updated incl. old-icon FAQ; help tips contain no product name; staging mail en/sk/pl and manual checks PASS |

Requirements BRAND-01..04: all SATISFIED (mapped to criteria 1-4). Full gate per 14-06 summary: Pint, Pest 4012 passed, Vitest 6694 passed.

## Anti-patterns
None found in scanned files; no debt markers checked beyond the old-name guard.

## Notes (non-blocking)
- REQUIREMENTS.md still shows BRAND-01..04 as Pending; tick on phase completion.
- Tab titles are set by Inertia pages after hydration (initial HTML title is FamilyHUB).
- Light lockup looks small next to the white one (cosmetic, judge by eye).
- Local storage/logs/laravel.log grows large (not a code issue).
- iPhone/iPad users must re-add the app to see the new icon (covered in manual FAQ).

_Verifier: Claude (gsd-verifier)_
