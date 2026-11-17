---
phase: 13-ai-full-text-ocr-and-document-search-issue-11
plan: 09
subsystem: medical-ai-notice-consent-states
tags: [ocr, notice, consent, privacy, ui, manual, help]
requirements: [GH11-SC5, GH11-SC6]
key-files:
  created:
    - database/migrations/2026_12_03_120000_add_text_notice_to_ai_notice_acks.php
    - tests/Feature/Ai/TextNoticeAckTest.php
  modified:
    - app/Medical/Queries/{AiRunQuery,AdminAiBulkQuery}.php
    - app/Http/Controllers/Settings/AiAgentsController.php
    - app/Http/Controllers/Health/AiRunController.php
    - app/Medical/PersonPage.php
    - resources/js/Health/Ai/{AiStatusTag,RunAiControl,AiNoticeDialog}.vue
    - resources/js/Health/{AiConsentControl,PersonHeader,DocumentPanel}.vue
    - resources/js/Health/Text/RecognisedText.vue
    - resources/js/Pages/Health/Person.vue
    - resources/js/Settings/AiAgents/{BulkRegenPanel.vue,useBulkStatus.js}
    - lang/{en,sk,pl}.json
    - resources/help/{en,sk,pl}.json
    - resources/manual/{en,sk,pl}/{health,admin}.json
    - tests/Feature/Ai/BulkNoTextTest.php
    - tests/js/health/{RunAi,AiConsent,RecognisedText,AiStatusPolling}.test.js
    - tests/js/settings/AiBulk.test.js
---

# Phase 13 Plan 09: Notice, consent and states Summary

While `ai.ocr.enabled` is true a member (and an administrator starting a bulk run) must acknowledge, once, a notice that says the document is sent in two steps and that the recognised text is stored in this app, searchable by members who may open the document, kept until the document or the text is removed, in backups until they expire. The acknowledgment is a separate per-member column, so the medical provider set and the recipe acknowledgment cannot satisfy it. With the flag false nothing changes.

## Commits
- 1edb0c4 migration `text_notice_acked_at`, `AiRunQuery` and `AdminAiBulkQuery` rules, `PersonPage` `ai.textNotice`, `TextNoticeAckTest` (12 tests), `BulkNoTextTest` helper passes `textNotice`
- 08ee149 notice variants, run states, consent turn-off confirmation, bulk acknowledgment, copy in en/sk/pl, Vitest
- d6cbd09 409 body carries `textNotice` only while the switch is on (existing exact-JSON test stays unchanged)
- 91c08e2 manual `ai`, `recognised-text` note, admin `backups`, help tips `health.ai_run` and `settings.ai_bulk`

## Server rules
- `noticeAcknowledged`: as before, and with the flag on `text_notice_acked_at IS NOT NULL`. `start()` upsert also sets the column when the flag is on; provider-set comparison unchanged.
- `AdminAiBulkQuery::start(..., bool $textNotice = false)`: flag on, column null, `textNotice` not true: `notice_required` (HTTP 409) before anything is written or the token consumed. Success with the flag on stores the column. `panel(?User)` carries `textNoticeAcknowledged` (all panel call sites pass the admin, so pause/resume/cancel answers do not reset it; `useBulkStatus.adopt` keeps it).
- The single-run 409 `notice_required` body adds `textNotice: true` when the flag is on, so a dialog reopened by a page loaded before the switch was turned on is the true one.

## UI
- `AiStatusTag`: `text_saved` (`FileText`, neutral, `data-status`).
- `RunAiControl`: provider lines `provider_text_*` when `ai.textNotice`; running with `textSaved` (and `mode` not `text`) shows "text saved, preparing suggestions" and one polite announcement per run; waiting/failed with `textSaved` add `waiting_text_saved` / `failed_text_saved` as a second line; `text_saved` shows its detail, the page line when `pagesRead < pagesTotal`, normal Run AI, no Review.
- `AiNoticeDialog`: prop `text` selects the three text bodies, title and buttons unchanged.
- `RecognisedText` / `DocumentPanel`: the earlier-text line only while the active run has not saved its text; the section appears and an open text reloads as soon as the run's `textSaved` turns true (`statusMap` flag, since `document.hasText` is not refreshed by polling).
- `AiConsentControl` / `PersonHeader` / `Person.vue`: with `search.enabled`, turning AI off opens a confirmation whose body is the purge sentence.
- `BulkRegenPanel`: Start confirm shows the notice body for the frozen provider set while `ocrEnabled` and not `textNoticeAcknowledged`, sends `textNotice: true`; 409 `notice_required` reopens it.

## Vue status sites checked
`TimelineList.vue` renders status through `AiStatusTag` (a `text_saved` entry shows the new tag, nothing else to change). `Pages/Health/AiReview.vue` handles `ready` and `waiting` proposals only; the server hides `mode = text` rows from review (13-06), so no change. `DocumentPanel.vue` changed (passes `textSaved`). `useAiStatus.js` needs no change: `text_saved` is terminal and not fast-polled.

## New and reworded lang keys (en, sk, pl; sk and pl are drafts)
New: `health.ai.run.notice_text_body`, `provider_text_codex`, `provider_text_both`, `provider_text_copilot`; `health.ai.notice.multi_text_body`, `github_text_body`; `health.ai.status.text_saved`, `text_saved_detail`, `text_saved_preparing`, `waiting_text_saved`, `failed_text_saved`, `announce_text_saved`; `health.ai.off_title`, `off_text_purge`, `off_confirm`, `off_keep`. No existing key reworded.

## User documentation
Touched: `resources/manual/en/health.json`, `resources/manual/sk/health.json`, `resources/manual/pl/health.json` (section `ai`: one new paragraph block and three new list items, identical in all three; section `recognised-text` last paragraph; `search` unchanged because nothing in it contradicts the final behaviour), `resources/manual/en/admin.json`, `resources/manual/sk/admin.json`, `resources/manual/pl/admin.json` (`backups` sentence), `resources/help/en.json`, `sk.json`, `pl.json` (`health.ai_run` 376 or fewer characters, `settings.ai_bulk`; `topics.json` untouched). **Every sk and pl text (lang keys, help, manual) is a draft for native review.** ManualCoverage, HelpCoverage, Pest Manual and Help pass.

## Deviations
1. [Rule 3] The plan and UI-SPEC speak of an "existing turn-off confirmation", but turning AI off was immediate (only turning on had a dialog). Added a real turn-off confirm (`UiDialog`, focus on "Keep AI on", no checkbox) shown only when `search.enabled` is true; otherwise off stays immediate as before. This needed three more keys than the plan's single consent key (`off_title`, `off_confirm`, `off_keep`) besides `off_text_purge`.
2. [Rule 2] Extra prop `purgesText` / `textStored` plumbing and `textSaved` plumbing into `RecognisedText` (the page's `hasText` is stale during a run).
3. [Rule 2] The 409 `notice_required` body of a single run carries `textNotice` (only when true) and `RunAiControl` honours it.
4. The running "preparing suggestions" line is not shown for `mode = text` runs (no step 2 exists); the plain wait line is used.
5. `wait_detail` is not reworded (still true with the feature off).
6. `BulkNoTextTest::bulkNoTextStart` helper now passes `textNotice` true (the flag is on there); no assertion changed.

## Known wording tension
`failed_detail` still reads "Nothing was saved." (it means suggestions) and `failed_text_saved` adds "The recognised text was saved." for a failed run with stored text, as UI-SPEC requires. Reading both together may look contradictory; fixing it needs a decision on rewording `failed_detail` (changing its meaning is forbidden by the plan, so it was left).

## Security notes
- Re-acknowledgment per member and per administrator, enforced server-side; not satisfiable by the medical provider set or the recipe columns (test with a recipe-only row); nothing sent and nothing changes while the flag is false.
- A page loaded before the flag was switched on is corrected by the 409; a never-acknowledged member on such a stale page could acknowledge the text-less notice once (accepted: the flag is a deploy-time setting).
- Copy is interpolated only; no `v-html` in the touched components (Vitest source scan); live-region announcements carry fixed strings only; no document content in props or logs.
- Threat register T-13-48 to T-13-52 mitigated as planned.

## Tests
Isolated compose project `fh1309` (own Postgres, scratch folder over storage/logs, serial), removed with `down -v` at the end.
- `TextNoticeAckTest`: 12 tests (flag off, old acknowledgment, new member, recipe-only row, second provider keeps the stamp, page props, bulk 409 with nothing written, `textNotice`, HTTP).
- Task 1 suites (Feature/Ai, Health, Recipes, Unit/Arch): green after the helper fix.
- Vitest full: 192 files, 6452 tests passed. Pest Manual and Help: 49 passed. Pint `--test`: pass (1238 files).
- Full Pest: one clean serial run after all commits: 3984 passed, 1 skipped, 0 failed (36773 assertions, 592 s).

## Known stubs
None.
