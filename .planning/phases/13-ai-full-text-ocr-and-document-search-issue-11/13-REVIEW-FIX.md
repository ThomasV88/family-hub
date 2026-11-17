---
phase: 13-ai-full-text-ocr-and-document-search-issue-11
fixed_at: 2026-11-17T00:00:00Z
review_path: .planning/phases/13-ai-full-text-ocr-and-document-search-issue-11/13-REVIEW.md, 13-REVIEW-UI.md
iteration: 1
findings_in_scope: 25
fixed: 22
skipped: 3
status: partial
---

# Phase 13: Code Review Fix Report

**Fixed at:** 2026-11-17
**Source reviews:** `13-REVIEW.md` (backend) and `13-REVIEW-UI.md` (frontend)
**Iteration:** 1

All work is on `main`, one commit per finding or small group (the backend store, job and prompt findings share files and
went in together), every commit carries `Refs #11`. Nothing was pushed. No migration was added or edited. This is stored
medical text: nothing here logs or returns content, and every fix has a test.

## Fixed (backend)

- **WR-01** a `text` or `full` run no longer stores anything while `ai.ocr.enabled` is off: a `text` run finishes cancelled (reason `user`, content-free), a `full` run is turned into an `entries` run (its row mode is rewritten to `entries` while it is running) and reads no page for the text. `DocumentTextStore::save` refuses with `SaveOutcome::Disabled` while the flag is off. Tests in `TranscriptionStepTest` and `DocumentTextStoreTest`.
- **WR-02** `save()` now takes the extraction id (required) and locks that row inside its transaction; it refuses (`NotRunning`) unless the run is `running`, not cancel-requested and for this document. The job ends such a run content-free as cancelled. The staging seeder and test fixtures use the distinct `saveStandalone()` (no run row to lock, no flag check, every other guard kept). Tests: running, cancel-requested, closed, other document, unknown id.
- **WR-03** the member run request carries `textNotice`; while the flag is on the acknowledgment (and `text_notice_acked_at`) is only written when it is `true`, otherwise 409 `notice_required` with `textNotice: true`. The client sends it only from the dialog that showed the text statement. Test in `TextNoticeAckTest` plus the client tests.
- **WR-04** the Copilot prompt cut is reported: `ExtractFromTextTask::pagesIncluded()` counts the whole pages in the inline prompt and the job records `min(textPages, included)` as `pages_read` when the answering agent is Copilot (Codex keeps all pages). Tests: a 12-page long text (partial marker) and a short text (all pages).
- **WR-05** a partial batch never replaces the stored text of another run that holds more pages (also not a complete one): `save()` returns `Kept`, the run goes on in memory and replaces the text once it has at least as many pages or completes. A text run that ends without replacing it finishes failed with the real code (`invalid_output` after a failed batch, `timeout` when the budget ended it); a full run uses the stored text for step 2. Tests: fail after batch 1, budget stop after batch 1, completion replaces, store-level keep and replace.
- **WR-06** a PDF whose page total is above 10000 fails content-free as `unsupported_document` instead of tripping the CHECK. Test with a synthetic 10001-page PDF.
- **IN-01** `TextNormalizer::stripInvisible()` removes zero-width characters (U+200B-U+200D, U+2060, U+FEFF) and turns U+2028 and U+2029 into LF; the search query (`SearchTerms`) and the snippet builder apply the same rule (so rows written before the fix still read as the index sees new text). Existing golden tests unchanged.
- **IN-02** the search answer carries `dropped` (words of one letter, words with no Latin letter or digit after the fold, words past the eighth); the UI shows a small hint only when it is above zero (new key `health.search.dropped_hint`, plural, en/sk/pl). Existing rows written before the fix keep zero-width characters in their index; new rows are clean.
- **IN-03** `max_page_chars` is 9900 so that `max_pages * max_page_chars` plus the page separators fits `max_total_chars` (200000, the CHECK of the body column), and a test pins it with a full 20-page body. Timeouts untouched. The validator and normaliser defaults and their tests follow.
- **IN-06** the restore drill probes a string through the search expression with a prefix `to_tsquery`, and compares every stored `search` value with the expression recomputed from its body (the old `search is not null` check could never fail). `docs/ops/server.md` describes it. The script passes `bash -n`.
- **IN-08** the argv visibility of the Copilot step 2 prompt is a row of the privacy table in `13-RESEARCH.md`.

## Fixed (frontend)

- **WR-01** the search follows deleted text. `DocumentSearch` exposes `refresh()` (the same query again at once, old rows cleared first, even if the same search is in flight); `Person.vue` calls it when this person's `hasText` set or AI consent changes, and when the panel document is no longer in the list (a result that cannot be opened). Tests in `DocumentSearchReview.test.js`.
- **WR-02** a dismissed "Confirm it's you" (423 `cancelled`) keeps the typed query and the results, also during Load more.
- **WR-03** new keys for the saved-text case (`failed_suggestions`, `failed_suggestions_detail`, `quota_timeout_suggestions_detail`, `quota_exhausted_suggestions_detail`, `cancelled_suggestions_detail`, `consent_suggestions_detail`, `announce_failed_suggestions`): they say that no suggestions were saved but the recognised text was; the old keys keep their meaning for runs without text. The failed tag and the timeline tag follow (`textSaved`). `failed_text_saved` is no longer rendered (the key stays).
- **WR-04** the bulk confirm uses its own bodies (`settings.ai_bulk.text_notice_codex|multi|github`): no "this document", no "check every suggestion", no per-document "once".
- **WR-05** `RecognisedText` drops the text from memory as soon as `hasText` turns false (and a later text is fetched only when opened).
- **WR-06** `send()` has a busy guard; a 409 `notice_required` sets a forced flag so the next press opens the dialog again without a round trip; cleared by a successful send or a changed prop.
- **WR-07** a poll that brings `running` together with the saved text announces "text saved" (the running line is skipped); a failed run after saved text has its own announcement.
- **IN-01** the turn-off confirm names the deletion only when this person has documents with text (`hasText`); it falls back to the feature switch only if the list does not say.
- **IN-03** plural forms (en 2, sk/pl 4) for `health.search.partial`, `health.document_text.tag_partial` and `partial_alert`; `i18n-phase7` excludes `health.document_text.*` from the ten-plural check like it already did for `health.search.*`.
- **IN-05** during the pause after a 429 a held-back search shows a progress line; a 429 on Load more starts the pause and Load more sends nothing inside it; the pause follows `Retry-After` (new `seconds` on the 429 result of `securityFetch`, 1 s to 120 s, else 5 s); `runWithGate` opens no dialog for a request whose signal was already aborted.
- **IN-06** the text is drawn as one block per paragraph inside each page (no huge text node); the text GET sends `cache: 'no-store'` (new `cache` option of `securityFetch`). Ctrl, Cmd, Shift, Alt and non-primary clicks on a result are left to the browser (`UiListRow` already did this; the handler now checks too, with a test).

## Skipped

### Backend IN-04: both catch branches throw in the text-table migration
**Reason:** skipped on purpose (cosmetic, and the migration is already on `origin/develop`; pushed migrations are never edited).

### Backend IN-07: eval command worker-free check and report file creation
**Reason:** skipped on purpose (not trivial: needs an advisory lock held for the whole run; the command only runs on staging and testing with `--confirm-synthetic`).

### Backend IN-05: `down()` of `2026_12_03_120000_add_text_notice_to_ai_notice_acks` drops acknowledgments silently
**Reason:** the migration is already on `origin/develop`, so it was not edited (document instead, as decided). A new migration cannot change an old `down()`. Runbook note: do not roll this migration back on a database where members acknowledged the text notice; the column holds the only record of it and a rollback loses it (the next run then asks again, which is safe but repeats the notice).

## Partly done or worth knowing

- IN-01 (frontend): archived documents are not in the document list, so a person whose only text sits on archived documents gets no purge sentence although consent-off still deletes it. The server behaviour is unchanged.
- IN-06 (frontend): the rendered text is not capped to the first pages; one block per paragraph keeps single nodes small (a page is at most 9900 characters, a text at most 20 pages).
- WR-01 (backend): a downgraded `full` run keeps its queue and audit history as before; only the row mode changes to `entries`.
- Logic changes that need a human look: backend WR-01 (rolled-back flag turns queued runs into plain runs), WR-02/WR-05 (a refused or kept save ends the run as cancelled or failed), frontend WR-01 (the search re-runs on text, consent or panel changes) and WR-06 (forced notice).

## User documentation

Touched: `resources/manual/{en,sk,pl}/health.json` (section `search` gets one item about skipped words; the `ai` section and the recognised-text section say the deletion question appears when the person has text), `resources/help/{en,sk,pl}.json` (`health.ai_consent` no longer says "Turning it off deletes nothing"; 265, 248 and 294 characters). Lang keys added in all three files: see WR-03, WR-04 and IN-02 above. Same section ids, counts and links in all three languages.

**Native review needed (Slovak and Polish drafts):** every new key listed above in `lang/sk.json` and `lang/pl.json`, the plural forms of the three partial-page strings, the manual sentences and the `health.ai_consent` help text.

## Verification

Isolated compose project `fh1311` (own Postgres, scratch folder over `storage/logs` through an override file), serial, removed with `down -v` (no container, volume or network left). Full Pest in one clean run after the last code change: 4005 passed, 1 skipped, 0 failed (36919 assertions, 617 s; includes `Feature/Manual`, `Help`, the arch and table-scan tests). A first full run found one failure (a table name in a docblock of the job, caught by `MedicalTableScanTest`), fixed in its own commit. Pint `--test`: pass (1240 files). Full Vitest: 195 files, 6646 tests passed (a11y sweeps, manual and help coverage, i18n parity, pseudo-long). Not run: `npm run build` and `scripts/test-backup-local.sh` (the restore-drill script was only syntax-checked with `bash -n`; run the backup test once on a machine with the restic image before relying on the new probe).

---

_Fixed: 2026-11-17_
_Fixer: Claude (gsd-code-fixer)_
_Iteration: 1_
