# Phase 14: Rebranding (issue #13) - Context

**Gathered:** 2026-11-18
**Status:** Ready for planning
**Source:** GitHub #13 + owner scoping answer (discuss skipped, `workflow.skip_discuss: true`)

<domain>
## Phase Boundary
Replace the product identity with the supplied FamilyHUB brand kit. Scope chosen by the owner: **logo + icons + colours**. Typography stays as it is.
</domain>

<decisions>
## Implementation Decisions
- Brand kit is committed at `.planning/phases/14-rebranding-issue-13/brand-kit/` (logo SVGs, png exports, favicons, brand-guide PDF). Use these as the source of truth; copy what the app needs into `public/` / `resources/`.
- Palette read from the logo SVGs: orange `#f2963e`, red `#e54738`, blue `#1c4e9a`, coral `#e8897a`, peach `#ffe1c8`. Logo variants: `primary`, `primary-on-transparent`, `icon`, `wordmark`, `mono-for-light-bg` (black), `mono-for-dark-bg` (white).
- Colour tokens (`resources/css/tokens.css`) follow the palette but must keep WCAG AA in light and dark: raw orange `#f2963e` on white is below 3:1, so derive AA-safe text/interactive tokens (do not use raw orange for text or small controls). Verify with the existing contrast tests / a11y sweep.
- Typography unchanged (`--font-sans` stays).
- Favicons / apple-touch / PWA icons (incl. maskable 512) come from the kit `favicon/` and `png/` files; manifest and `public/icons/` updated; service worker precache and its build guard must still pass; cache-bust so installed PWAs pick up the new icons.
- Product name: the logo reads "FamilyHUB". Replace user-visible "Family Hub" consistently (app shell, sign-in, invite page, offline page, mail templates, manual, help, README) in SK/PL/EN. Keep internal identifiers (container names, DB, repo, `fh-*` aliases, env) unchanged.

### Claude's Discretion
- Which logo variant goes where (light/dark theme switching), sizes, and the exact token derivation.
- Whether the brand-guide PDF (not renderable on this machine: no poppler) adds rules; the planner should include a task to read it (e.g. install poppler-utils or render it elsewhere) and reconcile with the SVG-derived palette.
</decisions>

<canonical_refs>
## Canonical References
- `.planning/ROADMAP.md` Phase 14 section; `.planning/REQUIREMENTS.md` BRAND-01..04
- `CLAUDE.md` "User documentation (mandatory)" rule: manual + help in en/sk/pl, coverage tests
- `resources/css/tokens.css`, `resources/js/Layouts/AuthLayout.vue`, `resources/views/app.blade.php`, `resources/js/sw.js`, `public/icons/`, `public/offline.html`, `vite.config.js` (PWA manifest)
- `.claude/skills/sketch-findings-family-hub/SKILL.md` (design direction)
- `tests/js/StyleGuide.test.js`, `tests/js/a11y-sweep.test.js`, `tests/js/shell/AppShell.test.js`
</canonical_refs>

<specifics>
- Commits resolving part of #13 carry `Fixes #13`.
</specifics>

<deferred>
## Deferred Ideas
- Typography change, component redesign, social-share images (kit `*-social-1080.png`) unless trivially useful.
</deferred>

---
*Phase: 14-rebranding-issue-13*
