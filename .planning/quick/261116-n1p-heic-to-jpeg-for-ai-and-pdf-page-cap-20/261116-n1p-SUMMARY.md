---
phase: quick-261116-n1p
plan: 01
subsystem: medical-ai
tags: [ai, heic, libheif, pdf, docker, i18n]
requires: []
provides:
  - HEIC medical documents run through AI (single run, bulk) as a metadata-free JPEG made only in the job work dir
  - AI reads up to 20 PDF pages (viewer limit stays 50)
affects: [ai-worker image, RunExtractionJob, AI start/bulk eligibility, health manual, help tips]
tech-stack:
  added: [libheif-tools (heif-dec) with libde265 HEVC plugin, ai-runtime stage only]
  patterns: [argv-only decoder spawn under ulimit and timeout, GD re-encode strips metadata]
key-files:
  created:
    - app/Medical/Documents/HeicConverter.php
    - tests/Unit/Ai/HeicConverterTest.php
    - tests/fixtures/ai/sample.heic
  modified:
    - docker/app/Dockerfile
    - config/ai.php
    - app/Medical/Documents/PdfRasterizer.php
    - app/Medical/Ai/RunExtractionJob.php
    - app/Medical/Queries/AiRunQuery.php
    - app/Medical/Queries/AiBulkEligibility.php
    - app/Medical/Queries/AiBulkJobQuery.php
    - resources/js/Health/Ai/RunAiControl.vue
    - resources/manual/en/health.json
    - resources/manual/sk/health.json
    - resources/manual/pl/health.json
    - resources/help/en.json
    - resources/help/sk.json
    - resources/help/pl.json
    - docs/ops/server.md
    - tests/Unit/Ai/PdfRasterizerTest.php
    - tests/Feature/Ai/RunExtractionJobTest.php
    - tests/Feature/Health/AiRunTest.php
    - tests/Feature/Ai/BulkRunTest.php
    - tests/Support/MedicalFileFixtures.php
    - tests/js/health/RunAi.test.js
    - tests/js/health/AiReview.test.js
key-decisions:
  - "Decode with heif-dec, then re-encode through the existing ImageProcessor (GD) so EXIF/GPS/XMP never reach the provider"
  - "Separate ai.pdf.pages_timeout (60 s) for the worker batch rasterize; ai.pdf.timeout (20 s) stays for the viewer"
  - "Server 'unsupported' branches and lang keys kept as defensive code"
metrics:
  completed: 2026-11-16
---

# Quick 261116-n1p: HEIC to JPEG for AI and PDF page cap 20

HEIC photos now reach the AI as a metadata-free `doc.jpg` created only inside the job work dir (heif-dec plus GD re-encode), and AI reads up to 20 PDF pages with its own 60 s batch rasterize timeout.

## Commits

- b06bf6b feat(ai): convert HEIC to a metadata-free JPEG in the job work dir for AI; read up to 20 PDF pages
- 0008546 feat(ai): allow HEIC where AI starts (single run, bulk eligibility and re-check, Run AI control)
- c675724 docs(ai): manual, help tip and ops notes for HEIC conversion and the 20-page PDF read (sk, pl drafts)
- 57b89c1 test(ai): HeicConverter may spawn its decoder (arch allowlist; AiArchTest)
- c7328f5 test(ai): a11y sweep expects an enabled Run AI button for a HEIC document

## What was built

- **Image:** `ai-runtime` stage installs `libheif-tools` (heif-dec, libheif 1.23.7); its dependencies pull `libheif-libde265` (libde265 1.1.3, HEVC). Installed set seen: libheif, libheif-libde265, libheif-jpeg, libheif-dav1d, libheif-openjpeg, libheif-tools, libde265. A build-time `heif-dec --list-decoders | grep -qi hevc` fails the build otherwise. The web `app` image is unchanged (base stage untouched). `family-hub-ai:dev` was rebuilt.
- **HeicConverter:** argv array via `sh -c 'ulimit -v N; exec "$@"'`, hard timeout with SIGKILL, decoder output in its own temp dir (removed in finally), output discarded, any failure returns false with no path or message. Takes `d.jpg`, else the lowest `d-*.jpg`.
- **Job:** `kind === 'heic'` branch copies to `doc.heic`, converts to `doc.jpg`, always unlinks `doc.heic`; failure gives `unreadable_document` with no spawn. Nothing is written to the medical disk (test asserts disk state hash is unchanged).
- **Start paths:** allowlist `['pdf','image','heic']` in AiRunQuery, AiBulkJobQuery, and the bulk eligibility SQL. RunAiControl no longer refuses HEIC locally (a server 'unsupported' answer still shows `heic_line`).
- **Config:** `ai.heic.{binary,timeout 30,ulimit_kb 524288,max_jpeg_bytes 33554432}`, `ai.pdf.max_pages 20`, `ai.pdf.pages_timeout 60`; `view_max_pages` 50 and `timeouts.job` 660 unchanged.

## Limits reasoning and measurements

- ai-worker is `MEM_AI` 768m (not 384m) with a 192m `/ai-work` tmpfs; both kept. pdftoppm renders pages one after another, so 20 pages do not raise peak memory; JPEG q82 at scale 1800 is a few hundred KB per page, so 20 pages fit the tmpfs easily. Only the batch spawn needed a longer bound (60 s), since 20 pages in one spawn can exceed 20 s on the VPS. Worst case 60 s rasterize + 30 s decode still fits the 660 s job deadline guarded by `min_call`. Note: the PDF fixtures are blank pages, so no real-world rasterize timing for 20 dense pages was measured; tune `pages_timeout` after the first real run.
- HEIC measured in the ai image: a 12 MP (4000x3000, heif-enc q50) HEIC converts end to end in about 1.4 s with a 116 MB real PHP peak under `memory_limit=128M` (ulimit 512 MB ok); `heif-dec` alone peaks around 49 MB resident in 0.4 s.
- Orientation: an EXIF orientation 6 source encoded by heif-enc decodes to the rotated dimensions (48x64 from 64x48) with orientation 1 in the JPEG, so ImageProcessor does not rotate twice.

## Documentation (CLAUDE.md rule)

Touched: `resources/manual/{en,sk,pl}/health.json` (one list item added to section "ai", ids/counts/links unchanged), `resources/help/{en,sk,pl}.json` (`health.ai_run` reworded, under 400 chars: 241/226/271). `resources/help/topics.json` NOT changed (no key added or moved). Also `docs/ops/server.md`. **SK and PL texts are drafts for native review.** Manual coverage, help coverage and Manual Pest tests pass.

## Deviations from Plan

**1. [Rule 1 - test adjustments]** PdfRasterizerTest's timeout test pinned `ai.pdf.timeout` for the batch; now also sets `pages_timeout`. BulkRunTest: the HEIC fixture is consent-off, so it moves from `skipped_unsupported` to `skipped_consent` (counts adjusted) and a separate test proves a consenting HEIC is eligible and picked by the bulk candidate query (keeping other bulk tests' expectations stable instead of changing their numbers).

**2. [Rule 3 - environment]** `npm run build` cannot write to `public/build` (pre-existing root-owned `public/build/assets`, git-ignored). Verified the build with `vite build --outDir` to the scratchpad instead (built fine). `scripts/check-compose.sh`: CHECK-COMPOSE OK.

Otherwise the plan was executed as written. The real-decoder test in HeicConverterTest runs (not skipped) in `family-hub-ai:dev`.

## Verification

- Pest: Unit/Ai, Feature/Ai, Feature/Health, Feature/Manual all green (919 tests) in an isolated compose project (`fh1116`, own postgres, scratch logs mount); full Pest suite: 2798 passed, 2 failed. AiArchTest failed on the new spawn site and was fixed (57b89c1, now green). `Unit/Wishlist/ImageRuntimeTest` (writable `storage/app/private/wish-images`) fails only because the isolated test container has no such directory; unrelated to this change, not fixed.
- Vitest: full suite 148 files / 5072 tests green after updating the a11y sweep (HEIC state now an enabled button).

## Threat flags

None beyond the plan's threat model. Mitigations T-n1p-01..06 implemented (ulimit, timeout, argv-only, no content in failures, doc.heic unlinked before the CLI, GD strips metadata).

## Known Stubs

None.

## Self-Check: PASSED
