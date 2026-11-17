---
phase: 11-shopping-lists-section-n-kupy-issue-8
reviewed: 2026-11-17T00:00:00Z
depth: deep
files_reviewed: 35
files_reviewed_list:
  - lang/en.json
  - lang/sk.json
  - lang/pl.json
  - resources/js/Components/Settings/AuditTable.vue
  - resources/js/Layouts/SettingsLayout.vue
  - resources/js/Pages/Settings/Index.vue
  - resources/js/Pages/Settings/Shopping.vue
  - resources/js/Pages/Shopping/Index.vue
  - resources/js/Pages/Shopping/Show.vue
  - resources/js/Settings/presets.js
  - resources/js/Shell/modules.js
  - resources/js/Shopping/CatalogImage.vue
  - resources/js/Shopping/CopyListDialog.vue
  - resources/js/Shopping/CreateListDialog.vue
  - resources/js/Shopping/EmailListDialog.vue
  - resources/js/Shopping/FinishShoppingDialog.vue
  - resources/js/Shopping/ImageUploadField.vue
  - resources/js/Shopping/ItemEditDialog.vue
  - resources/js/Shopping/ItemPickerDialog.vue
  - resources/js/Shopping/ListHeader.vue
  - resources/js/Shopping/ListReminderField.vue
  - resources/js/Shopping/ListRow.vue
  - resources/js/Shopping/ListSettingsPanel.vue
  - resources/js/Shopping/MarkButtons.vue
  - resources/js/Shopping/PeopleField.vue
  - resources/js/Shopping/Settings/CatalogTab.vue
  - resources/js/Shopping/Settings/StoresTab.vue
  - resources/js/Shopping/Settings/UnitsTab.vue
  - resources/js/Shopping/ShoppingItemRow.vue
  - resources/js/Shopping/ShoppingProgress.vue
  - resources/js/Shopping/StickyActionBar.vue
  - resources/js/Shopping/catalogSearch.js
  - resources/js/Shopping/listStatus.js
  - resources/js/Shopping/units.js
  - app/Shopping/Queries/ListPayload.php (read for the payload question only)
findings:
  critical: 1
  warning: 11
  info: 6
  total: 18
status: issues_found
---

# Phase 11: Code Review Report (frontend)

**Reviewed:** 2026-11-17
**Depth:** deep
**Files Reviewed:** 35 (lang/*.json, lang/*/mail.php and notifications.php were not reviewed)
**Status:** issues_found

## Summary

The UI follows the server `abilities` consistently, and the controls match the matrix: Lock, Unlock, Mark and Finish are shown to the responsible person only. The owner's carry-over question is verbatim in all three languages. The en/sk/pl `shopping.*` trees have identical key sets and identical placeholders. The Slovak and Polish plural strings work with the custom plural rule, because the rule clamps the index to the number of forms given. Nothing is rendered with `v-html`, and all URLs are built from numeric ids.

The serious problem is the core shopping flow. Inertia 3 runs `router.put` as a synchronous visit, and a new synchronous visit aborts the one in flight. The optimistic mark logic assumes that cannot happen. Beyond that, error handling outside the e-mail and finish dialogs is thin: 403, 404, 409 and 419 are not handled, and the spec's "forbidden" message is never shown.

Verified against `node_modules/@inertiajs/core/dist/index.js`: `syncRequestStream = new RequestStream({ maxConcurrent: 1, interruptible: true })`, and `Request.cancel()` calls `cancelToken.abort()` and then `fireFinishEvents()`.

## Critical Issues

### CR-01: Marking a second item aborts the first mark's request

**File:** `resources/js/Pages/Shopping/Show.vue:77-93`

**Issue:** `mark()` guards only per item (`inflight[item.id]`). Every mark is a plain `router.put`, which goes through Inertia's synchronous request stream. That stream allows 1 concurrent request and interrupts the in-flight one when a new visit starts.

Failure scenario in a shop:
1. The shopper taps Bought on milk.
2. Before the response arrives (about 200 ms to 1 s on mobile data), they tap Bought on bread.
3. The milk XHR is aborted, and Inertia fires `onFinish` for it.
4. `onFinish` deletes `overrides[milk]`, so milk visibly snaps back to pending.
5. No `onError`, `onNetworkError` or `onHttpException` fires, so no toast appears.
6. Whether the milk mark was persisted depends on whether the request reached PHP before the abort. If it did not, the mark is lost without any feedback.
7. Even when it was persisted, the row flickers back to pending until bread's redirect response refreshes the props.

The same abort hits any other visit made while a mark is in flight:
- `Finish` can abort the last mark. `hasBar` is computed from the optimistic `shownItems`, so Finish is offered while that mark is still on the wire. The server then answers 409 or finishes with a stale state.
- Lock, Settings save and the Index archive paging are affected in the same way.

This is the primary use case of the module, so it counts as a blocker.

**Fix:** Serialise marks through a small client queue, and keep each override until its own request settles. Drop the override only on failure or after the refreshed props arrive. Use `async: true` only if out-of-order responses are acceptable (they are not, because each response carries full props). Also derive `hasBar` and `canFinish` from the server `items`, or disable Finish while `Object.keys(inflight).length > 0`.

```js
let chain = Promise.resolve();
function mark(item, target) {
    if (inflight[item.id]) return;
    inflight[item.id] = true;
    overrides[item.id] = target;
    chain = chain.then(() => new Promise((resolve) => {
        router.put(url, { state: target }, {
            preserveScroll: true, preserveState: true,
            onError: markFailed,
            onHttpException: () => { markFailed(); return false; },
            onNetworkError: markFailed,
            onCancel: markFailed,
            onFinish: () => { delete overrides[item.id]; delete inflight[item.id]; resolve(); },
        });
    }));
}
```

## Warnings

### WR-01: After 403/404/409 a failed mark, lock or finish is never reconciled with the server

**File:** `resources/js/Pages/Shopping/Show.vue:84-90,115-119`; `resources/js/Shopping/FinishShoppingDialog.vue:57-59`

**Issue:** The backend answers 403 (no longer responsible), 409 (list already unlocked or finished, or items still pending), or 404 (list deleted) when the page state is stale. The UI handles all of these with a generic message and keeps rendering the stale page.

- Mark shows "Could not save. Try again." Retrying never helps, because the list is still shown as locked with working-looking buttons.
- Lock and unlock show the same `shopping.error.mark_failed` text.
- Finish shows "Nothing was changed. Try again." forever, for example when the responsible person on another device already finished or unlocked the list.

Concretely: person A unlocks the list on their tablet while A's phone still shows shopping mode. Every tap on the phone toasts a failure, with no way out except a manual reload.

**Fix:** In the HTTP exception handlers for status 403, 404 and 409, call `router.reload()` and show a specific message (`shopping.error.forbidden`, or a new "list changed, reloaded" string). Let the finish dialog close after reloading.

### WR-02: 403, 404, 419 and 429 are unhandled in most dialogs, and the spec's 403 message is dead

**File:** `ItemPickerDialog.vue:137`, `ItemEditDialog.vue:81,86-91`, `ListSettingsPanel.vue:139`, `CreateListDialog.vue:59`, `CopyListDialog.vue:53`, `Show.vue:155-157`, `Settings/CatalogTab.vue`, `StoresTab.vue`, `UnitsTab.vue` (delete and image requests)

**Issue:** Only `EmailListDialog` and `FinishShoppingDialog` define `onHttpException` and `onNetworkError`. Elsewhere only `onError` (422) is handled.

- A contributor whose edit right was revoked, or who edits while the list gets locked, receives 403 or 409. Inertia then shows its raw full-page error modal.
- The same happens when an item was deleted by someone else (404 on save or remove) and when the session CSRF token has expired (419).
- UI-SPEC line 310 defines a "You are not allowed to do that on this list" toast. `shopping.error.forbidden` exists in all three languages but is used nowhere, which the key-usage scan confirmed.

**Fix:** Add a shared helper, for example `shoppingHttp(response)`. It should map 403 to a toast with `shopping.error.forbidden`, 404 and 409 to a reload plus message, 419 to the existing stale-session gate, and 429 to a rate-limit message. Pass it as `onHttpException` and `onNetworkError` in every visit above, returning `false` so Inertia does not open its error window.

### WR-03: The archive load-error state can never appear

**File:** `resources/js/Pages/Shopping/Index.vue:65-71`

**Issue:** `goTo()` sets `loadFailed` only in `onError`, which Inertia calls for validation errors only. A 5xx response or a dropped connection goes to `onHttpException` or `onNetworkError`, which are not set. The `UiErrorState` with Retry is therefore unreachable. Instead the user gets Inertia's raw error modal and a skeleton that disappears.

**Fix:**
```js
router.get('/shopping', { tab: 'archive', page }, {
    ..., 
    onError: () => { loadFailed.value = true; },
    onHttpException: () => { loadFailed.value = true; return false; },
    onNetworkError: () => { loadFailed.value = true; },
    onFinish: () => { loading.value = false; },
});
```

### WR-04: Slicing at a UTF-16 index can break the carry-over, which silently skips creating the new list

**File:** `resources/js/Shopping/FinishShoppingDialog.vue:37-44`; also `CopyListDialog.vue:26-32` and `ItemPickerDialog.vue:99`

**Issue:** The list name is cut with `name.slice(0, room)`. When the cut falls inside a surrogate pair (an emoji near character 70 to 80 of the name), a lone surrogate is produced. `JSON.stringify` emits it as `\ud83d`, and PHP's `json_decode` rejects it with JSON_ERROR_UTF16.

Laravel then treats the body as empty, so `carry_name` arrives as null. The server accepts the request, archives the list, and does not create the carry list. The user chose "Create new list" and the unavailable items are dropped without any error. The same cut in the copy dialog fails the request, and in the picker it produces an invalid item name.

**Fix:** Truncate by code points: `Array.from(name).slice(0, room).join('').trimEnd()`. Alternatively use `Intl.Segmenter` and measure with `[...full].length`, which matches the server's `max:` (a character count).

### WR-05: The picker cannot enter realistic quantities for g and ml, and mixes fractions into other units

**File:** `resources/js/Shopping/ItemPickerDialog.vue:53,85-93`

**Issue:** Catalog tiles offer only a -/+ stepper. The step is 0.5 for `g`, `kg` and `l`, and 1 for `ml`.

- 250 g needs 499 taps and 500 ml needs 499 taps. There is no way to type a quantity for a catalog item in the picker. Only free-text items have a number field.
- `changeUnit` keeps the quantity and only raises it to the new step. Switching kg to pcs leaves "1.5 pcs", and the next + gives 2.5.

**Fix:** Make the quantity a typed `inputmode="decimal"` field with the stepper around it, using `parseQuantity` as for own items. Use per-unit steps such as g 50 and ml 50. On a unit change, round the quantity to the new unit's grid.

### WR-06: Bought and Unavailable state in the mark buttons is shown by colour only

**File:** `resources/js/Shopping/MarkButtons.vue:23-31,46-47`

**Issue:** Pressed and unpressed buttons have the same icon, the same label and the same border. The only visible difference is the fill colour (accent vs warn tint), which is a WCAG 1.4.1 violation. In addition, the row only mutes the name colour. Below 360 px the labels are hidden, so even the text is gone. `aria-pressed` helps screen readers only. UI-SPEC says state is never conveyed by colour alone, and a person in bright sunlight in a shop is the case this affects most.

**Fix:** Add a non-colour cue on the pressed button, such as a thicker border or `outline` plus a filled icon variant. Alternatively show a tag next to the buttons: render the `state_bought` or `state_unavailable` tag in markable rows too, with an icon.

### WR-07: Settings tabs can silently skip the image upload after a successful save, and upload failures bypass the error UI

**File:** `resources/js/Shopping/Settings/CatalogTab.vue:92-99,82-90`; `StoresTab.vue:70-77,60-68`

**Issue:** For a new item or store, `afterSave` finds the new id by `props.items.find((i) => i.name === clean)`. If the server normalises the name differently (case, Unicode normalisation or other trimming), `id` is null and the code calls `close()`. The chosen picture is discarded and the dialog closes as if everything worked.

`imageRequest` also handles only `onError`. A 413 from nginx or a 5xx on the upload goes to Inertia's error modal. `savedId` is not set, so a retry would create a duplicate item.

**Fix:** Return the new id from the server, for example via flash data from the store endpoint, and use it. Or match case-insensitively and show `imageError` when no match is found. Add `onHttpException` and `onNetworkError` to `imageRequest`, set `savedId.value = id` and `imageError` there.

### WR-08: Missing double-submit guards and a timing hole on destructive actions

**File:** `resources/js/Shopping/ItemEditDialog.vue:84-92`; `resources/js/Pages/Shopping/Show.vue:154-156`; `EmailListDialog.vue:37-51`

**Issue:**
- `remove()` has no `if (removing.value) return`. A double tap on "Remove item" sends two DELETEs. The second is aborted or returns 404, and it then shows Inertia's error modal.
- `deleteList()` is the same: no processing flag, and no error handling at all.
- `EmailListDialog` resets `sending = false` whenever it is reopened, so closing and reopening mid-request allows a second send. The e-mail has an external effect and is rate limited.
- `ItemEditDialog` also keeps the `.transform()` from `submit()` on the shared `form`, so the DELETE carries the stale transformed body.

**Fix:** Guard with `form.processing`/`removing` at the start of `remove()` and `deleteList()`, using a `processing` ref for the latter. Do not clear `sending` on reopen unless the request has finished. Reset the transform, or use `router.delete` for the removal.

### WR-09: The overview payload gives view-only users the names of family members who hold edit rights

**File:** `app/Shopping/Queries/ListPayload.php` (`index`, `responsibleCandidates` and `stores`); consumed in `resources/js/Pages/Shopping/Index.vue:26`

**Issue:** `index()` always returns `responsibleCandidates` (id, name, isChild of every person with Shopping Edit). It does so even when `canCreate` is false, and the only consumer is `CreateListDialog`, which is rendered under `v-if="canCreate"`. A member with view-only Shopping access therefore receives the list of editors in the Inertia payload. `Show` correctly gates `candidates` behind `manageMembers` or `changeResponsible`.

**Fix:** Only include `responsibleCandidates` (and `stores`) when `canCreate` is true. In `Index.vue` keep the default `[]`.

### WR-10: The reminder time is shown in two different time zones

**File:** `resources/js/Shopping/listStatus.js:22-28`; `resources/js/Shopping/ListSettingsPanel.vue:30`; `ListHeader.vue:18`, `ListRow.vue:26`

**Issue:** The server sends `remindAt` in the family time zone, for example `2026-11-20T18:00:00+01:00`. The settings field shows `slice(0, 16)`, which is the family wall-clock time `18:00`. `formatListDate` builds `Intl.DateTimeFormat(locale, …)` without a `timeZone`, so the header and the overview row use the browser's zone. A family member abroad or on a device in another zone sees a "Reminder: 17:00" header and an `18:00` field for the same value. Saving unchanged text then looks like a change.

**Fix:** Pass `timeZone: 'Europe/Bratislava'` (the `TIME_ZONE` constant in `i18n.js`, or a shared prop) to `Intl.DateTimeFormat` in `formatListDate`.

### WR-11: Overview rows do a full page load, and the selected tab is lost on Back

**File:** `resources/js/Shopping/ListRow.vue:46`, `resources/js/Pages/Shopping/Index.vue:32,85`; `resources/js/Components/ui/UiListRow.vue:28-37`

**Issue:** `UiListRow as="link"` renders a plain `<a href>`, not an Inertia `Link`. Opening a list reloads the whole app (JS, service worker boot, loss of the shell state) instead of an SPA visit. Switching to the Archive tab is client-only and does not touch the URL. If the user opens an archived list and presses Back, `tab` is re-initialised from `props.tab` and the page returns on the Open tab.

**Fix:** Render an Inertia `Link` in the row, for example `<component :is="Link">` with `as="link"`. Sync the tab to the URL with `router.get('/shopping', { tab }, { replace: true, preserveState: true })` or `history.replaceState`.

### WR-12: Server-side messages the UI would render as raw keys; spec'd keys unused

**File:** `app/Http/Controllers/Shopping/LockController.php:14`, `app/Http/Requests/Shopping/ItemStateRequest.php:25`, `app/Shopping/ItemService.php:177`; `lang/*.json` under `shopping.error`

**Issue:** The following keys are sent by the server and exist in none of en, sk, pl: `shopping.error.lock_invalid`, `shopping.error.state_invalid`, `shopping.error.quantity_invalid`. Every dialog's `translate()` runs `t(message)` on `shopping.*` messages, so a user would see the literal key. Currently they are mostly unreachable behind earlier validation, and `quantity_invalid` is shadowed by the request rule. Conversely these keys are never used: `shopping.error.forbidden`, `error.finish_failed`, `detail.archived`, `progress.all_done`, `overview.retry`, `overview.reminder`, `overview.no_store`, `detail.store`.

**Fix:** Add the three keys to all three languages, or change the server to emit existing keys. Make `translate()` fall back to the generic `save_failed` text when `te(message)` is false. Wire `forbidden` and `all_done` (see WR-02 and IN-02) or delete the unused keys.

## Info

### IN-01: The mark-failure toast overlaps the phone sticky bar

**File:** `resources/js/Shopping/StickyActionBar.vue:24-30`, `resources/js/Layouts/AppShell.vue:65`

The toast offset is `bottombar + 16px`, which is exactly where the 64 px sticky bar sits. The "Could not save" toast covers the "n items left" text and the Finish button. Raise the toast offset by 64 px (plus the safe area) when the sticky bar is present.

### IN-02: Nothing announces that Finish became available, or that a mark was applied

**File:** `resources/js/Shopping/StickyActionBar.vue:17-18`, `ShoppingProgress.vue`

On phone the `role="status"` paragraph is removed (not updated) when the last item is marked, so screen reader users get no announcement. On tablet and desktop nothing is live at all. UI-SPEC line 235 requires status changes to be announced. `shopping.progress.all_done` already exists in all three languages and is unused. Keep a persistent `aria-live` region and announce `all_done` when `left` reaches 0.

### IN-03: Desktop Finish button is at the bottom of the list, not in the header

**File:** `resources/js/Pages/Shopping/Show.vue:196-198`, `StickyActionBar.vue:20`

UI-SPEC line 155 puts the Finish action in the header on wide screens. With a long list the responsible person must scroll to the end to find it. Render it in the `ListHeader` actions slot on non-phone.

### IN-04: Duplicate error text, hardcoded limits and one bounded paste

**File:** `CopyListDialog.vue:61-63`, `CreateListDialog.vue:67-69`, `ListSettingsPanel.vue:155`, `EmailListDialog.vue:57-72`

- The danger `UiAlert` repeats the same message as the inline field error. Screen readers announce it twice, and the first invalid field does not receive focus on edit and panel errors (UI-SPEC line 215).
- The name limit 80 is hardcoded in six components (`nameMax` default or `maxlength="80"`). Pass it once from the server (`config('shopping.name_max.*')`).
- `EmailListDialog.commit` silently drops addresses beyond 5 (for example from a pasted list of 8). The hint only appears afterwards, when the field is full.

### IN-05: Bottom navigation with six modules at 360 px needs a visual check

**File:** `resources/js/Shell/modules.js:6-11`, `resources/js/Shell/BottomNav.vue:26`, `NavItem.vue:75`

Six equal columns give about 58 px each. Labels such as "Financie", "Nákupy" and "Zakupy" use `overflow-wrap: anywhere`. At the narrowest widths and with a larger system font they may break mid-word. This could not be verified statically. Add a Playwright screenshot at 360 px in sk and pl.

### IN-06: Minor accessibility and consistency items

**File:** `resources/js/Pages/Shopping/Show.vue:166-167,178`, `ShoppingItemRow.vue:33`, `FinishShoppingDialog.vue:43`, `CopyListDialog.vue:31`

- `ShoppingItemRow` puts the "Edit {name}" label only in `title`, which touch and screen reader users do not get reliably.
- The carry name falls back to `Math.max(1, room)`, while the copy name uses `Math.max(0, room)`, so the two truncations differ.
- A non-responsible user on a locked list gets no hint about who may unlock it.

## Checked and found sound

- **Controls vs matrix.** Lock, Unlock, Mark and Finish are shown from `abilities.*` only, not from `status` alone. Item editing needs `editItems` and an open list. The picker, settings and menu entries are all gated.
- **Owner wording.** The carry-over question is verbatim in sk, pl and en, and the "No, just finish" path sends `carry_name: null`.
- **i18n.** The key sets and placeholder sets of `shopping.*` are identical in all three languages. The Slovak and Polish forms are consistent with `pluralRule` plus `Math.min` clamping, including fractions. `t(key, n)` and `t(key, {n}, n)` are used correctly.
- **Rendering.** No `v-html`. URLs are built from integer ids and a version number.
- **Payload.** `ListPayload` lists explicit fields only, with no e-mail addresses. `catalog` is gated on `editItems`, `candidates` on the manage abilities, and `responsiblePushEnabled` on `setReminder`.

---

_Reviewed: 2026-11-17_
_Reviewer: Claude (gsd-code-reviewer)_
_Depth: deep_
