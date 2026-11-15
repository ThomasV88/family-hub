---
phase: 09-multi-agent-quota-and-bulk-regeneration
verified: 2026-11-14T17:00:00Z
status: human_needed
score: 3/3 roadmap success criteria verified in code and tests (fake CLIs); live Copilot, real quota transcripts and bulk-on-staging deferred
gaps: []
human_verification:
  - test: "Plan 09-20 live ops: owner decisions A10, G, A9, real `copilot login --device-code` per stack, L3 tool-denial gate on a synthetic injection document, live checks L1-L8, one real run per agent, fallback with one agent made unusable, tiny real bulk run on staging, release"
    expected: "Copilot health shows Logged in; the tool-denial flags hold on a live call (no tool runs); fallback and 'Read by' provenance appear; bulk dry run counts only, cap 1, pause/resume/cancel work; copilot-home mounted only in ai-worker and absent from backups on the server"
    why_human: "Needs the live server, real GitHub and ChatGPT logins and the owner; deferred by owner (DEFERRED.md). Only FakeCodex and FakeCopilot were exercised."
  - test: "Real usage-limit and expired-login transcripts per agent (L4)"
    expected: "ai.quota.signatures and ai.copilot.auth_failure_patterns frozen from real output; today they are ASSUMED values"
    why_human: "Real quota exhaustion cannot be produced from code"
  - test: "WR-04 follow-up / WR-06 / IN-06: separate uids for the CLIs, container hardening; review findings WR-02 (provider-specific consent), access:recent re-check during multi-day bulk, AI calls person-filter dropdown"
    expected: "Owner decision recorded; `/copilot` unreadable from the Codex process after the uid split"
    why_human: "Product and infrastructure decisions routed to the owner in DEFERRED.md; need a root-capable container"
  - test: "Native SK and PL review of Phase 9 copy, including Polish plural agreement of the four-segment bulk strings; real-device pass of Settings > AI agents and the waiting status"
    expected: "Copy reads correctly for every count class; layout usable on phone, iPad, desktop"
    why_human: "Native-language review and real devices"
---

# Phase 9: Multi-Agent, Quota and Bulk Re-generation Verification Report

**Phase Goal:** Admin controls which AI agents are used and in what order, extraction survives exhausted usage limits, and past documents can be re-processed safely in bulk.
**Status:** human_needed (no code gaps; live-server, real-login and device items deferred by owner)
**Re-verification:** No, initial verification

## Automated runs (executed by the verifier, not taken from SUMMARY)

| Check | Command | Result |
|---|---|---|
| Full Pest | `docker compose -p fh0922v run --rm app php -d memory_limit=1G ./vendor/bin/pest` | 2632 passed (28274 assertions), 254.1 s, exit 0, no failures |
| Build | `npm run build` (node:22-alpine) | exit 0, SW-PRECACHE OK |
| Full Vitest | `node:22-alpine`, `npx vitest run --testTimeout=30000`, after the build | 135 files, 4754 tests passed, exit 0 |
| Compose check | `scripts/check-compose.sh` | CHECK-COMPOSE OK, exit 0 |
| Deploy guards | `scripts/test-deploy-guards.sh` | 25 cases passed, 0 failed, GUARDS OK, exit 0 |
| Cleanup | `docker compose -p fh0922v down -v` | containers, network and volumes removed; working tree clean before this report |

## Observable Truths (ROADMAP success criteria)

| # | Truth | Status | Evidence |
|---|---|---|---|
| 1 | Admin sets agent order (Codex, then Copilot) and model per agent, sees each agent's login health | VERIFIED in code (live login human_needed) | `routes/web/settings-ai-agents.php`: `GET /settings/ai-agents`, `PATCH /{agent}` (enable, model) and `POST /{agent}/move` (order), all `access:admin` plus `access:recent` and throttled. `AiAgentsController` backed by `AiAgentSettingsQuery` and table `ai_agent_settings`. `config('ai.agents')` is `['codex','copilot']`. `AgentRegistry::enabled()` reads saved order fresh on every call and builds each agent with its saved model (`make()`); model ids pass `ModelId` rule. UI: `Pages/Settings/AiAgents.vue`, `AgentCard.vue` embeds `AiLoginHealthCard` per agent with `agent.health` state; `CopilotRunner::loginStatus()` reads the local credential marker (research C1: no status command exists) and `CodexRunner::loginStatus()` for Codex. Pest and Vitest suites pass. |
| 2 | On exhausted usage limit the job falls back to the next agent or waits until reset; user sees status | VERIFIED in code (real quota transcripts human_needed) | `RunExtractionJob::run` loops `AgentRegistry::nextCandidate` (skips cooling-down, ceiling-reached and non-runnable agents). On `QuotaExhausted` it calls `AgentHealth::recordQuotaHit` (cooldown ladder) and tries the next agent; `Auth` also falls through (C4); all other failures are terminal. With no candidate `noCandidate()` fails `notice_required`/`no_agent`/`quota_exhausted` or `parkWaiting` until `earliestResume`, capped at 24 h (`quota_timeout`). `waiting` has DB CHECK `medical_ai_extractions_status_check` and `medical_ai_extractions_waiting_check` (`(available_at IS NOT NULL) = (status = 'waiting')`), plus a unique-active index including `waiting`. Resumer: `ai:resume-waiting` scheduled every minute in `routes/console.php`, dispatches due rows to the ai or ai-bulk queue, `rewait` on dispatch failure. User visibility: `AiRunQuery` treats `waiting` as active and as cancellable; `health/Ai/AiStatusTag.vue`, `useAiStatus.js`, `AiReview.vue`, `DocumentUploader.vue` render the waiting status; AI calls shows attempt rows with the "Usage limit reached" tag and filter. |
| 3 | Admin starts bulk re-generation with dry run first and a daily cap; never overwrites human-accepted data | VERIFIED in code (tiny real run human_needed) | Routes `bulk/dry-run`, `bulk` (start), pause, resume, cancel, all `access:admin` with `access:recent` on mutations. `AdminAiBulkQuery::start` requires a stored single-use dry-run token (`ai_bulk_dry_runs`, owner match, `consumed_at` null, 15-minute TTL), re-counts eligibility (`stale` on drift), requires acknowledgment and the shown provider set equal to the enabled set under an advisory lock, then inserts the run with `daily_cap` copied from the dry run and the frozen `providers`. `AiBulkJobQuery::tick` (scheduled every 5 minutes, advisory-locked, single flight) enqueues at most one document per tick, gated by local start hour, `enqueued_today < daily_cap`, spacing and a global active headroom. Never overwrites accepted data, three layers: `AiBulkEligibility::bucket()` puts documents with an `accepted` decision in `decided` (skipped), `AiJobQuery::finishReady` re-checks `hasAcceptedDecision` inside the row-locked transaction for bulk rows and closes the row `cancelled/decided` instead of superseding, and the bulk job never touches record tables (grep of bulk query classes finds no `visits/medications/allergies/vaccinations` writes; only `medical_ai_extractions`, `ai_calls`, `ai_bulk_*`). |

**Score:** 3/3 truths verified in code

## Integration points and security promises

| Check | Status | Evidence |
|---|---|---|
| No document reaches an unacknowledged provider: start, fallback, resume, repair, bulk | VERIFIED | Start: `AiRunQuery::start` compares enabled to acked providers and records the shown set. Fallback and resume: `RunExtractionJob` calls `AiJobQuery::allowedProviders` before every attempt (resume re-enters `run`) and passes it to `nextCandidate`/`earliestResume`; `noCandidate` fails `notice_required` if no enabled provider is acknowledged, so nothing is sent. Repair: the one repair call lives inside `CopilotAgent` and re-uses the same agent already allowed for that attempt (same provider, same images). Bulk: provider set frozen in `ai_bulk_runs.providers` at Start (verified equal to the dialog's set under lock); the job and the tick read the run's frozen copy, never the live acknowledgment. |
| Copilot only in the ai-worker image; `copilot-home` isolated and out of backups | VERIFIED (server state human_needed) | `docker/app/Dockerfile`: single `COPY --from=copilot` placed after the `ai-runtime` stage start. `scripts/check-compose.sh` asserts `copilot-home` at `/copilot` only on `ai-worker`, no other service mounts it, ai-worker mounts only codex-home/copilot-home/medical, no backup script mentions either credential volume, and the dev equivalents. Check passed. |
| Copilot tool-denial flags present, `--allow-all-tools` absent | VERIFIED in code (live gate L3 human_needed) | `CopilotRunner::exec` args: `--available-tools` (empty list), `--deny-tool=shell/write/read/url/memory`, `--disable-builtin-mcps`, `--disable-mcp-server github-mcp-server`, `--no-ask-user`, `--no-custom-instructions`, `--disallow-temp-dir`, `--max-ai-credits`. Env is `env -i` allowlist, per-run throwaway home. grep for `allow-all`/`allow_all` across app, config, scripts, docker finds nothing. Whether the flags hold on a live call needs the live session. |
| Quota signatures matched on stderr only, with no valid output | VERIFIED (one note) | Copilot: `QuotaSignatures::match` runs on `$errWindow` only and only when exit is non-zero; `CopilotAgent::map` returns a valid reply first, then quota only if the run failed. Codex: `CodexAgent` validates the result file first and treats quota only when exit is non-zero; the runner scans stderr, plus CLI-generated `error`/`turn.failed` JSONL events on stdout (not model message text). Note: Codex's stdout event scan goes slightly beyond "stderr only" but is restricted to CLI error event types and still needs a failing exit and no valid output. |
| `waiting` DB CHECK and resumer | VERIFIED | See truth 2. |
| Bulk never writes record tables | VERIFIED | See truth 3; accept path remains the human review path only. |
| Admin sees counts only for bulk rows | VERIFIED | `AdminAiCallQuery`: person name selected only when `bulk_run_id IS NULL`, `is_bulk` flag exposed, person filter adds `whereNull('bulk_run_id')`. `AiRunAudit::meta` gives bulk rows `['bulk' => true]` with no subject or document id; run id and counts live on `AiBulkRunChanged`. Residual (owner decision, DEFERRED.md): the person filter dropdown still lists people. |
| AI-04 Copilot part | CODE-COMPLETE, awaiting live verification | `CopilotAgent`, `CopilotRunner`, preflight refusing GitHub tokens, image, compose and tests are done. AI-04 is complete only when 08-22 and 09-20 are both done. |

## Research overrides C1-C7

| Override | Status | Evidence |
|---|---|---|
| C1 no Copilot status command, health from credential marker plus outcomes | VERIFIED | `CopilotRunner::loginStatus` reads `config.json` keys (JSONC) and never runs the CLI |
| C2 DB `waiting` plus scheduled resumer | VERIFIED | migration `2026_11_21_110000`, `AiResumeWaiting`, schedule |
| C3 `quota_timeout`, `quota_exhausted`, `no_agent` | VERIFIED | `FailureCode`, `RunExtractionJob::noCandidate` |
| C4 fall through on auth as well as quota | VERIFIED | `RunExtractionJob::run` branch on `Auth` |
| C5 stored single-use dry-run row, 15 min, 409/410 | VERIFIED | `AdminAiBulkQuery::start`, `ai_bulk_dry_runs`, `ai.bulk.dry_run_ttl_minutes` |
| C6 bulk run-time recheck (still admin, run not cancelled, consent on) | VERIFIED | `RunExtractionJob::accessProblem` bulk branch; tick `starterIsAdmin` |
| C7 per-member provider notice (`ai_notice_acks.providers`), status payload, call filter | VERIFIED | `AiJobQuery::ackedProviders`, `AiRunQuery`, `AdminAiCallQuery` |

## Requirements Coverage

| Requirement | Status | Evidence |
|---|---|---|
| AI-05 agent order, model, login health | SATISFIED in code (live login human_needed) | Truth 1 |
| AI-06 fallback or wait, user sees status | SATISFIED in code (real quota transcript human_needed) | Truth 2 |
| AI-07 bulk with dry run, daily cap, no overwrite of accepted data | SATISFIED in code (tiny real run human_needed) | Truth 3 |
| AI-04 (Copilot part) | Code-complete, awaiting live verification (09-20 and 08-22) | Integration table |
| Orphaned requirements | none | REQUIREMENTS.md maps only AI-05..AI-07 (and AI-04 from Phase 8) to these phases; all left unticked until 09-20 by owner decision |

## Anti-patterns

No TBD/FIXME/XXX markers in Phase 9 PHP, JS or migration files. Known, documented limitations (warnings for the live session, not gaps): `/copilot` readable by the Codex process (same uid, WR-04 follow-up); WR-02 consent not provider-specific (a bulk Start acknowledgment releases consenting people's documents to the acknowledged providers); `access:recent` checked only at bulk Start; AI calls person dropdown; ASSUMED quota and auth signatures; PROVISIONAL defaults in DEFERRED.md.

## Gaps Summary

No code gaps. All automated checks ran green in this verification. Status is `human_needed` because plan 09-20 (real Copilot login per stack, live checks L1-L8 including the tool-denial gate, real quota transcripts, one real run per agent, tiny bulk run on staging, deployment and owner decisions A10, G, A9) is deliberately deferred by owner decision. Do not push `develop` or `main` before that session.

---

_Verified: 2026-11-14_
_Verifier: Claude (gsd-verifier)_
