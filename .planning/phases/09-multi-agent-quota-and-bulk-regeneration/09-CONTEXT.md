# Phase 9: Multi-Agent, Quota and Bulk Re-generation - Context

**Gathered:** 2026-11-14
**Status:** Ready for planning
**Discuss mode:** autonomous defaults (owner prefers no approval prompts, see memory feedback); every default below is PROVISIONAL and confirmed in the deferred live plan.

<domain>
## Phase Boundary

Admin controls which AI agents run and in what order (Codex, then GitHub Copilot), sets the model per agent and sees each agent's login health. When an agent's usage limit is exhausted, the job falls back to the next agent or waits until the limit resets, and the user sees the job's status. Admin can start bulk re-generation for past documents with a dry run first and a daily cap; it never overwrites data a human already accepted. Requirements: AI-05, AI-06, AI-07, and the Copilot part of AI-04.

Same rule as Phase 8: everything is built and tested against fake CLIs. No real Copilot login, no real AI call, no deployment until the shared live session (deferred last plan, like 08-22).

</domain>

<decisions>
## Implementation Decisions

### Agents, order, model, login health (AI-05, AI-04 Copilot part)
- A second agent `CopilotAgent` joins the Phase 8 `Agent` registry. GitHub Copilot CLI (`@github/copilot`, version pinned from research, installed only in the `ai-runtime`/ai-worker image next to Codex, never in web or worker images; package legitimacy evidence recorded in the plan SUMMARY, no approval prompt).
- Copilot invocation: `copilot -p` non-interactive, `--no-ask-user`, never `--allow-all-tools`, scrubbed `env -i` allowlist, throwaway work dir, images via `--attachment`, `-s` for clean output. Copilot has NO schema enforcement, so the prompt demands JSON, the server validates with the same `ExtractionValidator`, and one repair retry (a second call with the validation error codes only, never document content) is allowed per run. Success still means a validated output, never an exit code.
- Copilot login: device flow into a separate `copilot-home` volume mounted only in the ai-worker (like `codex-home`, never backed up, never in the app/worker). Preflight additionally refuses `GH_TOKEN`, `GITHUB_TOKEN`, `COPILOT_GITHUB_TOKEN` in the environment (subscription login only, no PAT in env, no additional-usage budget). Health check via the CLI's login/status command, same four states as Codex.
- Settings: the Phase 8 admin "AI calls" section gets a sibling admin section "AI agents" (admin only): per agent an enable switch, an order (move up / move down, no drag-only), a model field (empty = CLI default; validated identifier pattern) and the login-health card. State lives in a new `ai_agent_settings` table (enabled, position, model), editable only by admins, audited content-free (who, agent, changed field names, no values beyond enum/boolean/position and a validated model string).
- Defaults: Codex enabled at position 1; Copilot disabled until the admin enables it AND its login health is logged in (enabling is allowed while "Not checked yet", with a warning line).

### Quota, fallback and waiting (AI-06)
- Quota exhaustion is detected per agent from validated failure classes (`quota_exhausted`, distinct from `auth_expired` and `invalid_output`) using conservative output signatures; unknown failures are NOT treated as quota (fail, don't loop). Signatures are config-driven and flagged ASSUMED until captured live (live checklist item: capture a real exhausted-limit transcript for each agent).
- On quota exhaustion the agent gets `cooldown_until` (parsed reset time if the CLI prints one, else a configured default, e.g. 60 minutes for Codex's rolling window, longer ladder up to 24h on repeated hits). The job falls back to the next enabled, healthy, not-cooling-down agent in order. Each attempt is its own `ai_calls` row (agent and model recorded per attempt); the extraction row records the agent that produced the result.
- If every agent is cooling down or unavailable, the extraction goes to a new status `waiting` (queued with `available_at` = earliest reset) rather than failing; the job is released back to the queue with that delay (no tight retries, no per-token billing paths). Cap: after 24 hours waiting it fails with a clear code. A user can cancel a waiting run.
- User-visible: the document status tag and review/poll payload gain "Waiting for AI quota, retrying after HH:MM" (copy SK/PL/EN, native review flagged), plus which agent produced a ready result (provenance line "Read by <agent> <model>", content-free). No new polling mechanism: extend the Phase 8 status route.
- Consent and access rechecks, work-dir lifecycle, caps and the Phase 8 review fixes (WR-01 atomic finish, WR-07 run limits) apply unchanged across fallback attempts; a fallback attempt re-verifies consent and the starter's access before spawning the next agent.

### Bulk re-generation (AI-07)
- Admin-only "Bulk re-generation" panel in the AI agents section: choose scope (documents without any proposal / documents whose latest proposal failed / all documents) and the per-day cap; first a mandatory DRY RUN that returns counts only (documents eligible, skipped: consent off, hidden-from-nobody irrelevant, HEIC/unsupported, has human-accepted entries, already active) with no names, titles or content, then an explicit "Start" that references the dry-run token.
- Daily cap: config default 20 runs/day (admin can lower, not above a config max), enforced by a scheduler that queues the next batch each day after a configurable off-peak hour; spacing between runs. Respects the Phase 8 per-user/global/per-document limits and quota cooldown/waiting. Cancel-all and pause controls.
- Never overwrites accepted data: bulk runs only create new proposals and never touch record tables; documents that already have a proposal with any human-accepted entry are SKIPPED by default (and the new proposal never supersedes one with accepted entries). Persons whose AI consent is off are never processed (consent rechecked at run time).
- Privacy: the admin sees counts and status only, never person names, document titles or proposal content for people they cannot otherwise see; a bulk run is a system-initiated run on behalf of the admin, audited content-free (admin id, scope, counts) plus the normal per-run `ai_calls` rows with `started_by` = the admin and a `bulk_run_id`.

### Claude's Discretion
Table and class names, migration order, exact config keys, signature patterns, copy wording within the Phase 8 UI contract (new strings flagged for native SK/PL review), batch scheduling mechanics.

</decisions>

<code_context>
## Existing Code Insights

### Reusable Assets
- `app/Medical/Ai/Agents/` (Agent, AgentInput, AgentOutcome, AgentRegistry, CodexAgent), `CodexRunner`/`ProcessResult`/`FakeCodex`, `AgentHealth`/`CheckLoginJob` (ai-control queue), `RunExtractionJob`, `AiJobQuery`, `AiRunQuery`, `AiReviewQuery`, `AdminAiCallQuery`, `ai:reap-stuck`/`ai:prune-calls`, Preflight, WorkDir, ExtractionValidator/PromptBuilder, settings AiCalls UI (login health card, filters, table), AiStatusTag + polling, `AiPrivacyDifferentialTest`, MedicalPrivacy harness, `check-compose.sh`.

### Established Patterns
- Behaviour behind server-side checks with identical 403s; audit before state change; content-free logs and audit; fake CLI for tests; compose assertions for credential-volume isolation; admin sections test count and arch allowlists extended deliberately; lang keys in lang/en.json, sk.json, pl.json with native-review flag.

### Integration Points
- Agent registry and `RunExtractionJob` (fallback loop), `ai_calls` (per attempt), `medical_ai_extractions` (status `waiting`, `available_at`, agent), settings admin sections (8th section), ai-worker image/compose (copilot-home), preflight, backups (copilot-home never backed up), live checklist (Copilot login, quota transcripts).

</code_context>

<specifics>
## Specific Ideas

CLAUDE.md PART 1: Codex primary, Copilot secondary fallback with validation and repair loop; no API keys in env; set Copilot's "Additional usage" budget to $0 (live checklist); Claude Code CLI and Antigravity excluded from v1.

</specifics>

<deferred>
## Deferred Ideas

- Claude Code and Antigravity agents (excluded for ToS reasons).
- Per-person daily AI limits, cost dashboards, auto-learning of reset windows.
- Real Copilot login, real quota transcripts, live bulk run on staging: deferred live plan at the end.

</deferred>
