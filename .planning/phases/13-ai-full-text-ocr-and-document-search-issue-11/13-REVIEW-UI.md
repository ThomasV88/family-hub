---
phase: 13-ai-full-text-ocr-and-document-search-issue-11
reviewed: 2026-11-17T00:00:00Z
depth: deep
files_reviewed: 20
files_reviewed_list:
  - lang/en.json
  - lang/pl.json
  - lang/sk.json
  - resources/js/Components/Settings/AuditTable.vue
  - resources/js/Health/Ai/AiNoticeDialog.vue
  - resources/js/Health/Ai/AiStatusTag.vue
  - resources/js/Health/Ai/RunAiControl.vue
  - resources/js/Health/AiConsentControl.vue
  - resources/js/Health/DocumentPanel.vue
  - resources/js/Health/PersonHeader.vue
  - resources/js/Health/Search/DocumentSearch.vue
  - resources/js/Health/Search/SearchResults.vue
  - resources/js/Health/Search/SearchSnippet.vue
  - resources/js/Health/Search/useDocumentSearch.js
  - resources/js/Health/Text/RecognisedText.vue
  - resources/js/Health/Text/recognisedText.js
  - resources/js/Pages/Health/Person.vue
  - resources/js/Security/securityFetch.js
  - resources/js/Settings/AiAgents/BulkHistoryTable.vue
  - resources/js/Settings/AiAgents/BulkRegenPanel.vue
  - resources/js/Settings/AiAgents/useBulkStatus.js
findings:
  critical: 0
  warning: 7
  info: 6
  total: 13
status: issues_found
---

# Phase 13: Code Review Report (frontend)

**Reviewed:** 2026-11-17
**Depth:** deep
**Files Reviewed:** 20 (plus Health/Ai/useAiStatus.js, Security/staleGate.js, healthRefusal.js, TimelineList.vue, sw.js read for context)

## Summary

The privacy core is sound. The query is sent only as a POST body. It is never put in the URL, in Inertia props, in storage or in the service worker (the worker is precache-only and never caches fetches). Snippets and recognised text are drawn by interpolation, with no `v-html`. The `securityFetch` change is minimal and correct: the signal is only added to `init`, and an abort resolves as `aborted`. The search composable guards stale answers with a `seq` counter, aborts the previous request and de-duplicates by `documentId`. i18n has full key parity (2846 keys in en, sk and pl). Every key used in the reviewed files exists. The plural forms (2 for en, 4 for sk and pl) follow the existing pattern.

No blockers. The real defects are:

- Stale recognised text stays on screen after the server has deleted it. The text is deleted by a consent turn-off or a removal, but the search snippets and an open text section keep showing it.
- Cancelling "Confirm it's you" wipes the search query.
- Several wording contradictions ("Nothing was saved" next to "the text was saved").
- The bulk `no_text` confirm dialog reuses the per-document notice. That notice promises suggestions the run will not make.

## Warnings

### WR-01: Search results keep showing snippets of text that was just deleted (consent turn-off, per-document removal)

**File:** `resources/js/Health/Search/useDocumentSearch.js` (whole composable), `resources/js/Health/Text/RecognisedText.vue:113-126`, `resources/js/Health/AiConsentControl.vue:80-84`
**Issue:** Search results exist only in `DocumentSearch` memory. Nothing tells them that the text behind a snippet was purged. Scenario: Anna searches "metformin" and gets results with snippets. She opens a document, presses "Remove recognised text" and confirms, or turns AI consent off and confirms the purge dialog. `router.reload({only:['documents','search']})` and `router.patch` do not touch the results list. The `<mark>` snippets of the deleted text stay on screen. The panel closes and the stale list is back, and the member sees exactly the text the dialog said was deleted. The same holds for the "page_text purged" case after `ai-consent` is switched off.
**Fix:** Make the search view react to the event. Either expose `search.rescope()` and call it from `Person.vue` when `person.aiConsent` or `search.enabled` changes, or emit a `text-removed` event from `RecognisedText` up to `DocumentSearch`. A minimal version in `Person.vue`:
```js
watch(() => [props.person?.aiConsent, props.search.enabled], () => searchRef.value?.refresh());
```
where `refresh` calls `rescope()` (a rerun of the same query), and also run it after a successful `remove()`.

### WR-02: Closing "Confirm it's you" erases the typed search query

**File:** `resources/js/Health/Search/useDocumentSearch.js:121-125` and `:154-158`, `resources/js/Health/healthRefusal.js:17`
**Issue:** `securityFetch(..., {replay:true})` resolves `status:423, error:'cancelled'` when the member dismisses the dialog. `handleHealthRefusal('cancelled')` returns `true` without navigating ("stay put, nothing was sent"). The composable treats `true` as "left the page" and runs `reset('idle'); query.value = ''`. Scenario: the idle gate opens after a long pause, the member presses Esc, and the search box and results are blanked. This contradicts the helper's contract. For a real navigation (`confirm_required` and so on) the query clearing is harmless, because the page is leaving anyway.
**Fix:** Only clear for real refusals, and for `cancelled` keep the query and go back to a usable state:
```js
if (result.status === 423) {
    if (result.error === 'cancelled') { state.value = items.value.length ? 'results' : 'idle'; return; }
    if (handleHealthRefusal(result.error)) { reset('idle'); query.value = ''; return; }
}
```
Do the same in `loadMore` (there the existing items stay, so just return).

### WR-03: Contradictory run-state text: "Nothing was saved" next to "the text was saved"

**File:** `resources/js/Health/Ai/RunAiControl.vue:117-122` and `:125-129`, `lang/en.json` (`health.ai.status.failed_detail`, `quota_timeout_detail`, `quota_exhausted_detail`, `cancelled_detail`, tag `health.ai.status.failed`)
**Issue:** With `status === 'failed'` and `textSaved === true`, `detail` shows "AI could not read this document. Nothing was saved. ..." and `extraDetail` shows "Only the suggestions are missing: the recognised text was saved". The tag still says "AI could not read this". `quota_timeout` and `quota_exhausted` have the same clash. For `cancelled` after step 1, the detail says "Nothing was saved." and there is no `extraDetail` case at all, although the text was stored and is searchable. `TimelineList` shows the same "AI could not read this" tag with no qualification. A member is told two opposite things about stored medical text.
**Fix:** When `textSaved` is true, use different detail keys that do not say "Nothing was saved" (for example `failed_after_text_detail`, `quota_after_text_detail`, `cancelled_after_text_detail`) in all three languages, and add the `cancelled` case to `extraDetail`.

### WR-04: Bulk `no_text` confirm dialog reuses the per-document notice, which promises suggestions

**File:** `resources/js/Settings/AiAgents/BulkRegenPanel.vue:139-147` and `:459-461`, `lang/en.json` (`health.ai.run.notice_text_body`, `health.ai.notice.*_text_body`)
**Issue:** The confirm dialog for a bulk run shows `textNoticeKey`, the single-document notice body. That body ends "You will check every suggestion before anything is saved. You will only see this notice once." For scope `no_text` the next paragraph (`no_text_note`) says "No suggestions will be made." The two paragraphs contradict each other in one dialog. "This document" and "You will only see this notice once" are also wrong in a bulk context ("these documents"; the github and multi variants say "again only if another service is added").
**Fix:** Add dedicated bulk text-notice keys (`settings.ai_bulk.text_notice_*`) that state the storage and two-step facts without the suggestion and "this document" sentences, in en, sk and pl. Alternatively hide the notice paragraph that mentions suggestions when `confirmTextOnly`.

### WR-05: An open recognised-text section is not disposed when the server deletes the text

**File:** `resources/js/Health/Text/RecognisedText.vue:78-87` and `:55-63`
**Issue:** `drop()` runs on collapse, on document change and on 403/404/423 of the lazy fetch. When `document.hasText` turns false because of a reload (consent turn-off purge, a removal in another tab, a bulk purge), the section is hidden by `v-if="visible"` and `showDisclosure`, but `data`, the full pages array, stays in the component's memory until the panel closes. The text exists in memory after the purge, which the privacy contract ("dropped on collapse, document change, panel close") does not cover. If the text is later saved again, `expanded` is still true and the old `data` shows without a fetch.
**Fix:**
```js
watch(() => Boolean(props.document.hasText) || props.textSaved, (now) => { if (!now) drop(); });
```

### WR-06: Double submit of the notice confirm and a stale 409 loop in `RunAiControl`

**File:** `resources/js/Health/Ai/RunAiControl.vue:156-175` (`confirmNotice`, `send`), `:176-185`
**Issue:** `begin()` guards `busy`, but `confirmNotice()` and the re-run confirm call `send()` directly, and `send()` has no `busy` check. A fast double click on "Run AI" in the notice dialog fires two POSTs (the dialog is closed only on the next tick); the second one comes back 409 `active`, which is harmless but noisy. More important: the `acknowledged` computed is `ackLocal || props.ai.noticeAcknowledged`. After a 409 `notice_required` the code sets `ackLocal=false` and opens the dialog. If the member cancels the dialog, `props.ai.noticeAcknowledged` is still true. The next "Run AI" press therefore skips the dialog, sends a request, and gets another 409 before the dialog reopens. This costs one round trip per press and flashes the busy state. The text-notice case (page loaded with the old ack, the server wants the text ack) always takes this path.
**Fix:** Put `if (busy.value) return;` at the top of `send()`. Keep a `noticeForced` ref that is set on 409 `notice_required` and cleared on a successful send. Make `proceed()` open the dialog while it is set: `if (!acknowledged.value || noticeForced.value)`.

### WR-07: Live-region announcement for "text saved" can be overwritten

**File:** `resources/js/Health/Ai/RunAiControl.vue:131-153`
**Issue:** If a poll reports a run as `running` and `textSaved` together for the first time (a fast step 1, or the first poll after `queued`), both watchers fire in the same flush: `announceSaved()` and the status watcher's `announce_running`. They share one `announcement` ref, and the last call wins ("The run started"). The "text saved" message (which `savedAnnounced` then marks as done) is never heard, so the once-only guard turns a lost message into a permanent one.
**Fix:** In the status watcher, skip `announce_running` when `textSaved.value` is already true, or queue messages and announce them in order.

## Info

### IN-01: Consent turn-off confirm is not tied to this person's text

**File:** `resources/js/Pages/Health/Person.vue:129` (`:text-stored="Boolean(search.enabled)"`), `app/Medical/PersonPage.php:125`
**Issue:** `search.enabled` is `config('ai.ocr.enabled') || anyVisible($viewer)`: global flag or any visible document of any person. The turn-off dialog says "The text read from this person's documents will also be deleted" even when this person has none. This is only a harmless extra confirm and an inaccurate sentence, not a leak.
**Fix:** Expose `search.personHasText` (count of this person's documents with text) and pass that as `purgesText`.

### IN-02: The partial-reading tag appears only after the text is expanded and loaded

**File:** `resources/js/Health/Text/RecognisedText.vue:48-52` and `:164-166`
**Issue:** `partial` is computed from the lazily fetched `data`, so a collapsed header never shows "First n of N pages" (UI-SPEC shows it in the header). The "may contain errors" tag does show collapsed. The search results do show the partial note.
**Fix:** Read `document.textComplete`, `pagesRead` and `pagesTotal` (already in the search item shape) from the document prop for the collapsed state.

### IN-03: No Slovak or Polish plural handling for `health.search.partial` and `rereading` counts

**File:** `lang/en.json` and `sk.json`, `pl.json` (`health.search.partial`), `SearchResults.vue:127`
**Issue:** The key is called with a plural count (`t(key, {n}, n)`) but has a single form: "Only the first {n} pages". For a one-page cap, en reads "first 1 pages". The sk and pl strings hard-code the paucal "strany" and "strony". The same holds for the `tag_partial` and `partial_alert` strings.
**Fix:** Make them plural messages with the four-form sk and pl pattern, or word them without the count ("Only part of the document is searched").

### IN-04: `<mark>` hit may be clamped out of view

**File:** `resources/js/Health/Search/SearchSnippet.vue:17-24`
**Issue:** The snippet is clamped to 3 lines with `overflow: hidden`. At 320 px width and +40 % text, or with long unbroken tokens (`overflow-wrap: anywhere`), a hit near the end of the server snippet can be cut off, so a result appears with no visible match. The mark is bold plus background (not colour alone), which is good. A screen reader announces no "highlight" semantics.
**Fix:** Raise the clamp at narrow container widths, or have the server centre the hit. Add `aria-label`-free hidden text such as "match:" is optional.

### IN-05: Search feedback and rate-limit behaviour

**File:** `resources/js/Health/Search/useDocumentSearch.js:84-88` and `:150`
**Issue:** (a) During the 5 s cooldown after a 429, pressing Enter or Search sets `deferred` and does nothing visible, with no message and no spinner for up to 5 s. (b) A 429 on Load more does not start the cooldown. "Try again" can be hammered. (c) The cooldown ignores the server's `Retry-After` (`result.minutes` is computed in `securityFetch` and unused). (d) If a gate refusal arrived just before an abort, the "Confirm it's you" dialog still opens for the superseded request.
**Fix:** Show the throttled alert while `cooling`, start the cooldown for the more-error too, and use `Retry-After` when present.

### IN-06: Large text, modifier clicks and caching defaults

**File:** `resources/js/Health/Text/RecognisedText.vue:197-203`, `resources/js/Health/Search/SearchResults.vue:72-75`, `resources/js/Security/securityFetch.js:44-50`
**Issue:** (a) Up to 200 000 characters go into one `<p>` with `white-space: pre-wrap` in a 360 px scrolling box. One text node renders acceptably, but there is no truncation. `copyFormat` and the DOM duplicate the string. A guard such as "show the first N pages, load more" is optional. (b) `onNavigate` calls `preventDefault` for every click on a result row, so Ctrl or Cmd or middle click cannot open the document in a new tab. Check `event.ctrlKey || event.metaKey || event.button !== 0` and let those through. (c) The lazy text GET relies on the server's `no-store`. The client sends no `cache: 'no-store'`, so a heuristic browser cache entry is possible if the header is ever dropped. Passing `cache: 'no-store'` for GET calls to the text route is cheap defence in depth.
**Fix:** As described in each item.

---

_Reviewed: 2026-11-17_
_Reviewer: Claude (gsd-code-reviewer)_
_Depth: deep_
