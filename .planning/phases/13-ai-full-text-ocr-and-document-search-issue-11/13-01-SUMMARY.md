---
phase: 13-ai-full-text-ocr-and-document-search-issue-11
plan: 01
subsystem: ai-evaluation
tags: [ocr, evaluation, gd, codex, staging]
requirements: [GH11-SC1, GH11-SC2]
key-files:
  created:
    - app/Console/Commands/AiOcrEval.php
    - app/Medical/Ai/Eval/ (TextMetrics, EntryScorer, Stats, EvalDecision, Corpus, CorpusRejected, EvalTask, EvalRunner, EvalReport, EvalStopped)
    - app/Medical/Queries/EvalGuardQuery.php
    - app/Medical/Ai/TokenUsage.php
    - app/Medical/Documents/Text/SearchTerms.php
    - app/Medical/Documents/Preprocess/ (DocumentPreprocessor, SkewEstimator)
    - resources/ai/transcription.v1.schema.json, transcribe.v1.prompt.md, transcribe.v1.inline-json.md
    - resources/ai/eval/ (extract-from-text.prompt.md, combined.schema.json, combined.prompt.md)
    - scripts/ocr-eval/generate.sh, tests/Fixtures/ocr-eval/*, tests/Support/preprocess-memory-probe.php
  modified: [ProcessResult, CodexRunner, AgentOutcome, CodexAgent, tests/Support/FakeCodex.php, tests/Unit/Ai/CodexRunnerTest.php]
---

# Phase 13 Plan 01: OCR evaluation tooling Summary

Guarded `ai:ocr-eval` harness, metrics, the decision rule as code, GD pre-processing arms, Codex token counters, transcription schema and prompts, synthetic ground truth and a deterministic corpus generator. No real CLI was called.

## Commits
- 3cd6bbf metrics, SearchTerms, Stats, EvalDecision
- 8a30f03 ground truth, generator, DocumentPreprocessor, SkewEstimator
- 13d112a harness, token counters, schemas, prompts, FakeCodex
- (fix) busy-check moved to Medical/Queries, medical disk path from config (MedicalTableScan and mutation scan rules)

## Tests
Isolated compose project fh1301 (removed with `down -v`), serial. Pint: pass. Full Pest run: 3764 passed, 3 failed; all 3 were the repo scan rules above (raw DB access outside Queries, medical table names, `Storage::disk`) and are fixed. Afterwards `tests/Unit` and `tests/Feature/Ai` rerun green (1249 passed, 1 skipped) and the touched scan tests pass; the rest of Feature was green in the full run and is untouched by the fix. AiArchTest passes unchanged. Vitest not run (no JS, lang, help or manual file touched).

## For plan 13-02
- Generate (dev machine): `bash scripts/ocr-eval/generate.sh /tmp/ocr-corpus` -> 74 items, about 24.9 MB, about 50 s. It also writes `truth/` (ground truth with sha256 in the manifest) because the AI image has no `tests/`.
- Copy into the staging ai-worker `/ai-work/eval` (tar over ssh into `docker exec -i ... tar -C /ai-work/eval -xf -`, then chown to the worker uid).
- Run in the ai-worker (APP_ENV=staging): `php artisan ai:ocr-eval --confirm-synthetic --dir=/ai-work/eval --stage=A,B --max-calls=150 --pause=5`, then `--stage=C`, `--stage=D`, `--stage=E`, `--stage=F`, `--stage=G` (resume from `state.json`, finished calls are not repeated). `--stage=cleanup` removes `work/` and `state.json`. After stage B it prints `projected_total=`; it stops (`projected_over_cap`) if the remaining planned calls would exceed `--max-calls`. Stage D size is unknown until C, so it is not in the projection; `--max-calls` still caps hard (per invocation).
- Report `<dir>/report-<UTC>.json` fields: generated_at, stopped_reason, calls{this_run,total,projected_total}, noise_sd, stages_done, rows[] (stage, item, doc, condition, arm, rep, ok, failure, ms, pre_ms, peak_mb, tokens{input,cached,output,reasoning}, cer, wer, diacritics, drop_rate, numeric, recall, f1, precision, recall_entries, tp/fp/fn), aggregates ("stage:arm"), by_condition, resolution_probe{arms,downscale_confirmed}, verdicts{A5,A1,A2,A4,A3 -> keep, reasons, notes}, chain, pipelines{P0,P1,P2}, copilot, decisions{preprocess, best_arm, pdf_scale, pipeline, store_text, seconds_per_page{median,p90,n}}.

## Deviations
1. [Rule 1] Memory: `imagescale` (two-pass) pushed a portrait 3600 px page to 110 MiB peak. `fit` uses one-pass `imagecopyresampled` onto white (peak 78 and 82 MiB, about 1.4 s for the full chain). The plan said imagescale bicubic.
2. [Rule 3] Ground truth: `tests/` is in `.dockerignore`, so the worker cannot read `tests/Fixtures`. Corpus reads the repo fixtures when present, else a manifest-verified `truth/` copy (generator adds it).
3. `--dir` may equal `<work dir>/eval` itself (default), not only a subfolder.
4. Copilot (stage G) agents are built directly, not through AgentRegistry, because the registry's recorder writes `ai_agent_statuses` (the command must write no database row). Model settings are read only.
5. `bySchema` identifies the schema by file content (md5 of the resource file), since the agents copy every schema to `schema.json`. Options: usage, log, quota_after, fail_after; it also answers `login status`.
6. `EvalDecision::stepVerdict` returns an extra `notes` key (`clean_not_measured`) so rule 2 is never skipped silently in stage D (single steps are measured on degraded conditions only). Added `EvalDecision::margin`.
7. Stage E: phone items are not in stage C, so P1 makes one extra transcription call per phone item (about 42 calls instead of 36). Single-step verdict comparisons: A2 vs A1, A4 vs A1, A3 vs A2 (A1 vs A0, A5 vs A0).
8. Test change: search recall for "Penicilin" typo variant uses "Penicn" as the miss; the trimming rule (keeps 5+ characters) legitimately matches "Peniciln".
9. Extra file `tests/Support/preprocess-memory-probe.php` (child-process memory measurement). Corpus rejection code renamed `inside_private_documents` (a medical_ prefix in app code trips the table-name scan).

## Corpus facts
6 pages (SK discharge, PL prescription, EN lab, SK vaccination card with stamp and handwriting-like note, PL referral with signature and small print, mixed two-column), 11 conditions each (66 images, JPEG q80), 6 PDF variants, 2 probe images (4000x4000): 74 items. Real corpus verified to load in `Corpus::load`. Skew estimator on the generated pages: rot3 -> 3.07 deg, rot8 -> 8.03 deg, clean -> 0.

## User documentation
No user-visible change: operator command on staging only; no route, setting, permission, limit or UI text changed. No `resources/manual/` or `resources/help/` file touched.

## Known stubs
None.

## Self-Check: PASSED
All listed files exist and commits are in `git log`.
