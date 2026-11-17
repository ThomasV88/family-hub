---
phase: 13-ai-full-text-ocr-and-document-search-issue-11
plan: 05
subsystem: medical-document-search-ui
tags: [vue, search, accessibility, i18n, help, manual]
requirements: [GH11-SC4, GH11-SC5]
key-files:
  created:
    - resources/js/Health/Search/DocumentSearch.vue
    - resources/js/Health/Search/SearchResults.vue
    - resources/js/Health/Search/SearchSnippet.vue
    - resources/js/Health/Search/useDocumentSearch.js
    - resources/js/Health/Text/RecognisedText.vue
    - resources/js/Health/Text/recognisedText.js
    - tests/js/health/DocumentSearch.test.js
    - tests/js/health/RecognisedText.test.js
    - tests/js/i18n-phase13.test.js
  modified:
    - resources/js/Pages/Health/Person.vue
    - resources/js/Health/DocumentPanel.vue
    - resources/js/Security/securityFetch.js
    - lang/{en,sk,pl}.json
    - resources/help/{topics,en,sk,pl}.json
    - resources/manual/{en,sk,pl}/health.json
    - tests/js/health/a11y-health.test.js
    - tests/js/i18n-phase7.test.js
---

# Phase 13 Plan 05: Search UI and recognised text view Summary

A search box on the Health person page (name and recognised text, scoped to all people or the open person) and a lazy, copyable, editor-removable "Recognised text" section in the document panel, with copy, two help tips and two manual sections in en, sk and pl. The query and the text only ever live in component memory.

## Commits
- 1862e0f search box, results and snippets on the person page (plus `lang` keys for both features)
- 362e15e recognised text section in the document panel
- 659f13e help tips, manual sections, parity test, a11y sweep states

## What was built
- `useDocumentSearch.js`: state machine `idle | too_short | loading | results | empty | error | throttled`, 300 ms debounce, immediate submit, request counter plus `AbortController` (a late answer is ignored), the same in-flight search is never sent twice, Load more with its own guard and dedupe, 429 pause of 5 s (a search asked during the pause runs when it ends), 423 through `handleHealthRefusal` clears everything, 419/403/404 get their own texts. No router, history, location, storage or cache use (spied and source scanned).
- `DocumentSearch.vue` (`role="search"` form, hidden-label input with icon, own clear X, scope select only with more than one person, idle hint with `HelpTip health.search`, one polite status line with counts only), `SearchResults.vue` (heading, uncertain alert, skeleton or progress bar, rows as real links with title, person, date, visit, page, up to two snippets, partial note, empty/error/throttled states, Load more with focus on the first new row), `SearchSnippet.vue` (`{text, hit}` segments to `<span>`/`<mark>`, interpolation only).
- `Person.vue`: `DocumentSearch` mounted once in the main column above the view switch when `search.enabled`; while results are active they replace the view switch, folders and timeline. A result of the open person opens its panel through the existing `go()`; one of another person goes to that person's page with `preserveState`. `search.textReliability` is passed to the document panel.
- `RecognisedText.vue` + `recognisedText.js` (`copyFormat`, pinned by test): collapsed disclosure (`aria-expanded`, `aria-controls`), GET on expand (`replay`, no query string), dropped on collapse or document change, stale-answer guard, pages as plain `pre-wrap` text with page labels only for multi-page texts, "Nothing legible on this page.", tags and partial alert, copy with toasts and clipboard failure, remove (editors only) behind the danger confirm with DELETE, toast, section hidden, page props reloaded, focus to Run AI; 403/404 on load make the section disappear, 500/429/network show the load failure with Try again, 419 a session text, 423 `handleHealthRefusal`; "being read" and "earlier text" lines while a run is active. Mounted in `DocumentPanel.vue` after `RunAiControl`.
- Help tips `health.search` and `health.document_text` (each under 400 characters), screen labels `document_search` and `document_text`; manual sections `search` and `recognised-text` after `documents` (existing sections, including `ai`, untouched).

## Tests
Isolated compose project `fh1305` (own Postgres 18, scratch folder over storage/logs via an override file, serial), removed with `down -v`.
- New Vitest: `DocumentSearch.test.js` (42 incl. person page mount), `RecognisedText.test.js` (31), `i18n-phase13.test.js`; a11y sweep gained 10 states x (en/sk/pl x light/dark + 320px + +40% text) and passes (1355 tests in `a11y-health.test.js`).
- Full Vitest after the last change: 192 files; the one failure found in the full run (`i18n-phase7` plural-key census, now 11 health plural keys) was fixed and that file re-run green (196). ManualCoverage, HelpCoverage and `tests/js/health` green. `npm run build` incl. SW precache check: OK.
- Pest `tests/Feature/Manual tests/Feature/Help`: 49 passed. Full serial Pest (one clean run, after the last commit): 3872 passed, 1 skipped, 0 failed (36185 assertions). No PHP file touched, so no Pint run needed.

## Decisions
- `securityFetch` accepts an optional `signal` and answers `{error: 'aborted'}` for an aborted request (additive; the plan names AbortController and securityFetch).
- The search input has a visually hidden label (plus placeholder) on every width instead of a visible label on phone; the field is a labelled search landmark.
- While a query is active the view switch is hidden together with the folders/timeline (the plan places the box above the view switch and says the results replace the views); add buttons live in the person header and stay reachable.
- Scope values are `all`/`person`; the person id is read at send time and a person-scoped search is forgotten when another person is opened ("All people" survives).
- A removed or missing recognised text triggers `router.reload({only: ['documents', 'search']})` so `hasText` and the search flag follow the server.
- Tag "May contain errors" is shown in the header as soon as `textReliability` is `uncertain` (UI-SPEC: header tag), before the text is expanded.

## Deviations
1. [Rule 3] `tests/js/i18n-phase7.test.js` counted plural keys under `health.*`; the new plural `health.search.heading` (checked in `i18n-phase13.test.js`) is excluded the same way `health.ai.*` is.
2. [Rule 3] `securityFetch.js` (not in the plan's file list) gained the `signal` option, see Decisions.
3. [Rule 2] Extra copy keys for HTTP failures the UI-SPEC has no row for: `health.search.error_session`, `error_forbidden`, `results_aria`, `searching`; `health.document_text.error_session`, `loading` (en source; sk and pl drafts). Reason: the review lessons require real texts for 419/403/404 and an accessible name for the progress bar and loading skeleton.
4. TDD: the component code was drafted before the test files were finished and both went into the same commit per task, so there is no separate RED commit.
5. Only 13-05 scope was built: no RunAiControl or AiStatusTag change, no `text_saved`, no notice texts, no consent sentence, no bulk `no_text` scope, no rewording of the manual `ai` section (13-06 to 13-09).

## User documentation
Touched: `resources/manual/en/health.json`, `resources/manual/sk/health.json`, `resources/manual/pl/health.json` (sections `search` and `recognised-text`, same ids, shapes and links); `resources/help/topics.json`, `resources/help/en.json`, `resources/help/sk.json`, `resources/help/pl.json` (`health.search`, `health.document_text`, screens `document_search`, `document_text`).

**Needs native review: every Slovak and Polish text of this plan** (`lang/sk.json`, `lang/pl.json` keys under `health.search.*` and `health.document_text.*`, `resources/help/sk.json`, `resources/help/pl.json`, `resources/manual/sk/health.json`, `resources/manual/pl/health.json`). The UI copy follows the UI-SPEC draft columns; the extra keys from deviation 3, the help texts and the manual sections are new drafts.

## Left for 13-06 to 13-09
RunAiControl states and the two-step run detail lines, `AiStatusTag` `text_saved`, notice dialog texts and the `text_notice` acknowledgment, the consent turn-off sentence, bulk scope `no_text` and its settings tip text, the rewording of the manual `ai` section and of `health.ai_run`.

## Known stubs
None. The search is inert until text rows exist or `ai.ocr.enabled` is on (13-04 `search.enabled`); tests use fixture answers.

## Threat flags
None beyond the plan's register: T-13-26 (no `v-html`, hostile titles and text tested, source scan), T-13-27 (spied router/history/storage, text dropped on collapse), T-13-28 (live region counts only), T-13-29 (Remove not rendered without edit), T-13-30 (one empty state, 403/404 identical).

## Self-Check: PASSED
Created files exist; commits 1862e0f, 362e15e and 659f13e are in `git log`; the fh1305 project and its volumes are removed.
