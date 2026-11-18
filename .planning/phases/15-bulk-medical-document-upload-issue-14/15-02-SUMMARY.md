---
phase: 15-bulk-medical-document-upload-issue-14
plan: 02
subsystem: medical-ai
tags: [ai-copy-adjust, fresh-read, run-job, issue-14]
requires: [15-01]
provides:
  - App\Medical\Documents\Adjustments value object
  - App\Medical\Documents\AiCopyAdjuster (GD, in place in the work dir)
  - claim() returns fresh_read and adjustments; RunExtractionJob honours both
affects: [15-03, 15-05, 15-07]
key-files:
  created:
    - app/Medical/Documents/Adjustments.php
    - app/Medical/Documents/AiCopyAdjuster.php
    - tests/Feature/Ai/AiCopyAdjusterTest.php
    - tests/Feature/Ai/UploadBatchRunJobTest.php
  modified:
    - app/Medical/Ai/RunExtractionJob.php
    - app/Medical/Queries/AiJobQuery.php
decisions:
  - "Adjusted copy: fit to 2048 px, one imageconvolution (centre b*k, offset 127.5*(1-k)), then grayscale when bw; same name and format"
  - "Adjuster failure ends the run content-free as unreadable_document"
metrics:
  tasks: 2
  files: 6
requirements: [BULK-05, BULK-06]
---

# Phase 15 Plan 02: Adjusted AI copy and fresh read Summary

The existing run job now sends a brightness/contrast/black-and-white adjusted copy (browser-identical filter maths, made only in the throwaway work dir) when the claimed row carries non-neutral adjustments, and re-reads the pages instead of reusing stored text when the row is a fresh read.

## Commits

- b24d3db feat(15-02): adjustments value object and GD copy adjuster
- 397d3c4 feat(15-02): run job applies adjustments to the AI copy and honours fresh_read

## Verification

- AiCopyAdjusterTest (11) and UploadBatchRunJobTest (9) pass; RunExtractionJobTest, TwoStepRunTest, TranscriptionStepTest, WaitingRunTest, AiJobLeakTest unchanged and green (122 tests together).
- Image, PDF (every page) and HEIC (converted JPEG) adjusted; stored original sha256 unchanged; neutral/null sends byte-identical bytes.
- docker compose project fh1502 torn down.

## Deviations from Plan

**1. Test detail:** the HEIC case uses a fake heif-dec (as RunExtractionJobTest does) instead of the real fixture, because the decoder binary is not guaranteed in the test image.

TDD note: the adjuster implementation and its tests were written in one pass (tests passed first run); no separate RED commit.

## User documentation

No user-visible change: worker internals; the controls come in 15-07 and are documented in 15-08. No resources/manual files touched.

## Known Stubs

None.

## Self-Check: PASSED
