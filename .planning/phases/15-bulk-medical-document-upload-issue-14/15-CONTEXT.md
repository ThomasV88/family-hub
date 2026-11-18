# Phase 15: Bulk medical document upload (issue #14) - Context

**Gathered:** 2026-11-18
**Status:** Ready for planning
**Source:** GitHub #14 (owner-written) + owner decisions of 2026-11-18

<domain>
## Phase Boundary
Upload many medical documents at once (e.g. 20), let the AI process them one by one, show each result, let the owner approve/reject each finding and press "Continue" or request a re-scan, with brightness/contrast/black-and-white adjustment before re-sending, and a session that survives reload and resumes exactly where it stopped.
</domain>

<decisions>
## Implementation Decisions (locked by owner, 2026-11-18)
- **Batch scope:** one batch belongs to one person (chosen on the person card); no per-document person.
- **Persistence:** server-side. Batch, per-document status, adjustments and approve/reject decisions live in the database; the same batch can be resumed from another device. Unfinished batches and their uploaded files auto-expire after **30 days**.
- **"Continue":** saves the approved findings of the current document to the records immediately, discards rejected ones, shows the next document. A document can be sent to re-scan at any time.
- **Adjustments (brightness, contrast, black-and-white):** applied only to the copy sent to the AI; the stored original is never modified; settings are saved with the document and remembered.
- **Quota:** when the AI quota is exhausted the batch pauses and resumes by itself (reuse Phase 9 quota/park logic); finished work is never lost.
- **Privacy:** access follows the person's record rules; existing AI consent and notice (Phase 13 text notice) apply; audit events; no document content in logs.
- Reuse, do not rebuild: Phase 8/9 AI run job, review-before-save flow, bulk guards; Phase 13 pre-processing evaluation (13-EVALUATION.md) to decide which adjustment is meaningful and how the copy is produced.

### Claude's Discretion
- Data model (batch + items tables), status machine, polling vs broadcast for live progress, UI layout (panel/stepper), file count/size limits (at least 20 files), duplicate handling, concurrency (strictly one document at a time), cancel/delete of a batch, per-document error and retry display, adjustment preview implementation (CSS filter for preview vs server-side GD for the AI copy).
- Open points the planner must resolve itself and record in the plan: max files/size per batch, behaviour when a file is unreadable or not a supported type, whether adjusting a document counts as a new AI run for the usage log, what happens to a batch when the person's AI consent is withdrawn (should purge texts/queued items, consistent with Phase 13).
</decisions>

<canonical_refs>
## Canonical References
- `.planning/ROADMAP.md` Phase 15, `.planning/REQUIREMENTS.md` BULK-01..09
- `.planning/phases/13-ai-full-text-ocr-and-document-search-issue-11/` (13-EVALUATION.md, summaries): pre-processing results, text storage, consent notice, bulk guards
- `.planning/phases/08-ai-extraction-platform/`, `.planning/phases/09-multi-agent-quota-and-bulk-regeneration/`, `.planning/phases/07-medical-records/`
- `CLAUDE.md` "User documentation (mandatory)": manual + help tips in en/sk/pl, coverage tests
- `.claude/skills/sketch-findings-family-hub/SKILL.md`
</canonical_refs>

<specifics>
- Commits resolving part of #14 carry `Fixes #14`.
- Staging uses synthetic data only; test the real AI flow on staging only.
</specifics>

<deferred>
## Deferred Ideas
- Per-document person assignment; automatic document-type/person detection; crop/rotate/deskew tools beyond brightness, contrast and black-and-white.
</deferred>

---
*Phase: 15-bulk-medical-document-upload-issue-14*
