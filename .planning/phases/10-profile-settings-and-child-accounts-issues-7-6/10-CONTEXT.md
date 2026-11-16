# Phase 10: Profile settings and child accounts - Context

**Gathered:** 2026-11-17
**Status:** Ready for planning
**Mode:** Owner decisions recorded, discuss not needed

<domain>
## Phase Boundary

See the phase goal and success criteria in `.planning/ROADMAP.md` (Phase 10). Source: the GitHub issues named there (owner-written text and comments).

</domain>

<decisions>
## Implementation Decisions

Locked by the owner or decided by Claude as documented in `.planning/ISSUES-6-11-OWNER-DECISIONS.md`:

- Child = a `users` row without e-mail (e-mail nullable, unique when present) plus `sign_in_disabled`. It can never authenticate (no password, reset, passkey, invitation) and never receives mail. Existing Child preset and `wishlist_managers` give a parent the management of the child's wish list.
- Admin creates the child directly (Settings > Members). Later conversion to a normal member: add e-mail and invite.
- A member edits own name and photo; a parent or administrator edits a child's name and photo. E-mail is shown, not editable.
- Profile photo: private storage, sanitised re-encode like `app/Wishlist/Catalog/ImageProcessor.php`, no metadata, never publicly reachable; avatars (`resources/js/Components/ui/UiAvatar.vue` and its users) show it everywhere.
- Account menu: new item "Profile settings" above "Settings".

### Rules that apply to every plan
- Every commit that resolves part of an issue carries `Fixes #N` (one trailer per issue); commits touch explicit paths only (never `git add -A`), no attribution lines, never touch `.planning/config.json`.
- CLAUDE.md "User documentation (mandatory)": manual topic and help tips in en, sk, pl (sk and pl are drafts for native review) and the coverage tests.
- Privacy: permissions enforced server-side, tests for hidden data; no secrets printed.
- Release: develop (staging) first with browser verification on synthetic data, then PR to main. The owner approves production.

### Claude's Discretion
Everything not listed above, following existing codebase conventions.

</decisions>

<code_context>
## Existing Code Insights

Gathered during plan-phase research.

</code_context>

<specifics>
## Specific Ideas

None beyond the issue text.

</specifics>

<deferred>
## Deferred Ideas

See "Suggestions recorded for the owner" in `.planning/ISSUES-6-11-OWNER-DECISIONS.md`.

</deferred>
