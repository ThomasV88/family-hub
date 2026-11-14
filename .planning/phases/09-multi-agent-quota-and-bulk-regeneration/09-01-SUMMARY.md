---
phase: 09-multi-agent-quota-and-bulk-regeneration
plan: 01
subsystem: i18n, audit
tags: [i18n, vue-i18n, audit, plurals, ai-agents, bulk-regeneration]
requires: []
provides:
  - "149 Phase 9 copy keys in en, sk, pl (agents, waiting, provenance, provider lines and notices, bulk panel, AI calls additions, audit details)"
  - "AuditEvent::AiAgentSettingChanged (agent, field, value) and AuditEvent::AiBulkRunChanged (action, scope, count), group health, no subject"
  - "AuditTable.vue rendering of both events"
affects: [09-02, 09-03, 09-04, 09-05, 09-15, 09-16]
tech-stack:
  added: []
  patterns: ["allowlisted meta mapped to translated labels in AuditTable details()", "pipe plurals, EN 2 and SK/PL 4 segments"]
key-files:
  created: [tests/js/i18n-phase9.test.js, tests/Feature/Audit/AuditAiAgentEventsTest.php]
  modified: [lang/en.json, lang/sk.json, lang/pl.json, app/Audit/AuditEvent.php, resources/js/Components/Settings/AuditTable.vue, tests/js/settings/AuditHealth.test.js]
decisions:
  - "Model rule copy follows the server rule: letters, numbers, dots, dashes or colons, up to 60 characters (no slashes)"
  - "Audit scope meta values are no_proposal, last_failed, all (map to settings.ai_bulk.scope_*); agent values are codex, copilot; agent field values enabled, position, model; bulk actions dry_run, started, paused, resumed, cancelled, completed"
  - "Bulk audit detail lines avoid plural grammar: 'Documents: {count}'"
  - "valid_minutes is the plural key; checked_at carries only the time"
metrics:
  tasks: 2
  completed: 2026-11-14
---

# Phase 9 Plan 01: Phase 9 copy and AI audit events Summary

All Phase 9 strings in EN, SK and PL with parity and plural tests, plus two content-free audit events (AI agent setting changes and bulk-run actions) that render on the audit page.

## Commits

- 6b6462f feat(09-01): Phase 9 copy in EN, SK and PL with parity test
- 79a5bf1 feat(09-01): content-free audit events for AI agent settings and bulk runs

## Verification

- Vitest `i18n-phase9` and `i18n-phase8`: 197 passed (phase 9 file: 91 tests, includes sk/pl plural picks for 0, 1, 2, 4, 5, 12, 22, 25 on all four plural keys).
- Vitest `AuditHealth.test.js`: 14 passed (new rows, `<b>` model rendered literally, off-allowlist meta prints nothing, sk/pl and axe).
- Pest `AuditAiAgentEventsTest`, `AuditLogTest`, `MedicalLangTest`: 40 passed. Exhaustive `group()` loop over all cases passes. Pint clean.

## Plural keys (EN 2, SK/PL 4 segments)

`settings.ai_bulk.days_estimate`, `settings.ai_bulk.valid_minutes`, `settings.ai_bulk.confirm_body`, `settings.ai_bulk.progress`. Call sites: `t(key, { n, ... }, n)`. Progress uses `{done}` and `{n}` (n = total).

## Deviations from Plan

- "Checking..." became "Checking" (project rule: no ellipses; the Phase 8 test enforces it, and the Phase 9 test applies the same rule). SK/PL likewise.
- UI-SPEC row text "Skipped: already has suggestions you accepted from" is truncated in the spec; used "Skipped: already has suggestions you accepted".
- Audit detail wording and the mapping of meta values (see decisions) were chosen here; the plan only fixed the key names.
- `AiBulkRunChanged` rows with unknown action fall back to the "started" line and unknown scope prints an empty scope; no raw meta is ever printed.
- The plan said an extra meta key is "dropped"; AuditLogger actually rejects it by throwing (existing behavior, nothing written). The test asserts the throw.
- Task 2 needed no change to `MedicalLangTest`; it passes unchanged.

## New SK/PL strings: native review pending

Every new SK and PL string in the 149 keys is a draft and is native review pending. Highest-risk ones, which had no UI-SPEC draft and were written here:
`settings.ai_agents.model_error` (tightened rule), `settings.ai_agents.open_calls`, `settings.ai_bulk.already_active`, `settings.ai_bulk.try_again`, `settings.ai_bulk.pause_name`, `settings.ai_bulk.resume_name`, `settings.ai_bulk.valid_minutes`, `settings.ai_bulk.row_*`, `health.ai.status.quota_exhausted_detail`, `no_agent_detail`, `notice_required_detail`, `health.ai.notice.github_title`, `github_body`, `settings.ai_calls.person_bulk`, and all `audit.detail.ai_*` lines. PL plural agreement in `confirm_body`, `progress` and `valid_minutes` also needs review.

## Known Stubs

None.

## Threat Flags

None. T-09-01 (meta whitelist, no subject) and T-09-02 (text interpolation only) are covered by tests.

## Self-Check: PASSED

Files and commits 6b6462f, 79a5bf1 exist.
