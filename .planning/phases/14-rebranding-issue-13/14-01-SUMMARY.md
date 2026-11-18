---
phase: 14-rebranding-issue-13
plan: 01
subsystem: design-tokens
tags: [branding, tokens, wcag, css]
requires: []
provides: [brand colour tokens light and dark, brand notes]
affects: [all screens via tokens]
key-files:
  created: [.planning/phases/14-rebranding-issue-13/14-BRAND-NOTES.md]
  modified: [resources/css/tokens.css, resources/js/Layouts/PrintLayout.vue, tests/js/tokens.test.js, .claude/skills/sketch-findings-family-hub/SKILL.md]
decisions:
  - "Light primary is deep orange #a64b0b (AA); raw #f2963e only as accent and dark-theme primary"
metrics:
  tasks: 2
  completed: 2026-11-18
---

# Phase 14 Plan 01: Brand colour tokens Summary

FamilyHUB orange palette in the token layer (light primary #a64b0b, dark primary #f2963e), AA verified for every TOKEN_PAIRS pair in both themes, typography untouched.

## Tasks

1. Brand guide read (all 9 pages extracted from the PDF's embedded JPEGs into the scratchpad) and recorded in 14-BRAND-NOTES.md: commit 5a432c4.
2. Tokens, print override, token test and design skill note: commit 4ff62b8.

Vitest (tokens, theme, StyleGuide, a11y-sweep): 384 tests pass.

## Deviations from Plan

None to the tokens: the guide confirmed the pre-computed values. One note: guide page 8 says to use mono-for-dark assets on backgrounds above 50% luminance, which contradicts the file (white) and page 4; recorded in the notes, we follow the files (white on dark). I also updated the print override's `--color-primary-hover` to #8c3f09 (it was the old blue hover; leaving it would have kept a blue tone in print).

## User documentation

No user-visible change beyond colour; no manual or help text describes colours.

## Self-Check: PASSED
