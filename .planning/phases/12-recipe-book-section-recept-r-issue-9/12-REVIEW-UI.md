---
phase: 12-recipe-book-section-recept-r-issue-9
reviewed: 2026-11-17T00:00:00Z
depth: deep
files_reviewed: 41
files_reviewed_list:
  - resources/js/Pages/Recipes/Index.vue
  - resources/js/Pages/Recipes/Show.vue
  - resources/js/Pages/Recipes/Form.vue
  - resources/js/Components/Recipes/useRecipeState.js
  - resources/js/Components/Recipes/useImportStatus.js
  - resources/js/Components/Recipes/useWakeLock.js
  - resources/js/Components/Recipes/recipeScale.js
  - resources/js/Components/Recipes/quantityInput.js
  - resources/js/Components/Recipes/shoppingQuantity.js
  - resources/js/Components/Recipes/ingredientPaste.js
  - resources/js/Components/Recipes/recipeFilters.js
  - resources/js/Components/Recipes/recipeFailure.js
  - resources/js/Components/Recipes/recipePhotosApi.js
  - resources/js/Components/Recipes/AddToShoppingDialog.vue
  - resources/js/Components/Recipes/ImportFromUrlDialog.vue
  - resources/js/Components/Recipes/ImportDraftAlerts.vue
  - resources/js/Components/Recipes/ImportDraftRow.vue
  - resources/js/Components/Recipes/NewRecipeDialog.vue
  - resources/js/Components/Recipes/CategoryField.vue
  - resources/js/Components/Recipes/IngredientRow.vue
  - resources/js/Components/Recipes/IngredientRows.vue
  - resources/js/Components/Recipes/RecipeIngredients.vue
  - resources/js/Components/Recipes/RecipeNotes.vue
  - resources/js/Components/Recipes/RecipePhotoGallery.vue
  - resources/js/Components/Recipes/RecipePhotosField.vue
  - resources/js/Components/Recipes/RecipeSteps.vue
  - resources/js/Components/Recipes/RecipeCard.vue
  - resources/js/Components/Recipes/RecipeFilters.vue
  - resources/js/Components/Recipes/ServingsControl.vue
  - resources/js/Components/Recipes/StepRow.vue
  - resources/js/Components/Recipes/StepRows.vue
  - resources/js/Components/Recipes/PhoneProbe.vue
  - resources/js/Shell/BottomNav.vue
  - resources/js/Shell/NavItem.vue
  - resources/js/Shell/modules.js
  - resources/js/Components/ui/UiField.vue
  - resources/js/Components/ui/UiMenu.vue
  - resources/js/Components/ui/UiProgress.vue
  - resources/js/Components/ui/UiToastRegion.vue
  - resources/js/Components/ui/useToast.js
  - resources/js/Settings/AiCalls/AiCallsTable.vue
  - lang/en.json, lang/sk.json, lang/pl.json (recipes.*, nav.*)
findings:
  critical: 0
  warning: 14
  info: 11
  total: 25
status: issues_found
---

# Phase 12: Frontend Code Review Report

**Reviewed:** 2026-11-17
**Depth:** deep (also read RecipePayload.php, RecipeController::prefill and RecipeQuery pagination to check prop contents)
**Files Reviewed:** 41 (plus the three lang files)
**Status:** issues_found

## Summary

The queue in `useRecipeState` is sound for its stated purpose: one request in flight, no `AbortController`, absolute target states, per-row rollback. I found no way for a tap to be aborted, and a 404 leaves a consistent "unavailable" page. The rendering is clean. There is no `v-html`, the source link is limited to http(s), and the Inertia props carry no page text or raw AI output. The `original` line in the review prefill is a validated model-extracted fragment of at most 200 characters and is shown only to its owner as text. The three lang files have identical key sets, every `t('...')` literal resolves, and the 4-form plural rule in `i18n.js` works with the 4-form sk/pl strings I checked.

There are no BLOCKER-level defects. The warnings are real behaviour problems in edge paths: lost tick state around reset, list loss after Back on a paged overview, a leave guard that does not cover Back, number parsing and display errors, an import give-up that leaves the user stuck, and several Slovak and Polish grammar defects.

## Structural Findings (fallow)

None provided.

## Narrative Findings (AI reviewer)

## Warnings

### WR-01: A tick made after "Reset progress" can be swallowed by merging into a job queued before the reset

**File:** `resources/js/Components/Recipes/useRecipeState.js:76-87` (with `reset()` at 99-106)
**Issue:** `mark()` merges a new tap into any *waiting* job for the same key, wherever that job sits in the queue. The queue is shared with `send()` jobs such as reset.
1. Tap ingredient K, and a request is in flight, so the K=true job waits.
2. Confirm Reset, which queues `DELETE /progress` behind it.
3. Tap K again. The tap is merged into the first K job, because `queue.find` returns it. The K=true job runs before the DELETE, the DELETE clears it, and the override is deleted when `counts[K]` reaches 0.

The member ticked K after the reset, yet K ends up unticked on the server and on screen. Only the narrow window in which an earlier tap is still waiting is affected, but it silently loses a mark, which is the property this queue exists to guarantee.
**Fix:** Merge only into a waiting mark job that sits *after* the last `send` job. For example, search from the end and stop at the first non-mark job:
```js
let waitingJob = null;
for (let i = queue.length - 1; i >= 0; i--) {
    if (queue[i].kind !== 'mark') break;
    if (queue[i].key === key) { waitingJob = queue[i]; break; }
}
```

### WR-02: After a 403 or 409 the page reloads, but the tick/done/favourite base state and the servings/notes are not re-synced

**File:** `resources/js/Pages/Recipes/Show.vue:50-59, 68`; `useRecipeState.js:25-27`
**Issue:** `failed()` calls `router.reload()` on 403/409 so that "what the server now says" is shown. `useRecipeState` reads `initial` only once in setup. `servings` and `RecipeNotes.text` are also initialised once, so props that change on reload never reach them. After a 409 ("recipe changed") the ingredient and step lists re-render from fresh props. The ticks still come from the stale `base`, which may include ids that no longer exist and miss ones the server holds. A tick queued for a deleted ingredient id then returns 404 on every attempt. Each 404 sets `gone = true` and replaces the whole page with "unavailable", even though only one ingredient was removed.
**Fix:**
- Treat a mark-row 404 differently from the recipe 404. Check `recipe.ingredients` before setting `gone`, or have the server distinguish "recipe gone" from "row gone".
- After a reload, re-seed the queue with something like `queue.resync({ tickedIngredientIds, doneStepIds, favourite })` when `props.state` changes. This should only be done while the queue is idle.

### WR-03: Back/Forward and swipe-back bypass the form's leave guard

**File:** `resources/js/Pages/Recipes/Form.vue:167-185`
**Issue:** The guard is router.on('before') plus `beforeunload`. The `before` event fires for visits the app starts. A history `popstate` (the browser Back button, or the iOS edge swipe, which is a main way to leave on phone and iPad) is handled by Inertia as a history restore and does not fire `before`. `beforeunload` does not fire either, because no document unload happens. A member who has typed a long recipe, or is reviewing an import draft, loses it silently on Back. Review mode sets `dirty` to true always, so this is the main way to lose a draft.
**Fix:** Add a `popstate` listener that, when `dirty`, calls `history.go(1)` or `history.pushState` to re-establish the entry and opens the leave dialog. Alternatively, push a sentinel history entry on mount and handle `popstate` against it.

### WR-04: The overview loses earlier pages after Back or reload (list shows only the last loaded page)

**File:** `resources/js/Pages/Recipes/Index.vue:39, 54-65, 126-134`
**Issue:** `loadMore` calls `router.get('/recipes?page=N', { replace: true })`. The server returns only the slice for page N (`RecipeQuery::index` uses `offset(($page-1)*$perPage)`). The accumulated `shown` list lives only in component state. Scenario: scroll, press "Load more" so the URL becomes `/recipes?page=2`, open a recipe, press Back. Inertia restores props for `page=2`, and `shown` is initialised from `props.recipes` (slice 2 only). The member sees recipes 25-48 and the first page has vanished. The same happens on a browser refresh. `hasMoreShown` is also wrong in that state.
**Fix:** Either do not put `page` in the address for "load more" (use `preserveUrl: true`, or send it as a non-URL partial request), or have the server return pages 1..N when `page > 1` is requested as a full visit. A simple option is `visit(..., { preserveUrl: true })` for load-more, keeping the address as page 1.

### WR-05: A favourite tap or draft reload can cancel an in-flight filter request (and vice versa)

**File:** `resources/js/Components/Recipes/RecipeCard.vue:44-58`; `Index.vue:75-93, 145-150`
**Issue:** Inertia cancels the previous visit when a new non-async visit starts. Three cases follow.
- A card favourite `router.put` started while a search or filter GET is loading cancels the GET. The skeleton disappears through `onFinish` and the grid shows stale cards under the new chips.
- A filter change while a favourite PUT is in flight aborts the PUT client-side. The optimistic heart stays on (no rollback, because `onCancel` is not handled), and the write may never reach the server.
- `reloadDrafts` can cancel `loadMore` the same way.

This is the aborted-request class that Phase 11 found. Even for `apply()` called twice, the cancelled first visit's `onFinish` sets `loading=false` while the second is still running.
**Fix:** Use `async: true` for the favourite PUT, `reloadDrafts` and `loadMore`, so they do not cancel or get cancelled. Or send the favourite through `securityFetch` (no Inertia visit), as `Show.vue` already does. Count in-flight visits (`loading` as a counter) instead of a boolean, and handle `onCancel` in `RecipeCard.toggle` by rolling back.

### WR-06: Scaled fractions of thirds never snap to whole numbers ("0.99 tsp", "2.01 tbsp")

**File:** `resources/js/Components/Recipes/recipeScale.js:25-33`; `quantityInput.js:41`
**Issue:** `parseQuantity` rounds 1/3 to 0.33 and 2/3 to 0.67 when it stores them. Scaling by 3 gives 0.99, and 3 x 0.67 gives 2.01. `asFraction` looks only for a fractional part near 1/4, 1/3, 1/2, 2/3 or 3/4. A fractional part near 0 or near 1 is not recognised. So "1/3 tsp" for 3 servings becomes "0.99 tsp" (or "0,99 lyžičky"), and "2/3 tbsp" becomes "2.01 tbsp". The same text goes into the shopping dialog. Quantities that should read "1 tsp" and "2 tbsp" look wrong.
**Fix:** In `asFraction`, snap near-integers first:
```js
const nearest = Math.round(value);
if (Math.abs(value - nearest) <= TOLERANCE && nearest > 0) return String(nearest);
```
In `displayNumber`, do this before the Intl fallback, or apply it to all units. Ideally store thirds with more precision (4 decimals).

### WR-07: "1,000" typed in the English locale is silently stored as 1

**File:** `resources/js/Components/Recipes/quantityInput.js:29-30`
**Issue:** The first branch accepts `^\d+[.,]\d+$` regardless of locale, so "1,000 g" (an English reader's thousands separator) becomes `1.000`, which is 1. No error is shown, and the saved recipe has 1 g flour. The same applies to "1.250". The ingredient paste parser uses the same function, so pasted "1,000 g sugar" has the same fault.
**Fix:** Treat a separator followed by exactly three digits and a leading group of 1-3 digits as ambiguous: return `INVALID`, or require the locale's own separator when the digit group after it has length 3. At minimum, show the parsed value back to the member in the row so the loss is visible.

### WR-08: Paste alias "cl" turns centilitres into teaspoons (and "pl" is ambiguous)

**File:** `resources/js/Components/Recipes/ingredientPaste.js:19-20`
**Issue:** `tsp` has the alias `cl` and `tbsp` has `pl`. They stand for Polish "lyzeczka"/"lyzka", which are normally written "lyzeczka"/"lyzka" or "lyz.", not "cl" or "pl". "cl" is the standard abbreviation of centilitre ("4 cl rum"). Pasting that line yields 4 tsp rum. The error is silent, and the quantity and unit then look like valid data in the row.
**Fix:** Remove `cl`; and drop `pl` unless there is evidence that Polish recipes use it. Prefer leaving the unit text in the name over guessing a wrong unit.

### WR-09: A give-up (3 minutes) or poll error leaves a draft row stuck as "reading" with no way to stop it, and the dialog invites a duplicate import

**File:** `resources/js/Components/Recipes/ImportDraftRow.vue:90-100`; `ImportFromUrlDialog.vue:211-217`; `useImportStatus.js:35-36, 57`
**Issue:**
- Row. When polling ends in `timedOut`, `error`, or a 404, `terminal` turns true and the row emits `changed`. The overview reloads, and if the server still reports `running` the draft row stays in the `active` state. The `useImportStatus` for that row has already given up and does not restart, because `idSource` did not change. The row displays "Reading..." forever, has no Cancel or Discard action, and is never refreshed until a manual reload.
- Dialog. At the 3-minute timeout the dialog shows a generic failure with "Try again", but the server job may still finish. The member then starts a second import (a second AI call and a second draft), and later sees a "ready" draft they thought had failed.

**Fix:**
- Give the active row a Cancel action that hits `/recipes/imports/{id}/cancel`. Reset the row's poll (change a key) after each reload, so a still-active draft keeps polling.
- On timeout in the dialog, call `emit('background')` and close with a "still reading, check back" message instead of showing failure plus retry.

### WR-10: Checkbox rows replace the visible label with an `aria-label` that omits it

**File:** `resources/js/Components/Recipes/IngredientRow.vue:19`; `StepRow.vue:62`
**Issue:** `UiCheckbox` receives `aria-label="Prepared: {name}"` or `"Done: step {n}"`. An `aria-label` overrides the label content. For an ingredient, the accessible name omits the quantity and unit, which are visible ("200 g flour"). For a step, it omits the step text entirely, so a screen-reader user who tabs the checkbox hears "Done: step 3, checkbox" with nothing about what step 3 is. This also fails WCAG 2.5.3 (Label in Name) for speech-control users, who say the visible words ("200 g flour"). Cooking mode, the use case for voice and hands-free control, is affected most.
**Fix:** Drop the `aria-label` and let the visible label be the name. Where the quantity needs to be part of it, use `aria-labelledby` pointing at the qty and name spans, or build the label from `quantityText + name`.

### WR-11: Slovak and Polish grammar errors in strings with a count but no plural forms

**File:** `lang/sk.json` and `lang/pl.json`, keys `recipes.servings.updated`, `recipes.ingredient.split_offer` (and `recipes.form.categories_hint`, `recipes.form.categories_max`, `recipes.photos.hint`, `recipes.photos.cap` if the limits ever change)
**Issue:** These strings are single-form but take a count.
- `recipes.servings.updated`, sk: "Množstvá upravené pre {n} porcií". For n=1 this reads "pre 1 porcií" and for n=2..4 "pre 2 porcií"; it should be "porciu" and "porcie". It is the live-region announcement on every stepper tap, and sizes 1-4 are the common ones. Polish ("dla {n} porcji") is correct.
- `recipes.ingredient.split_offer`, sk: "Rozdeliť na {n} surovín". n=2..4 needs "suroviny".
- `recipes.ingredient.split_offer`, pl: "Podziel na {n} składników". n=2..4 needs "składniki".
- `categories_hint`/`categories_max` (sk "kategórie", pl "kategorie") are right only for 2-4. They are correct today only because the limit is 3.

**Fix:** Convert them to the 4-form syntax used by `recipes.card.servings`, and call `t(key, { n }, n)`. For the config-driven limits, either use plural forms or phrase them without a declined noun ("Kategórie: najviac {n}").

### WR-12: The Add to shopping dialog edits to the name of a catalog-matched row are silently ignored

**File:** `resources/js/Components/Recipes/AddToShoppingDialog.vue:266-274, 387`
**Issue:** For a row with `catalogItemId !== null`, `body()` sends only `catalog_item_id`, and `name` is dropped. The name input stays enabled and editable for such rows. A member who fixes "tomatoes (ripe)" to "tomatoes" sees their edit, but nothing about it is sent. The shopping list gets the catalog item's name. They also get no hint (the "Change" picker is a separate control).
**Fix:** Make the name input read-only (or show the catalog name instead) while the row is matched, and make the input editable again only through "As typed".

### WR-13: Create has no protection against duplicate recipes after an ambiguous failure

**File:** `resources/js/Pages/Recipes/Form.vue:357-380`
**Issue:** `send()` posts `/recipes`. If the server created the recipe but the response is lost (dropped mobile connection, proxy timeout, or a 5xx after commit), the result is `failed(0)` or `failed(5xx)` and `saving` is reset, so the member presses Save again and gets a second recipe. In review mode the second POST also carries `import_id`, which the server ignores once it is used, so the form gives no hint. A missing `response.data?.id` leads to `router.visit('/recipes/undefined')`. The same risk exists for the Add to shopping POST (duplicated items on an existing list).
**Fix:** Send a client-generated idempotency key (UUID per form instance) that the server stores with the recipe. Guard `id == null` as a failure. For the shopping POST, do the same.

### WR-14: The detail page's cooking-mode controls and favourite expose a conflicting toggle state to assistive technology

**File:** `resources/js/Pages/Recipes/Show.vue:248-257, 278`; `RecipeCard.vue:88-98`
**Issue:** The favourite button has `aria-pressed` *and* a label that changes between "Add to favourites" and "Remove from favourites". Per ARIA, a toggle button must keep its name stable and let `aria-pressed` carry the state. As written, a screen reader announces "Remove from favourites, toggle button, pressed", which reads as "currently on, press to remove, and also on". The cooking toggle is the correct pattern (fixed label plus `aria-pressed`).
**Fix:** Use a fixed label ("Favourite") with `aria-pressed`, or keep the changing label and drop `aria-pressed`.

## Info

### IN-01: `useImportStatus` can run two requests after stop() then start()

**File:** `resources/js/Components/Recipes/useImportStatus.js:62-80`
**Issue:** `stop()` sets `inflight = false` while the old request is still running. A following `start(id)` can launch a second poll. When the old request returns, it sets `inflight = false` before checking `generation`, so `onVisibility` may start a third. The result is not a visible bug, only a few extra requests, but the "one request at a time" invariant is not strictly kept.
**Fix:** Only reset `inflight` when `mine === generation`.

### IN-02: A failed `makeMain` after create, or a failed main photo upload, is silent

**File:** `resources/js/Components/Recipes/recipePhotosApi.js:80-82`; `Form.vue:373-376`
**Issue:** If the chosen main photo failed to upload, the first stored photo silently becomes main. If `makeMain` fails, nothing is reported, and the toast says "Saved".
**Fix:** Return `mainFailed` from `uploadQueue` and add one sentence to the toast.

### IN-03: `busy`, `drained` and `overrides` of `useRecipeState` are unused, and `busy` is not reactive to `queue`

**File:** `resources/js/Components/Recipes/useRecipeState.js:41, 108-115`
**Issue:** No caller uses `drained`/`busy`. `busy` reads the plain array `queue.length`, so the computed does not re-evaluate when only a `send` job is queued.
**Fix:** Remove them, or make `queue` reactive.

### IN-04: Notes typed just before leaving can be lost without notice, and notes are not re-synced after a reload

**File:** `resources/js/Components/Recipes/RecipeNotes.vue:215-230, 199`
**Issue:** `onBeforeUnmount` sends the note fire-and-forget. If it fails (offline, 419) there is no message and nothing is retried. `text` is initialised once from `modelValue`, so a `router.reload` after 403/409 does not update it.
**Fix:** Keep a `flush` on `beforeunload`/`pagehide`, and `watch(() => props.modelValue)` to update `text` when not dirty.

### IN-05: Servings above 99 on the recipe make "Scaled" appear with no way back

**File:** `resources/js/Pages/Recipes/Show.vue:68`; `ServingsControl.vue:24`
**Issue:** `servings` is clamped to 99, but the base is not. A recipe with `servings = 100` (if the server limit differs from the 99 in the UI) shows "Scaled" and a reset button that clamps back to 99.
**Fix:** Clamp the stepper's range to `Math.max(99, base)`, or enforce the same maximum from `limits.servingsMax` in both places.

### IN-06: After a 409 "list locked" the dialog keeps offering the locked list, and the radio flips to "existing" even for a new-list 409

**File:** `resources/js/Components/Recipes/AddToShoppingDialog.vue:316, 281-285`
**Issue:** `focusList()` forces `target = 'existing'` whatever the failing target was. If there are no open lists, `target` points at nothing and the submit stays disabled until the member chooses "New list" again. The locked list stays in the select; the member has to guess to pick another.
**Fix:** Reload the preview (or remove the list from `lists`) on 409; set `target` to `existing` only when it was already the target.

### IN-07: The catalog change picker shows the wrong option when the current item is filtered out or beyond the first 100

**File:** `resources/js/Components/Recipes/AddToShoppingDialog.vue:250, 409-412`
**Issue:** The select's value (the current catalog id) has no matching `<option>` when the search filters it out or it is beyond `slice(0, 100)`. The browser then shows "As typed", although the row is still matched.
**Fix:** Always include the current item as an option, even if it does not match the search.

### IN-08: `goNew` always prevents default, so Ctrl/Cmd-click and middle-click cannot open the form in a new tab

**File:** `resources/js/Pages/Recipes/Index.vue:140-144, 164, 211`
**Issue:** `Show.vue` has `onOpen`, which respects modifier keys. `goNew` does not.
**Fix:** Return early for `event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey`.

### IN-09: Bottom navigation at 7 items can overflow at 320 px, and the "title" on icon-only items is on a visually hidden span

**File:** `resources/js/Shell/BottomNav.vue:36-40`; `resources/js/Shell/NavItem.vue:41`
**Issue:** In `tight` mode six icon-only items are at least 44 px each (264 px) plus the current item sized to its label (`flex: 1 0 auto`). The container is 320 - 4 px at 320 px wide. A longer label such as "Financie" or "Receptarz" pushes the total past it, so the bar scrolls or clips. At 360 px it fits. The `title` attribute on the span that is `visually-hidden` for icon-only items never shows a tooltip for mouse users. `aria-label` is correct and sufficient for assistive technology. The dense (6 items) labels fit at 360 px (the longest are about 8 characters at 11 px), so no truncation appears there.
**Fix:** Move `title` to the link element, and let `.bottom-nav--tight li` have `min-width: 40px`, or allow shrinking below 44 px visually while keeping a 44 px hit area through padding.

### IN-10: `UiField` error `aria-live` is on a node that appears together with its text

**File:** `resources/js/Components/ui/UiField.vue:46`
**Issue:** Live regions announce changes to an existing node. This `<p v-if="error" aria-live="polite">` is created already filled, so many screen readers will not announce it. Form validation already moves focus to the first invalid field, which covers most of the case.
**Fix:** Render an always-present empty live container and put the error text inside it, or rely on `aria-describedby` plus focus and remove the attribute.

### IN-11: Hero photo uses the full-size image; unavailable author name renders as "By "

**File:** `resources/js/Components/Recipes/RecipePhotoGallery.vue:124`; `RecipeCard.vue:76-79`; `Show.vue:261`
**Issue:**
- The gallery hero (at most 360 px wide, 240 px high on phone) loads `fullUrl`. Thumbnails and cards correctly use `thumbUrl` and `loading="lazy"`; the hero fetches a multi-megabyte original on mobile data. A mid-size variant, or `thumbUrl` until the viewer is opened, would do.
- `RecipePayload::author()` returns `name: ''` when the author account is gone. The card and detail show "By " with an empty avatar.

**Fix:** Serve a ~720 px `hero` size. In the UI, fall back to the "Unknown author" label when the name is empty.

---

_Reviewed: 2026-11-17_
_Reviewer: Claude (gsd-code-reviewer)_
_Depth: deep_
