---
phase: 08-ai-extraction-platform
plan: 03
subsystem: ai-extraction
tags: [json-schema, validation, prompt, medical]
requires: []
provides:
  - extraction schema v1 (resources/ai/extraction.v1.schema.json)
  - ExtractionValidator (fromFile, validate, ruleTable), ExtractionOutcome, FailureCode
  - ProposalEntries key helper, PlainText helper, PromptBuilder and static prompt v1
affects: [08-10, 08-12, 08-15]
tech-stack:
  added: []
  patterns: [hand-written closed-shape validator with drift test against the schema file]
key-files:
  created:
    - resources/ai/extraction.v1.schema.json
    - resources/ai/prompt.v1.md
    - app/Medical/Ai/ExtractionSchema.php
    - app/Medical/Ai/ExtractionValidator.php
    - app/Medical/Ai/ExtractionOutcome.php
    - app/Medical/Ai/FailureCode.php
    - app/Medical/Ai/ProposalEntries.php
    - app/Medical/Ai/PromptBuilder.php
    - app/Medical/PlainText.php
    - tests/Unit/Ai/ExtractionValidatorTest.php
    - tests/Unit/Ai/PromptBuilderTest.php
  modified:
    - app/Medical/Export/MarkdownFormat.php
decisions:
  - "PlainText::clean takes an optional second argument $keepNewlines (default true) so MarkdownFormat keeps both of its modes; contract signature clean(?string) is a subset."
  - "Validator and PromptBuilder are instance classes (called as new X)->method(), matching the plan's fromFile/build signatures; ruleTable() is static."
  - "A date field of the wrong type (not string/null) is invalid_output; a well-typed but bad or implausible date becomes null."
metrics:
  tasks: 2
  completed: 2026-11-14
---

# Phase 8 Plan 03: Extraction contract Summary

Closed strict-mode schema v1, hand-written server-side validator and normaliser, entry key helper, content-free failure enum and a static untrusted-data prompt, all pure PHP.

## Commits

- c71ab53 feat(08-03): extraction schema v1, validator, entry keys and PlainText
- feat(08-03): static extraction prompt with untrusted-data framing
- style(08-03): pint

## Verification

- Validator, drift, PromptBuilder and ExportFormat tests green (35 and 27 tests in per-task runs).
- Full Pest: 2083 passed on the second run. The first run had 2 failures in MedicalPrivacyTest (passes alone, 6/6), caused by sibling plans editing lang/*.json while the suite ran; re-run was fully green.
- Pint applied to app/Medical and my tests (one style fix, committed). Vitest and `npm run build` were not run (instructed Pest and Pint only; no JS touched).

## Deviations from Plan

1. **[Acceptance criterion miscount]** The criterion `grep -c 'additionalProperties": false'` at least 6 cannot be met: the schema has exactly 5 objects (root plus visit, medication, allergy, vaccination), all closed. The drift test asserts closed shape on every object instead.
2. **[TDD ordering]** Tests and implementation were written together and the first run was already green, so there is no separate RED commit; per-violation behaviour is covered by 19 parametrised violation cases plus the golden, normalisation, key and drift tests. Both tasks are single feat commits.
3. **[Shared tree]** My first commit landed after a sibling's 08-04 commit with no cross-contamination (verified file list of c71ab53).

## Known Stubs

None.

## Threat Flags

None. All five threat register items (T-08-08..12) are mitigated: size check before read, depth 8, 25 items, 4000 chars, PlainText on every string, no value in any message, any violation gives a payload-less failure.

## Self-Check: PASSED
