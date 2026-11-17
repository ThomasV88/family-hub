# Phase 13: AI full-text OCR and document search - Context

**Gathered:** 2026-11-17
**Status:** Ready for planning
**Mode:** Owner decisions recorded, discuss not needed

<domain>
## Phase Boundary

See the phase goal and success criteria in `.planning/ROADMAP.md` (Phase 13). Source: the GitHub issues named there (owner-written text and comments).

</domain>

<decisions>
## Implementation Decisions

Locked by the owner or decided by Claude as documented in `.planning/ISSUES-6-11-OWNER-DECISIONS.md`:

- Evaluate first: how images are read today, whether Codex adjusts contrast itself, and whether pre-processing (contrast, denoise, deskew, sharpen, grayscale) measurably helps on a labelled synthetic set of degraded copies (rotated, blurred, low contrast, noisy). Keep pre-processing only if it measurably improves accuracy; report accuracy, time and subscription-quota cost.
- Two-step reading: transcribe all text, store the complete recognised text in its own table (plain text, searchable with Postgres full-text search and unaccent for sk, pl, en), then extract the structured JSON from that text. Prompt and schema version bump.
- Same access as the document: hidden for archived documents and hidden persons, deleted with the document, never in exports, logs or the AI calls table. Search shows only documents the viewer may see.
- Notice text and manual updated. Backfill of existing documents only through the existing guarded bulk run.

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
