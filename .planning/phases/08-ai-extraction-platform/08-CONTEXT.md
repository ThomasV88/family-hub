# Phase 8: AI Extraction Platform - Context

**Gathered:** 2026-11-14
**Status:** Ready for planning

<domain>
## Phase Boundary

A member with Edit access to a person can, on demand and only when that person's AI-consent flag is on, have a medical document read by Codex CLI (ChatGPT subscription login) in a locked-down background worker. The result is a versioned, validated JSON proposal that is never applied to the record; the member reviews it side by side with the document and accepts, edits or rejects each entry. Every external AI call is logged and visible to admins. Requirements: AI-01, AI-02, AI-03, AI-04 (Codex only; Copilot, agent order, quota fallback and bulk re-generation are Phase 9).

Everything is built and tested against a faked CLI. No real AI call and no live Codex login happen until the shared live session at the end (deferred plan, like earlier phases).

</domain>

<decisions>
## Implementation Decisions

### What the AI extracts and how a proposal is shaped
- Extraction produces lists of proposed visits, medications, allergies and vaccinations (the Phase 7 record types), plus optional document date and specialty. No lab values, no free-form diagnosis beyond the existing visit fields.
- One JSON Schema with `schema_version`, passed to `codex exec --output-schema`. The server validates again and stores the schema version on the proposal. Failed validation = `failed` status, never a partial proposal.
- One `ai_extractions` row per run (status, schema version, payload JSON, document, person, agent, model). Never written to record tables until accepted. A re-run creates a new row and marks the previous one superseded.
- The user accepts, edits or rejects each proposed entry individually. Accepted entries are created through the normal Phase 7 record queries (same scoping, validation, audit) with AI-extracted provenance, carrying the Phase 7 "unverified" label rules until a member verifies them.

### Worker, Codex CLI and containment
- Separate `ai-worker` container with its own `ai` queue, non-root user, holding only the writable `codex-home` volume (refreshed `auth.json`) and read access to medical documents. The normal `worker` never gets CLI credentials; `ai-worker` gets no app secrets beyond queue/database access it needs.
- `codex exec --sandbox read-only --ephemeral --output-schema ... -o ...` in a fresh empty temp dir per job; document copy placed there, PDFs rasterized with `pdftoppm`, dir deleted afterwards. Success = output file exists and passes server-side validation, never exit code alone. Timeout kills the process group.
- Worker refuses to start if `OPENAI_API_KEY`, `CODEX_API_KEY` or `ANTHROPIC_API_KEY` is set (subscription-only cost rule). The prompt marks document content as untrusted data. No network tools, no MCP. Model output is data only and never reaches a shell or direct database write.
- Admin-visible login health via `codex login status` ("logged in / login expired"). One agent in Phase 8; an agent registry interface is built so Phase 9 adds Copilot, ordering and quota fallback without rework.

### Review screen and the call log
- Members who can Edit the person start extraction, only if the person's AI-consent flag is on, via an explicit "Run AI" button per document with a one-time notice that the document leaves the server for OpenAI. Consent is checked server-side at queue time and again when the job runs (turned off in between cancels the job).
- Review is side by side: document preview on one side, proposed entries on the other with accept / edit / reject; stacked with a toggle on phones. Nothing is saved until a user acts. Status (queued, running, ready, failed) shows on the document.
- Append-only `ai_calls` table: who started it, person, document, agent, model, status, duration, time. No document text, prompt or result content. A content-free audit row is also written.
- Admin-only "AI calls" section in Settings, filterable by person, member and status; admins see person names only there (Phase 7 rule). Rows older than 12 months are pruned.

### Claude's Discretion
Table and class naming, exact JSON Schema field names, prompt wording (must be SK/PL/EN-document tolerant, output in the source language), polling vs. push for status updates, temp dir location, UI details within the approved Phase 7 design system.

</decisions>

<code_context>
## Existing Code Insights

### Reusable Assets
- `app/Medical/` record queries (visits, medications, allergies, vaccinations), `MedicalDocumentStore`, `AiConsent::allows` (Phase 7-09), Provenance/unverified label (07-16), `Visibility::apply` per-person scoping, `requireAccess` and SensitiveGate, content-free audit events.
- Database queue and separate worker container pattern (Phases 3 and 4), `SafeFetcher`/image pipeline patterns, document preview routes (07-12), `UiDetailPanel` and Health UI components.
- `tests/Support/MedicalPrivacy` leak harness, endpoint matrix, arch allowlists.

### Established Patterns
- Behaviour gated server-side with one identical 403 for hidden/unknown ids; audit before state change; no-store on sensitive responses; settings sections for admin pages; SK/PL/EN lang keys with native-review flag.
- Tests run in containers; CLI processes faked in tests.

### Integration Points
- Document panel (Run AI action, status), person page, Settings (AI calls section, login health), compose files and Dockerfile (ai-worker, codex install pinned, poppler-utils), backups (codex-home must NOT be in plain backups), StagingAudit.

</code_context>

<specifics>
## Specific Ideas

Sketch 007 (specialty folders with timeline, per-person AI consent and review flow) is the visual direction. Owner's hard rules from PROJECT: subscription-only, no per-token API; medical documents leave the server for OpenAI only on an explicit per-document action.

</specifics>

<deferred>
## Deferred Ideas

- GitHub Copilot CLI, agent order, per-agent model setting, quota fallback and waiting: Phase 9.
- Bulk re-generation with dry run and daily cap: Phase 9.
- Lab value extraction and trend graphs: out of scope for v1.
- Real Codex login, live AI test on staging, data-controls/training check for the ChatGPT plan: deferred live plan at the end.

</deferred>
