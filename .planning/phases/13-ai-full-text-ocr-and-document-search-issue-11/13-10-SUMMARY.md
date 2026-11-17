---
phase: 13-ai-full-text-ocr-and-document-search-issue-11
plan: 10
subsystem: release-gate
tags: [staging-seed, ops, backups, restore-drill, i18n, a11y, gate]
requirements: [GH11-SC1, GH11-SC2, GH11-SC3, GH11-SC4, GH11-SC5, GH11-SC6]
key-files:
  created:
    - database/seeders/Staging/DocumentTextsSeeder.php
    - tests/Feature/Health/StagingDocumentTextsSeedTest.php
    - tests/js/health/PseudoLong.test.js
  modified:
    - database/seeders/StagingSeeder.php
    - database/seeders/Staging/DocumentsSeeder.php
    - docs/ops/staging-data.md
    - docs/ops/server.md
    - ops/env/app.env.example
    - scripts/restore-drill.sh
    - scripts/test-backup-local.sh
    - lang/{en,sk,pl}.json
    - tests/Feature/StagingDemoSeedTest.php
    - tests/Feature/Health/SubjectTest.php
    - tests/Unit/Arch/MedicalPrivacyArchTest.php
    - tests/js/{i18n-phase13,a11y-sweep}.test.js
    - tests/js/health/{a11y-health,RunAi}.test.js
---

# Phase 13 Plan 10: Staging seed, ops notes, parity and the full gate (Tasks 1-2 only)

Tasks 1 and 2 are done. **Task 3 (the 16-step staging verification with a real two-step run) is NOT done: it is pending the orchestrator**, which pushes `develop`, waits for the staging deploy and performs it. This plan is not complete until then.

## Commits
- a5a36ab `feat(13-10)`: synthetic recognised texts on staging, backups note, restore drill text line (Refs #11)
- d0d424d `test(13-10)`: final locale parity, accessibility sweep, pseudo-long check, gate fixes (**Fixes #11**, the last code commit of the phase)

## What was built
- `DocumentTextsSeeder` (called from `StagingSeeder` after the documents and proposals seeders): every text goes through `DocumentTextStore::save` (consent, archive guards, normalisation, page counting are the real ones). Scenarios: three-page Slovak (`Žltačka`, `ľadviny`, `Penicilín`), two-page Polish (`zażółć`, `Łukasz`), English with `paracetamol`, partial (4 of 9 pages, `complete` false), a text with an empty (illegible) page written by a text-only run (mode `text`, status `text_saved`, the text row carries its extraction id), the grandparent's seeded proposal turned into a two-step run (mode `full`, prompt version 2) with its text, a text for the hidden person (Marek Skrytý), a text for the archived document (written while the document was active, then archived again). No AI call rows are written (`ai_calls` is append-only). The texts are invented and are not OCR of the seeded pictures (documented).
- Reseed: `StagingSeeder` also deletes `medical_document_texts` by `started_by` (the people and documents cascade already removed them); a second seed leaves one text per seeded document. `staging:audit` already covered the table (13-03 rule `started_by in real`), no change; a new test proves a text started by a real user makes it fail.
- `docs/ops/staging-data.md` (Slovak): scenario map with words to search, who sees what, the note that texts do not match the pictures, how to show the "may contain errors" label (`AI_OCR_TEXT_RELIABILITY=uncertain`), reseed behaviour, audit. Document and consent counts of the existing sections updated (nine documents, consent on for A, D and E).
- `docs/ops/server.md`: backups now contain the recognised text until retention expires (production daily 14, weekly 8, monthly 6), removing text in the app does not remove it from older snapshots, the `RESTORE-DRILL TEXT` line, the env keys `AI_OCR_ENABLED` (default false, production off until the owner enables it) and `AI_OCR_TEXT_RELIABILITY`. Both keys are in `ops/env/app.env.example`.
- `scripts/restore-drill.sh`: after the restore prints `RESTORE-DRILL TEXT OK rows=<n>` once it has checked, in the restored database, that the table exists, its generated `search` column is filled and `public.fh_unaccent(chr(321) || 'ukasz')` gives `Lukasz`; `RESTORE-DRILL TEXT NONE` when the snapshot predates the table. Counts only. `scripts/test-backup-local.sh` creates the table, function and one synthetic row, asserts the OK line on both drills, and has a new step 8c (drop the table, new snapshot, drill must say `NONE` and still end with `RESTORE-DRILL OK`).
- Task 2: parity test for the 13-08/13-09 lang keys (non-empty in en, sk, pl, same placeholders, not left in English, no plurals among them), help topics `health.search`, `health.document_text`, `health.ai_run`, `settings.ai_bulk` (at most 400 characters, three languages), identical section ids and block shapes in `health.json` and `admin.json`. Accessibility sweep states (en, sk, pl x light, dark, 320 px and +40 percent text, through the existing loops): text_saved, running/waiting/failed with text saved, the text provider line, text notice dialog (codex, multi, github) and the consent turn-off confirmation with the purge sentence; `a11y-sweep.test.js` covers the bulk panel with the `no_text` scope selected and its dry-run result (en/sk/pl, light/dark, 390 and 1280 px, plus +40 percent at 360 px). New `tests/js/health/PseudoLong.test.js` (sk and pl, 360 px, +40 percent): search results, recognised-text header with both tags and actions, run detail lines for every 13-09 state, notice variants, consent confirmation, bulk `no_text` row, and a source scan that the only line clamp on these screens is the three-line snippet.

## Gate results (isolated compose project `fh1310`, own Postgres, scratch folder over storage/logs, serial; removed with `down -v`, no `fh1310` containers or volumes left)
- Pint `--test`: pass (1240 files)
- Full Vitest: 193 files, 6606 tests passed. `npm run build`: OK with `SW-PRECACHE OK`
- Full Pest serial: first run 3989 passed, 1 skipped, 1 failed (the arch test below); after the fix one clean run: **3990 passed, 1 skipped, 0 failed** (36841 assertions, 616 s)
- `check-nginx-template.sh`: OK. `check-compose.sh`: OK. `check-upload-limit.sh fh1310`: OK (upload 302, profile 302, shopping 302, recipes 302, other 413, medical 302, medicalother 413)
- `bash -n` on both scripts, `scripts/test-backup-local.sh` (own project `fh1310b`, removed by the script): `TEST-BACKUP OK`

## Deviations from plan
1. [Rule 3] The arch test `medical queries are used only by the medical layers` fails for a seeder that uses `DocumentTextStore` (required by the plan). Added only `Database\Seeders\Staging\DocumentTextsSeeder` to its allowlist, with a comment.
2. [Rule 3] The seed needed more documents than the existing seven: `DocumentsSeeder` got two (`text_only_pdf` 3 pages for adult A, `partial_pdf` 9 pages for the grandparent). `StagingDemoSeedTest` pins changed with that: documents 7 to 9, extractions 3 to 4, PDF page counts per title, consent count 2 to 3.
3. [Rule 2] The hidden person had AI consent off, and the store refuses a write without consent. The seeder turns consent on for that one synthetic person (and writes the changed-by stamp) before saving; it stays on. Documented in `staging-data.md`.
4. [Rule 1] Staging scenario correction found by the test: parent `sk_sk.1` may see Marek (he is hidden from the child and guest only), so the parent finds his text; the hidden-from-search proof is with `sk_sk.2`.
5. The grandparent's existing seeded proposal is converted to a two-step run (mode and prompt version updated by a direct update on the seeded row) instead of adding a new proposal, to keep the seeded counts.
6. [Rule 1] The `SubjectTest` "creates a person" flake (random actor name containing "Ada"): the actor now has a fixed name and e-mail. The assertion is unchanged.
7. Wording tension fixed (see below): `failed_text_saved` reworded in en, sk, pl; its meaning is unchanged (the recognised text was saved) and it now says that only the suggestions are missing. `tests/js/health/RunAi.test.js` pins the new English text. No existing key's meaning changed. `failed_detail`, `cancelled_detail`, `consent_detail`, `quota_*_detail` still say "Nothing was saved." (meaning: no suggestions); a full fix would reword those five keys to "No suggestions were saved." in all three languages when a text may exist, which changes existing keys and was left for the owner.

## Known wording tension (proposal)
"AI could not read this document. Nothing was saved." followed by the second line "Only the suggestions are missing: the recognised text was saved and can be searched." is now consistent in meaning, but the first line is still literally "Nothing was saved". Proposed follow-up (not done): reword `failed_detail` and the quota, cancelled and consent detail lines to "No suggestions were saved." (sk "Žiadne návrhy sa neuložili.", pl "Żadne propozycje nie zostały zapisane."), then drop the "Only" lead from `failed_text_saved`.

## User documentation
No user-visible change in this plan except the reworded `health.ai.status.failed_text_saved` line (lang keys only; no manual or help text quotes it). No `resources/manual/` or `resources/help/` file touched in 13-10. Touched in Phase 13 (all sk and pl texts are drafts):
- Manual: `resources/manual/{en,sk,pl}/health.json` (13-05 sections `search`, `recognised-text`; 13-09 section `ai` and last paragraph of `recognised-text`), `resources/manual/{en,sk,pl}/admin.json` (13-08 `ai-agents`; 13-09 `backups`)
- Help: `resources/help/topics.json` (13-05), `resources/help/{en,sk,pl}.json` (13-05 `health.search`, `health.document_text`, screens `document_search`, `document_text`; 13-09 `health.ai_run`, `settings.ai_bulk`)
- Lang: `lang/{sk,pl}.json` (see list below)

### Drafts needing native review (sk and pl)
1. `lang/sk.json`, `lang/pl.json`, 13-04: audit label `audit.detail.medical_text_removed` (sk "Odstránený rozpoznaný text v Zdraví", pl "Usunięto rozpoznany tekst w Zdrowiu")
2. `lang/sk.json`, `lang/pl.json`, 13-05: every key under `health.search.*` and `health.document_text.*`, including the extra keys `health.search.error_session`, `error_forbidden`, `results_aria`, `searching` and `health.document_text.error_session`, `loading`
3. `lang/sk.json`, `lang/pl.json`, 13-08: `settings.ai_bulk.scope_no_text`, `scope_no_text_hint`, `no_text_note`, `zero_eligible_no_text`, `history_text_only`
4. `lang/sk.json`, `lang/pl.json`, 13-09: `health.ai.run.notice_text_body`, `provider_text_codex`, `provider_text_both`, `provider_text_copilot`; `health.ai.notice.multi_text_body`, `github_text_body`; `health.ai.status.text_saved`, `text_saved_detail`, `text_saved_preparing`, `waiting_text_saved`, `failed_text_saved` (reworded in 13-10), `announce_text_saved`; `health.ai.off_title`, `off_text_purge`, `off_confirm`, `off_keep`
5. `resources/manual/sk/health.json`, `resources/manual/pl/health.json` (sections `search`, `recognised-text`, `ai`)
6. `resources/manual/sk/admin.json`, `resources/manual/pl/admin.json` (sections `ai-agents`, `backups`)
7. `resources/help/sk.json`, `resources/help/pl.json` (`health.search`, `health.document_text`, `health.ai_run`, `settings.ai_bulk`; screens `document_search`, `document_text`)
Not drafts to translate: `docs/ops/*.md` (operator notes, Slovak, owner-facing).

## Leftovers and deviations noted in 13-01 to 13-09 (no action taken unless stated above)
- 13-01: `imagescale` replaced by one-pass `imagecopyresampled` (memory); corpus ground truth copied to `truth/` because `tests/` is in `.dockerignore`; Copilot stage agents built outside `AgentRegistry`; `SearchTerms` trims only 1 or 2 trailing characters (13-04 left it alone, so long Polish endings such as "-ach" do not reach a base word three characters shorter).
- 13-02: evaluation corpus synthetic and clean (CER 0.0 percent, ceiling effect); real phone photos not measured; stage D and Copilot stage G skipped; 148 of 150 calls.
- 13-03: `DocumentTextStore` lives in `Medical/Queries` (repo scans); the store never catches or logs because a `QueryException` message carries the bound text, so the job's content-free catch must stay; `pages_read`/`complete` are recomputed by the store.
- 13-04: search is a POST and is audited as `health_read` (content-free); `MedicalPrivacy::READ_ONLY_POSTS` added; the audit row of a removal is written inside the store's transaction; a visible document without text answers 404 on the text view (reveals only absence on a document the member may open); `MedicalLeakSuiteTest` inventories adjusted.
- 13-05: the search input has a hidden label on every width; `securityFetch` gained an optional `signal`; extra HTTP-failure copy keys; no separate RED commit; the "Recognised text" tag "May contain errors" shows in the header as soon as reliability is `uncertain`.
- 13-06: `text_reliability` default is now `reliable` (two 13-04 expectations updated); the consent and archive between batches tests use a `DB::listen` hook; a text run failing after the first batch ends `text_saved` with the stored partial text.
- 13-07: new optional `$promptVersion` parameter of `AiJobQuery::finishReady` forced a signature change in an anonymous subclass in `RunExtractionJobTest`; reused text is re-saved under the new run with its language, agent and model.
- 13-08: `AdminAiBulkQuery::scopes()` added so the controller keeps naming only the query class; the tick holds a `no_text` run while the flag is off; the "unsupported file type" bucket is always 0 for this scope in tests (the schema cannot produce it); `ai_bulk_run_items` and `ai_bulk_runs` are still not pruned (see DEFERRED.md).
- 13-09: turning AI off had no confirmation before; a real turn-off dialog was added, shown only while the search switch is on; a stale page loaded before the flag was switched on may let a never-acknowledged member acknowledge the text-less notice once (accepted: deploy-time setting); `wait_detail` not reworded; the "preparing suggestions" line is not shown for text-only runs.
- Test notes: `tests/Unit/Ai/Eval/TextMetricsTest.php` (CER of 3000 characters under 2.0 s) had one timing flake under parallel Vitest in 13-04, clean in serial runs; Phase 8 `PdfRasterizerTest` 1 ms timeout test is timing sensitive in the full suite (green in both full runs here).
- Open from this plan: the seeded Polish and Slovak search words match only because the seed text uses them; `ai_calls` rows are not written for the seeded runs, so Settings > AI calls shows only the three rows of the earlier proposal seed.

## Decision record for the owner (the orchestrator copies it into the PR body)

| Topic | Decision |
|---|---|
| Evaluation | Synthetic, clean corpus; CER 0.0 percent; real phone photos not measured; 148 staging Codex calls |
| Pre-processing | None; PDF pages stay at 1800 px |
| Reading | Two steps (transcribe, then extract suggestions from the text); 4 pages per call, at most 20 pages; a longer document is read in part and marked partial; timeouts not raised |
| Storage | Plain text in `medical_document_texts`, same access as the document, deleted with it, hidden when the document or person is archived or hidden, never in exports, logs or AI calls |
| Search | By name and recognised text; accent-insensitive prefix match; no stemming, no typo tolerance |
| Reliability label | Off (`AI_OCR_TEXT_RELIABILITY=reliable`); switch to `uncertain` in `app.env` without a code change |
| Consent | Turning AI off for a person purges their texts (confirmation says so); per-document Remove for editors |
| Notice | New notice acknowledged once per member (separate column); administrator re-acknowledges for a bulk start |
| Backups | Database snapshots hold the text until retention expires (production daily 14, weekly 8, monthly 6); restore drill checks the table and `fh_unaccent` |
| Providers | Copilot transcription unverified (best effort, fail fast, bulk Codex-only) |
| Not added | Evidence field |
| Backfill | Only through bulk scope `no_text` (dry run first) |
| Production | Stays off until the owner sets `AI_OCR_ENABLED=true` in the production `app.env` |

Open points and backlog: check real documents by eye before relying on the text; a 2048 px derivative for the AI path (evaluation note); trigram fuzzy search; a "documents mentioning this drug" view (owner suggestion); a cheaper model for transcription; reword the "Nothing was saved" family of detail lines (see above); `ai_bulk_run_items` retention.

## Task 3 (pending the orchestrator)
Steps 0 to 16 of the plan: push `develop`, wait for the staging deploy, reseed (the deploy does it), then verify with the demo accounts. Seed words for the steps: `zltacka`, `zazolc`, `lukasz`, `paracetamolu` (visible); `betaloc` (archived, expected not found); `warfarin` (hidden person: found by `sk_sk.1`, not by `sk_sk.2`). Staging step 0 turns `AI_OCR_ENABLED=true` on; the seeded text rows exist regardless of the flag.

## Known stubs
None.

## Self-Check: PASSED
Files exist (seeder, seed test, PseudoLong test, this summary); commits a5a36ab and d0d424d exist; the working tree holds no uncommitted file of this plan.
