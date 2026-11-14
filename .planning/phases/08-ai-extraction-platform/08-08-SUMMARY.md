---
phase: 08-ai-extraction-platform
plan: 08
subsystem: ai
tags: [poppler, pdf, preflight, workdir, subscription-only]
requires: ["08-02", "08-05"]
provides:
  - PdfRasterizer (pageCount, page, pages) as the only pdfinfo/pdftoppm seam
  - WorkDir (per-job 0700 dir, name-restricted put, cleanup, sweepStale)
  - Preflight and ai:preflight (codes only)
  - MedicalFileFixtures::pdfPages(n)
affects: [08-12, 08-22]
key-files:
  created:
    - app/Medical/Documents/PdfRasterizer.php
    - app/Medical/Ai/Work/WorkDir.php
    - app/Medical/Ai/Preflight.php
    - app/Console/Commands/AiPreflight.php
    - tests/Unit/Ai/PdfRasterizerTest.php
    - tests/Unit/Ai/WorkDirTest.php
    - tests/Unit/Ai/PreflightTest.php
  modified:
    - tests/Support/MedicalFileFixtures.php
    - tests/Unit/Arch/AiArchTest.php
requirements-completed: [AI-01, AI-02, AI-04]
completed: 2026-11-14
---

# Phase 8 Plan 08: PDF rasterizer, work dir, preflight Summary

Bounded PDF rasterization (argv array behind `ulimit -v`, timeout, kill on expiry), a per-job 0700 work dir with a stale sweep, and `ai:preflight` that prints problem codes only.

## Commits
- 30ed5ec feat(08-08): add bounded PdfRasterizer and multi-page PDF fixture
- 6acb6ea feat(08-08): add work dir lifecycle and ai:preflight

## Verification
- PdfRasterizerTest (5), WorkDirTest (3), PreflightTest (8) and AiArchTest (4) pass; Pint clean on all plan files.
- Full Pest: 2112 passed, 4 failed, all in tests/Feature/Health/RecordVerifyTest.php (`App\Audit\AuditEntry` not found, belongs to parallel plan 08-09). None in this plan's files.
- Vitest and `npm run build` not run (PHP-only plan in a shared tree; no frontend files touched). Full Pest took about 186 s with the default 512M limit overridden to 1G on the command line; phpunit.xml untouched.

## Deviations from Plan
- [Rule 3] `tests/Unit/Arch/AiArchTest.php` (not in files_modified): added `Medical/Ai/Preflight.php` to `AI_ARCH_PROCESS_ALLOWED`. The contract requires Preflight to run `codex --version` via a Process, which the 08-05 arch rule otherwise forbids.
- `WorkDir` gained two public statics not in the contract: `makeTempDir()` (private scratch dir under sys temp for the rasterizer) and `removeTree()`. `file()` and `put()` return the path (put returns the file path).

## Provisional decisions
- Rasterizer does not use setsid; `exec` replaces the shell, so a SIGKILL on the pid is enough. Unit-tested with a 1 ms timeout and pgrep.
- `pages()` renders all k pages in one pdftoppm call into a private temp dir and renames to `page-N.jpg` (handles poppler's zero-padded names); on any failure returns `written: []` and removes partial files.
- Version check compares the first x.y.z token of `codex --version` to `ai.codex.version`.
- `codex_home_in_tmp` checks both sys_get_temp_dir() and /tmp.
- Preflight tests use storage/framework/testing for writable dirs, since the temp dir is excluded for codex home.

## Known Stubs
None.

## Self-Check: PASSED
