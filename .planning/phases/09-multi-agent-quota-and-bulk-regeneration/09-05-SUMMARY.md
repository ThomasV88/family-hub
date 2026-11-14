---
phase: 09-multi-agent-quota-and-bulk-regeneration
plan: 05
subsystem: medical-ai
tags: [copilot, repair-loop, validation, prompt]
requires: []
provides: [JsonReply::decode, ExtractionValidator::inspect, PromptBuilder::buildInline]
affects: [09-07]
key-files:
  created: [app/Medical/Ai/JsonReply.php, resources/ai/prompt.inline-json.v1.md, tests/Unit/Ai/JsonReplyTest.php]
  modified: [app/Medical/Ai/ExtractionValidator.php, app/Medical/Ai/PromptBuilder.php, tests/Unit/Ai/ExtractionValidatorTest.php, tests/Unit/Ai/PromptBuilderTest.php]
decisions:
  - "inspect() returns validate()'s outcome unchanged; date_format is reported as an issue even though validate() nulls such dates"
  - "keys_missing/keys_unexpected carry the object path (visits[0]:keys_missing:notes), root-level ones use root:"
requirements: [AI-04]
completed: 2026-11-14
---

# Phase 9 Plan 05: Copilot validation and repair building blocks Summary

Strict single-object reply extraction, content-free structural issue codes from the validator rule table, and an inline-schema prompt with a pattern-filtered repair block.

## Commits
- `feat(09-05)`: all code and tests (single commit; the two tasks share the three test files)

## Verification
Pest in project fh0905: JsonReplyTest, ExtractionValidatorTest, PromptBuilderTest, 41 passed (235 assertions). Pint clean. Project removed with `down -v`.

## Deviations from Plan
- [Choice] The plan's example `root:keys_missing:notes` implies a root path for entry fields. I used the object path (`visits[0]:keys_missing:notes`), which still matches the issue pattern and is more useful for repair; root keys still give `root:keys_missing:<field>`.
- [Choice] `validate()` accepts implausible dates by nulling them, so `inspect` reports `date_format` while the outcome stays ok. Phase 8 behaviour is untouched (all existing fixtures pass).
- TDD done as one pass (tests and code together), no separate RED commit.

## Threat model
T-09-15: codes from the rule table only, filtered by pattern in inspect and again in buildInline; canary test passes. T-09-16: JsonReply removes one fence only, no other repair, size-capped.

## Known Stubs
None.

## Self-Check: PASSED
