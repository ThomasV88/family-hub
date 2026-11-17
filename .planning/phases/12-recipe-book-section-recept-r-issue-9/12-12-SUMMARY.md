---
phase: 12-recipe-book-section-recept-r-issue-9
plan: 12
subsystem: recipes-import
tags: [recipes, import, html-reduction, json-ld, ssrf, prompt-injection]
requires: ["12-02"]
provides:
  - "RecipePageReducer::reduce(FetchResult): ReducedPage (kind json_ld | microdata | text, text, cut)"
  - "PageRejected with reason codes not_html, too_large, empty"
  - "SafeFetcher optional trailing ?string $userAgent (default FamilyHubImageFetcher/1.0)"
affects: [12-13]
key-files:
  created:
    - app/Recipes/Import/RecipePageReducer.php
    - app/Recipes/Import/ReducedPage.php
    - app/Recipes/Import/PageRejected.php
    - tests/Unit/Recipes/RecipePageReducerTest.php
    - tests/Feature/Wishlist/SafeFetcherUserAgentTest.php
    - tests/fixtures/recipes/ (8 synthetic HTML pages)
  modified:
    - app/Wishlist/Fetch/SafeFetcher.php
metrics:
  tasks: 2
  completed: 2026-11-17
---

# Phase 12 Plan 12: RecipePageReducer and fetcher user agent Summary

A fetched page now becomes short, sanitised, labelled text (schema.org Recipe JSON-LD first, including `@graph`, HowToStep and HowToSection and ISO 8601 durations; then microdata; then readable text from recipe, article or main) or a `PageRejected` reason code. `SafeFetcher` gained an optional trailing `$userAgent`; with no argument the request is byte for byte what it was.

## Commits
- 4855389 reducer, value object, rejection exception, first fixtures (`Refs #9`)
- b8af9ec remaining fixtures, SafeFetcher user agent, tests (`Refs #9`)

## Tests
Isolated compose project `fh1212` (own Postgres, scratch folder over storage/logs), serial, removed with `down -v`. Pint on touched PHP: pass. `RecipePageReducerTest` (14) and `SafeFetcherUserAgentTest` (2) green. Full serial Pest: 3527 passed, 1 skipped (all wishlist fetch tests unchanged and green).

## User documentation
No user-visible change: backend only, no lang, help, manual or JS file touched.

## Security notes
- SafeFetcher: only the constructor (one optional parameter) and the `User-Agent` header value changed; DNS pinning, IP policy, redirects, byte cap and every existing test are untouched.
- Reducer: `LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING`, never NOENT or DTDLOAD (a test with an external entity proves nothing is read); libxml error state restored.
- Gzip is inflated incrementally in 1 KiB steps and stops at `decoded_max_bytes` (so a bomb never allocates much more than the cap and is distinguishable as `too_large` from a corrupt stream, which `zlib_decode` with a limit cannot do).
- Hidden-text removal happens before microdata and text extraction: comments, processing instructions, script, style, noscript, template, svg, iframe, form, nav, header, footer, aside, button, select, `hidden`, `aria-hidden="true"`, inline `display:none`, `visibility:hidden`, `font-size:0`, plus `opacity:0`, large negative `left` or `text-indent` and the classes hidden, d-none, invisible, sr-only, visually-hidden, screen-reader-text. The canary fixture proves none of it reaches the text.
- Sanitising removes all Unicode control, format (zero-width, bidi, tag), private-use characters, NFC-normalises, collapses whitespace, breaks up `<<<`, cuts on a line boundary. JSON-LD text (not hidden by nature) is data for the AI, handled by the 12-11 prompt.
- `PageRejected` holds only a code; no logging in `app/Recipes/Import` (arch rule from 12-11 now active, passes).

## Deviations from Plan

**1. [Rule 3 - Blocking] mbstring has no Windows-1250 in this PHP build**
- **Issue:** `mb_convert_encoding(..., 'Windows-1250')` throws `ValueError` here, so the charset test fixtures could not be generated with mbstring, and production decoding must use the iconv path.
- **Fix:** The reducer already falls back to `iconv //IGNORE` on a `ValueError` (as the plan specifies); tests generate the windows-1250 and ISO-8859-2 bodies with `iconv`.

**2. ISO 8601 durations parsed with `DateInterval`** instead of `CarbonInterval::make` (same result in minutes, no dependency on Carbon's string parsing, garbage and `PT` ignored in try/catch).

**3.** The plan's two tasks were committed as two commits, but the reducer file (steps 1-8) was written in one go, so the first commit already contains the readable-text fallback; the second commit holds its fixtures, tests and the SafeFetcher change.

## Known Stubs
None.

## Threat Flags
None beyond the plan's threat model (T-12-47 to T-12-50).

## Self-Check: PASSED
