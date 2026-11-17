# Phase 12: Recipe book (Receptár) - Context

**Gathered:** 2026-11-17
**Status:** Ready for planning
**Mode:** Owner decisions recorded, discuss not needed

<domain>
## Phase Boundary

See the phase goal and success criteria in `.planning/ROADMAP.md` (Phase 12). Source: the GitHub issues named there (owner-written text and comments).

</domain>

<decisions>
## Implementation Decisions

Locked by the owner or decided by Claude as documented in `.planning/ISSUES-6-11-OWNER-DECISIONS.md`:

- New module `recipes`. Private (author only) or shared (all members who have the module). Only the author edits.
- Create manually or from a URL: the app fetches the page with `app/Wishlist/Fetch/SafeFetcher.php` (SSRF-safe), reduces it to text and the AI agent returns structured fields. The source URL is kept. All values are reviewed and edited before saving. A one-time notice says the page text goes to the AI provider; no separate consent switch (administrator turns the agents on).
- Photos with one main photo; overview grid at most 3 cards per row; detail with structured ingredients (name, quantity, unit) and steps; ticked ingredients and done steps are stored per member.
- Link to Shopping: create a new list or add to an open one, review before confirming, match catalog items when possible else manual items.
- v1 extras: servings with recalculation, preparation time, categories, favourites, search and filter, personal notes. Deferred: cooking history, meal planning.

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
