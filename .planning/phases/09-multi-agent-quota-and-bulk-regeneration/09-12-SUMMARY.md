---
phase: 09-multi-agent-quota-and-bulk-regeneration
plan: 12
subsystem: admin-settings
tags: [ai-agents, settings, access-gate, audit]
requires: ["09-01", "09-09"]
provides: [settings.ai_agents routes, eighth admin section, AiAgentsController]
affects: [09-13, 09-16]
key-files:
  created:
    - app/Http/Controllers/Settings/AiAgentsController.php
    - routes/web/settings-ai-agents.php
    - tests/Support/EndpointMatrix/settings-ai-agents.php
    - tests/Feature/Settings/AiAgentsPageTest.php
  modified:
    - app/Support/SettingsSections.php
    - tests/Support/SensitiveRoutes.php
    - tests/Unit/Arch/MedicalPrivacyArchTest.php
    - tests/Feature/Settings/MembersPageTest.php
    - tests/Feature/Audit/AuditPageTest.php
    - tests/Feature/Finance/AccountAdminTest.php
    - tests/Feature/Settings/AiCallsPageTest.php
    - tests/Feature/Notifications/NotificationSettingsTest.php
requirements: [AI-05]
duration: ~25 min
completed: 2026-11-14
---

# Phase 9 Plan 12: AI agents settings backend Summary

Admin-only Settings > AI agents backend: Inertia page props, JSON update and move endpoints (access:admin + access:recent + throttle 30/min), the eighth admin section, and the allowlists and pinned counts that go with it.

## Commits
- b526d85 feat(09-12): controller, routes, section entry, SensitiveRoutes, matrix, arch pin, page test, pinned counts

## What was built
- `index` renders `Settings/AiAgents` with sections, agents (key, enabled, position, model, health state and checkedAt, cooldownUntil only when in the future), providers and checkRoute.
- `update` (PATCH): 404 for an unknown agent, 422 `invalid_model` (regex = ModelId pattern, max 60, empty becomes null) or 422 `last_enabled`; audit happens inside the query transaction before the change (a failing audit logger gives 500 and nothing changes). `move` (POST): `in:up,down`, optional `expectedPosition`, 409 `stale` with current agents, no-op at an end returns 200. All JSON is `private, no-store`.
- SettingsSections: `ai_agents` after `ai_calls` (admin only). SensitiveRoutes: update and move in `accessGranting()`, prefix `settings.ai_agents.`, allowlist for the index. Matrix file with guest 302 / admin 200 / all presets 403.
- MedicalPrivacyArchTest: controller added to the queries allowlist and settings exemption (reason comment), plus `mdArchAiAgentsViolations` pin (allowed AiAgentSettingsQuery, AdminAiBulkQuery, AgentHealth, AgentRegistry, CheckLoginJob, ModelId; forbidden SubjectQuery and any Medical model) with mutation cases.
- Pinned counts: four tests 7 to 8; NotificationSettingsTest order shifted (ai_agents index 4).

## Decisions
- Update applies `enabled` first, then `model`, after validating the model up front, so a refused request never half-applies.
- Invalid model returns `{reason: 'invalid_model', agents}` (custom 422 shape, not Laravel's errors bag) per the contract.
- The 422/409 bodies also include the current `agents` so the page can resync.
- The audit logger is not listed in the arch allowed set regex check (it lives in App\Audit, not matched); the controller does not use it directly.

## Deviations from Plan
None. The matrix admin PATCH/POST rows mutate codex (model, move down); harmless under RefreshDatabase.

## Verification
Pest in project fh0912: AiAgentsPageTest, MedicalPrivacyArchTest, SensitiveGateMatrixTest, the five pinned-count tests, SettingsShellTest and SecurityPageTest: 95 passed. Pint clean. Project removed with `down -v`.

## Known Stubs
None. The Vue page `Settings/AiAgents` and lang keys (`settings.section.ai_agents`) arrive in 09-16.

## Self-Check: PASSED
