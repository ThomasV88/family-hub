# Phase 11: Shopping lists (Nákupy) - Context

**Gathered:** 2026-11-17
**Status:** Ready for planning
**Mode:** Owner decisions recorded, discuss not needed

<domain>
## Phase Boundary

See the phase goal and success criteria in `.planning/ROADMAP.md` (Phase 11). Source: the GitHub issues named there (owner-written text and comments).

</domain>

<decisions>
## Implementation Decisions

Locked by the owner or decided by Claude as documented in `.planning/ISSUES-6-11-OWNER-DECISIONS.md`:

- New module `shopping` in `app/Access/Module.php`; default grants follow To-Do; not audited, not sensitive.
- Catalog items (name, image, default unit) and store chains (name, logo) are managed in the section's own settings page. Units: pcs, g, kg, ml, l, pack, bunch, tbsp, tsp, extendable by an administrator.
- A list: store, one responsible person (changeable), contributors (edit items), viewers (read only). Only the responsible person locks, unlocks and marks Bought/Unavailable. Locked lists: no add/remove/edit of items.
- Finish shopping appears when every item is Bought or Unavailable; the list moves to the archive. Unavailable items offer a new OPEN list with the same store, responsible person and rights. Archived or existing lists can be copied.
- E-mail: to any typed address (max 5 per send, per-user send limit), plain text with items, quantities, store and responsible person.
- Reminder: one date and time per list; push goes to the responsible person only. If the responsible person is removed the creator becomes responsible.

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
