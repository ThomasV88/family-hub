# Phase 13 evaluation: reading documents with the AI, with and without image pre-processing

Run on 2026-11-17 on **staging** with the real Codex login (ChatGPT subscription), **synthetic documents only**, one call at a time, 148 of the 150 allowed calls, stopped_reason `none`. Raw numbers: `13-eval-report.json` (ids and numbers only, no document text; checked).

## Summary (for the owner)

- **Codex reads our clean synthetic documents almost perfectly without any help.** On 6 synthetic pages (Slovak, Polish, English, a vaccination card with a stamp and handwriting-like note, a referral with a signature, a dense two-column page) in 7 conditions each (clean, rotated 8 degrees, perspective, blur, low contrast, noise, JPEG artefacts) the character error rate was **0.0 %** for the unprocessed image in all 42 cases.
- **Our own pre-processing did not help and slightly hurt.** The full chain (fit to 2048 px, auto-levels, denoise and sharpen, deskew) gave a mean error rate of 0.23 % (worse than 0.0 %), worst on low-contrast pages (1.6 %), and added about 3.8 seconds per page. Result: **do not pre-process.**
- **Codex shrinks every image to 2048 px on the long edge itself** (confirmed by a resolution probe: the 4000 px and the 2048 px versions gave identical text, the larger one cost about 60 % more input tokens). It does no contrast or brightness adjustment. So sending bigger images only costs quota.
- **PDF pages:** rendering at 2048 px was as good or marginally better than 1800 px (one diacritic slip at 1800 on the dense page), but the gain is below the pre-registered margin, so the existing **1800 px stays**.
- **Two steps beat one:** transcribing first and extracting entries from the text gave a slightly higher entry F1 (0.81) than the single combined call (0.80) at lower input-token cost, but takes about 10 seconds longer. The two-step design is kept.
- **Time and quota:** about 12 seconds per page for a transcription call (p90 14.5 s). 148 calls used about 2.9 million input tokens in total. The usage window was not exhausted.
- **Limit of this evidence (please read):** the corpus is synthetic and typographically clean, so the model hits a ceiling (0.0 %) and the pre-registered rule could not find any step that helps. Real phone photos of crumpled or glared paper, real handwriting and real stamps were NOT measured. Before relying on stored text for medical decisions, check a handful of real documents by eye (the text view shows the text next to the document). The "may contain errors" label is controlled by `ai.ocr.text_reliability` and can be switched to `uncertain` at any time without a deployment of code.

## How images are read today

Stored images are sanitised JPEGs (private disk). The worker copies the image (HEIC is converted, PDFs are rasterised by `pdftoppm` at 1800 px) into a throwaway work directory and passes it to `codex exec -i`. Codex (0.169.0) resizes anything above 2048 px on the long edge and re-encodes it; it does not adjust contrast, brightness or colour. The resolution probe confirms the downscale: identical results, 15.8k instead of 25.1k input tokens (PNG) and 15.9k instead of 22.9k (JPEG).

## Method

Six synthetic ground-truth documents, 11 degradation conditions generated with ImageMagick (66 images, JPEG q80), 6 PDF variants and 2 probe images (74 items, 24.9 MB), copied into the staging ai-worker's `/ai-work/eval` (tmpfs). `ai:ocr-eval` (staging only, refuses production, synthetic corpus only, one call at a time, `--max-calls`) ran stages A (resolution probe), B (baseline), C (A0 vs A5 chain on 7 conditions), F (PDF 1800 vs 2048) and E (pipelines P0 combined, P1 two calls, P2 extract from text). Stage D (single pre-processing steps) was **skipped on purpose**: with a baseline error rate of exactly 0.0 nothing can improve on it, so ablation would only have burned quota. Stage G (Copilot as transcription fallback) was **not run** to protect the allowance; Copilot remains unverified for transcription (research risk R-Copilot): the page cap applies to Codex, and Copilot runs fail fast as before.

## Results

| Stage:arm | n | CER | WER | diacritics | numeric | search-term recall |
|---|---|---|---|---|---|---|
| B:A0 (baseline) | 6 | 0.0000 | 0.0000 | 1.000 | 1.000 | 1.000 |
| C:A0 (no pre-processing, 7 conditions) | 42 | 0.0000 | 0.0000 | 1.000 | 1.000 | 1.000 |
| C:A5 (full chain) | 42 | 0.0023 | 0.0020 | 0.9986 | 1.000 | 0.9979 |
| F:PDF1800 | 6 | 0.0001 | 0.0011 | 0.9959 | 1.000 | 1.000 |
| F:PDF2048 | 6 | 0.0000 | 0.0000 | 1.000 | 1.000 | 1.000 |

A0 versus A5 by condition (CER): clean 0 / 0, rot8 0 / 0, perspective 0 / 0.0004, blur 0 / 0.0002, low contrast 0 / 0.0158, noise 0 / 0, JPEG 0 / 0. Noise floor (sd between repeated identical calls): 0. Verdict for A5: not kept (gain below margin, interval includes zero, recall drop, too slow).

| Pipeline | n | entry F1 | mean wall time per page |
|---|---|---|---|
| P0 one combined call | 12 | 0.800 | 13.5 s |
| P1 transcribe, then extract (2 calls) | 12 | 0.812 | 22.9 s |
| P2 extract from the stored text only | 12 | 0.814 | 20.0 s |

Time: transcription calls median 12.0 s, p90 14.5 s per page (n = 106). Pre-processing cost 3.4 to 5.5 s per page on the 2-CPU worker with 87 to 88 MB peak memory.

## Decisions (pre-registered rules applied verbatim)

- **Pre-processing:** `none`. The chain is slower, not better, and made low-contrast pages worse.
- **PDF scale:** 1800 (2048 does not beat 1800 beyond the margin).
- **Pipeline:** `two_call` (P1 is not more than 2 points below P0).
- **Seconds per page:** p90 = 14.52 s. `pages_per_call` = 4 (4 x 14.52 = 58 s, within 240 s). `max_pages` = floor((660 - 60 - 120 - 60) / 14.52) = 28, capped at 20. Process and job timeouts are not raised (owner default).
- **Store text:** `go` (typical set CER 0.0 %, diacritics 100 %, numeric 100 %, recall 100 %). `text_reliability: reliable`, with the synthetic-corpus caveat above recorded for the owner.
- **Evidence field:** `no` (owner default).

## What plans 13-06 to 13-10 must use

- Do NOT use the pre-processing step classes in production; keep `DocumentPreprocessor` and the evaluation command as an operator tool only (they are not wired into the run job).
- PDF pages are rasterised at 1800 px as today; images are sent as stored (the 3600 px / q90 storage stays: Codex downsizes anyway, the extra pixels only cost tokens, so consider storing a 2048 px derivative for the AI path later (backlog)).
- Transcribe in batches of 4 pages per call, two-call pipeline (transcribe, store text after each validated batch with a `complete` flag, then extract entries from the text), at most 20 pages, `ai.ocr.text_reliability = reliable`.
- Set `ai.ocr.enabled` to true only together with the new notice and re-acknowledgement; production stays off until the owner turns it on.

## Owner notes (for the PR body)

- 148 Codex calls of the staging ChatGPT login were used for this evaluation (synthetic only). Nothing was written to any database, and the worker and Codex home hold no evaluation content after cleanup (checked: no ground-truth words in `/codex`, `/ai-work` empty, `staging:audit` OK).
- The evidence is from clean synthetic documents; please judge real documents by eye after the feature is on and tell me if the label should be shown (config switch).

```decisions
status: complete
preprocess: none
pdf_scale: 1800
pipeline: two_call
pages_per_call: 4
max_pages: 20
seconds_per_page: 14.52
store_text: go
text_reliability: reliable
evidence_field: no
codex_resize_confirmed: yes
```
