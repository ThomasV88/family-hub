---
phase: 13-ai-full-text-ocr-and-document-search-issue-11
plan: 08
subsystem: medical-ai-bulk-backfill
tags: [ocr, bulk, backfill, privacy, quota, admin-ui, manual]
requirements: [GH11-SC6]
key-files:
  created:
    - database/migrations/2026_12_03_110000_add_no_text_scope_to_ai_bulk_tables.php
    - tests/Feature/Ai/BulkNoTextTest.php
  modified:
    - app/Medical/Queries/{AiBulkEligibility,AiBulkJobQuery,AdminAiBulkQuery}.php
    - app/Http/Controllers/Settings/AiAgentsController.php
    - tests/Unit/Arch/DocumentTextArchTest.php
    - resources/js/Settings/AiAgents/{BulkRegenPanel.vue,BulkHistoryTable.vue,useBulkStatus.js}
    - resources/js/Components/Settings/AuditTable.vue
    - lang/{en,sk,pl}.json
    - resources/manual/{en,sk,pl}/admin.json
    - tests/js/settings/{AiBulk,AuditHealth}.test.js
---

# Phase 13 Plan 08: Guarded text-only backfill (bulk scope no_text) Summary

The only way to give existing documents recognised text is now a bulk run with the scope `no_text`: each item is a `mode=text` run (13-06), under every existing bulk guard, refused while `ai.ocr.enabled` is false, and it never creates, supersedes or touches a proposal or an accepted decision.

## Commits
- 9fabad6 migration, eligibility, enqueue, ocr_off refusal, panel flag, `BulkNoTextTest`
- 8b48ee3 panel option, history tag, audit label, copy (en, sk, pl), admin manual, Vitest

## What changed
- Migration `2026_12_03_110000`: both scope CHECKs accept `no_text`; `down()` refuses while a dry run or run with that scope exists.
- `AiBulkEligibility`: scope `no_text` = no row in the text table; the `decided` bucket is left out for this scope (unsupported, consent, active still apply; archived stays excluded by the scope predicate); `skipped_decided` is therefore 0.
- `AiBulkJobQuery`: enqueue writes `mode` `text` for `no_text`, else `full` while `ai.ocr.enabled`, else `entries`; the enqueue-time "accepted decision skips the item" rule is not applied to `no_text` (nothing is replaced). A running `no_text` run is held (not ended, nothing enqueued) while the flag is off, so no document is sent while the feature is off.
- `AdminAiBulkQuery`: `dryRun` answers `['result' => 'ocr_off']` and writes no row or audit; `start` answers `ocr_off` before consuming the token; `panel()` carries `ocrEnabled`. Controller maps `ocr_off` to HTTP 422 `{reason: ocr_off}`.
- UI: radio order `no_proposal`, `no_text`, `last_failed`, `all`, `no_text` rendered only when `panel.ocrEnabled`, hint always visible, `row_decided` hidden and the info note shown for `no_text`, own zero line, note repeated in the Start confirm body, `Text only` `UiTag` with the `FileText` icon in history, audit table labels the scope. If reading is switched off while `no_text` is selected the panel falls back to the first scope. `useBulkStatus.adopt` keeps `ocrEnabled`.

## Tests
Isolated compose project `fh1308` (own Postgres, scratch folder over storage/logs, serial), removed with `down -v` at the end.
- `BulkNoTextTest`: 13 tests (CHECKs, bucket counts, `ocr_off` for dry run, start and HTTP with no row written, held run while off, end-to-end tick and job with the fake CLI for two documents (one with an accepted decision, one with an unreviewed ready proposal) showing status `text_saved`, text rows present, item outcome `read`, proposals, decisions and records unchanged and no extraction call logged, pacing/cap/start hour/single open run/pause/resume, quota parking with `waiting_quota` and no Copilot call, cool-down after `text_saved`, consent withdrawn before the job, cancel, mode `full`/`entries` for the other scopes, validation).
- Task 1 suites (BulkRun, BulkTick, AiPhase9EndToEnd, TextSavedStatus, MedicalPrivacy, Audit, Unit/Arch, Unit/Health): all green unchanged.
- Vitest full: 192 files, 6427 tests passed. Pest Manual and Help: 49 passed. Pint `--test`: pass (1236 files).
- Full Pest: 3972 passed, 1 skipped, 0 failed.

## Deviations
1. [Rule 3] The plan says the controller validates with `AiBulkEligibility::SCOPES`, but `MedicalPrivacyArchTest` pins `AiAgentsController` to name only `AdminAiBulkQuery` and the agent settings query (existing arch test must stay unchanged). Added `AdminAiBulkQuery::scopes()` returning `AiBulkEligibility::SCOPES`; the controller uses `Rule::in(AdminAiBulkQuery::scopes())`.
2. `tests/Support/MedicalPrivacy.php` needed no entry: its net is route-based (runtime statement capture), and `AiBulkEligibility` is not on a request path it captures; the repo scan rule is the arch allowlist, extended in `DocumentTextArchTest` (`DOC_TEXT_ARCH_TABLE_ALLOWED`). File not touched.
3. `AuditTable.vue` reuses `settings.ai_bulk.scope_no_text` (like the other scopes), so no extra audit key was added.
4. The "unsupported file type" bucket cannot be produced by the schema (kind CHECK allows only pdf, image, heic), so the test asserts 0 for it.
5. `useBulkStatus.js` (not in the plan's file list) had to carry `ocrEnabled` through `adopt`, otherwise polling would drop it.
6. Extra safety beyond the plan: the tick holds a `no_text` run while the flag is off (the plan only refuses new dry runs and starts).

## User documentation
Touched: `resources/manual/en/admin.json`, `resources/manual/sk/admin.json`, `resources/manual/pl/admin.json` (section `ai-agents`: one new list item for the scope, one sentence on AI calls per 4 pages plus one for the suggestions; same section ids and block counts). New keys under `settings.ai_bulk` in en, sk and pl: `scope_no_text`, `scope_no_text_hint`, `no_text_note`, `zero_eligible_no_text`, `history_text_only`. **sk and pl texts (lang keys and the manual) are drafts for native review.** The `settings.ai_bulk` help tip is not touched here (13-09 rewords it). ManualCoverage, HelpCoverage and Pest Manual/Help pass.

## Security notes
- Backfill stays behind: mandatory fresh dry run, guarded Start with the provider confirm, daily cap, spacing, start hour, one open run, frozen providers, Codex only unless `ai.bulk.fallback_agents`, quota parking, per-document cool-down (also after `text_saved`), per-person consent (checked at selection, at enqueue under a share lock and again in the job), `access:recent` on the routes (existing). The text notice acknowledgement is added by 13-09.
- Admin views show counts only; the panel, history and audit rows carry no document, person or text (history tag derives from the scope; Vitest and Pest privacy tests unchanged).
- Threat register T-13-43 to T-13-47 mitigated as planned.

## Known stubs
None.

## Final full Pest run
One clean serial run after both commits: 3972 passed, 1 skipped, 0 failed (36704 assertions, 608 s).

## Self-Check
Files and commits listed above exist (`git log` shows 9fabad6, 8b48ee3).
