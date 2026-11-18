---
phase: quick-261118-rqu
plan: 01
type: execute
wave: 1
depends_on: []
files_modified:
  - app/Http/Controllers/Wishlist/Owner/ReceivedController.php
  - resources/js/Wishlist/OwnWishPanel.vue
  - lang/en.json
  - lang/sk.json
  - lang/pl.json
  - resources/help/en.json
  - resources/help/sk.json
  - resources/help/pl.json
  - resources/manual/en/wishlist.json
  - resources/manual/sk/wishlist.json
  - resources/manual/pl/wishlist.json
  - tests/js/wishlist/OwnWishPanel.test.js
  - tests/Feature/Wishlist/OwnerActionsTest.php
autonomous: true
requirements: [GH-17]
must_haves:
  truths:
    - "Pressing Mark as received in the wish panel opens a confirmation dialog; nothing is sent until the member confirms"
    - "Cancel (the default focus) leaves the wish unchanged"
    - "A received wish shows a Move back to my list button; after confirmation the wish is active and editable again"
    - "The Mark as received button never exists in the create form"
    - "Reservation privacy is unchanged: marking and restoring keep reservation rows and the recipient still cannot see them"
  artifacts:
    - path: "resources/js/Wishlist/OwnWishPanel.vue"
      provides: "two UiConfirmDialog instances (receive, restore) and the restore button"
    - path: "tests/Feature/Wishlist/OwnerActionsTest.php"
      provides: "undo restores state, permissions, privacy tests"
  key_links:
    - from: "OwnWishPanel.vue"
      to: "DELETE /wishlist/wishes/{id}/received"
      via: "router.delete in confirmRestore"
      pattern: "received`"
---

<objective>
Fix GitHub issue #17: a gift was marked received by accident on mobile. Add a confirmation step to the received action and a visible, confirmed undo, without touching reservation privacy.

Findings (already verified in code): the only UI that sets received is the footer button in `OwnWishPanel.vue` (`markReceived`, POST `/wishlist/wishes/{wish}/received`), which is already hidden when `creating` (requirement 3 is met; keep and lock with a test). The undo route and service exist (`ReceivedController::destroy`, `WishService::unmarkReceived`, same `canEditList` check, same route middleware) but NO UI calls it, so a received wish is currently irreversible from the app. `GiftWishCard.vue` and other people's views only display state. `WishService` and the wishlist code have no audit logging of state changes, so none is added (nothing to mirror).

Purpose: prevent accidental irreversible state changes on touch devices.
Output: confirm dialog + undo button, texts in en/sk/pl, manual and help updates, tests. Issue #16 link autofill (linkPreview.js, preview state in OwnWishPanel) must stay untouched.
</objective>

<execution_context>
@/home/voslar/projects/family-hub/.claude/get-shit-done/workflows/execute-plan.md
@/home/voslar/projects/family-hub/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
@CLAUDE.md
@.planning/quick/261118-rkf-wishlist-autofill-from-first-url-16/261118-rkf-SUMMARY.md
@resources/js/Wishlist/OwnWishPanel.vue
@app/Http/Controllers/Wishlist/Owner/ReceivedController.php
@app/Wishlist/Catalog/WishService.php
@tests/Feature/Wishlist/OwnerActionsTest.php
@tests/js/wishlist/OwnWishPanel.test.js
</context>

<tasks>

<task type="auto" tdd="true">
  <name>Task 1: Confirm dialog for Mark as received, confirmed undo, server toast</name>
  <files>resources/js/Wishlist/OwnWishPanel.vue, app/Http/Controllers/Wishlist/Owner/ReceivedController.php, lang/en.json, lang/sk.json, lang/pl.json, tests/js/wishlist/OwnWishPanel.test.js, tests/Feature/Wishlist/OwnerActionsTest.php</files>
  <behavior>
    - Vitest: clicking "Mark as received" opens a dialog, `router.post` not called; Cancel closes it, still no post, panel not closed.
    - Vitest: confirming posts to `/wishlist/wishes/11/received` once.
    - Vitest: create mode has no "Mark as received" button; received wish shows "Move back to my list" (replacing Mark as received); click opens a dialog, cancel sends nothing, confirm calls `router.delete('/wishlist/wishes/11/received')`.
    - Vitest: the existing 'marks as received with a POST' test is rewritten to include the confirm step; the read-only received test also asserts the restore button exists.
    - Pest: DELETE received on a received wish sets `received_at` null and returns 303 with toast key `wishlist.toast.unreceived`; POST received sets it; after mark then restore the wish equals its pre-mark state (received_at null) and its reservation rows are unchanged; a member without wishlist edit gets 403 on both (the existing 'owner'/'manager' sweep covers parity, add an explicit 403 case for a plain viewer/non-manager if not already covered by WishlistPrivacy helpers); the recipient view of a reserved wish still hides the reservation after mark and restore (reuse `WishlistPrivacy::scenario()` as in the existing reserved-wish test at line 83).
  </behavior>
  <action>
In `OwnWishPanel.vue` (per issue #17 requirements 1-3), add two refs `receiveOpen` and `restoreOpen` next to `deleteOpen`. The footer "Mark as received" button now sets `receiveOpen = true` instead of calling `markReceived` directly; rename the POST logic to `confirmReceived` (same `router.post(...visitOptions(() => emit('close')))`) wired to a new `UiConfirmDialog` (same component and props pattern as the existing discard/delete dialogs: `v-model:open`, `title`, `body`, `confirm-label`, `cancel-label`, `@confirm`). Cancel is the safe default focus exactly as those existing dialogs behave; do not use the destructive variant unless the existing delete dialog passes a prop for it, and then pass it only if it is the established pattern for confirm dialogs (reading the component props first). Body must state what happens: the wish moves to the Received section, can no longer be edited, and can be moved back. Keep the button hidden when `creating` (`v-if="!creating && !received"` already present; do not weaken it). When `received`, show in the same footer slot a secondary button "Move back to my list" (icon `Undo2` from `@lucide/vue`, verify it is exported, else `RotateCcw`) that opens `restoreOpen`; its confirm calls `router.delete('/wishlist/wishes/${props.wish.id}/received', visitOptions(() => emit('close')))`. Its dialog body states the wish returns to the active list and becomes editable again. Add `HelpTip` handling only via existing topic `wishlist.received` (no new key; text is reworded in Task 2). Do not touch preview/linkPreview code, `received` computed, `guard`, or the fieldset.

In `ReceivedController::destroy` return `back(303)->with('toast', ['key' => 'wishlist.toast.unreceived'])`. Server permission check stays as is (`canEditList` + route middleware identical to store). No audit logging exists in the wishlist code, so none is added.

In `lang/en.json`, `sk.json`, `pl.json` add under `wishlist.received`: `restore` (button), `confirm_title`, `confirm_body`, `confirm_action`, `restore_title`, `restore_body`, `restore_action`, `cancel`; and under `wishlist.toast`: `unreceived`. Keep the same key set and order in all three; SK and PL are drafts (Slovak uses "prijaté", Polish "otrzymane", matching existing wording). Check whether `wishlist.discard.keep` style cancel label exists and reuse an existing generic cancel key if there is one rather than adding `cancel`. Then update the Vitest and Pest tests as listed in behavior.
  </action>
  <verify>
    <automated>npx vitest run tests/js/wishlist/OwnWishPanel.test.js tests/js/wishlist/LinkPreview.test.js && php artisan test --compact tests/Feature/Wishlist/OwnerActionsTest.php tests/Feature/Wishlist/ReservationPrivacyTest.php</automated>
  </verify>
  <done>Confirm dialog gates marking; cancel changes nothing; undo button restores via DELETE after its own confirmation; create form has no received button; new Pest and Vitest cases pass; autofill tests still pass. (Run Pest in the project's docker compose setup used in quick 261118-rkf if the host lacks PHP/DB, with a unique project name, then `down -v`.)</done>
</task>

<task type="auto">
  <name>Task 2: User documentation in en/sk/pl and coverage tests</name>
  <files>resources/manual/en/wishlist.json, resources/manual/sk/wishlist.json, resources/manual/pl/wishlist.json, resources/help/en.json, resources/help/sk.json, resources/help/pl.json</files>
  <action>
Per CLAUDE.md user-documentation rule. In the manual line 33 of each locale (add-wish/received section) reword: choosing **Mark as received** asks for confirmation first; received wishes move to the Received section; to undo, open the wish in the Received section and choose **Move back to my list** (confirmed). Keep section ids, section count and links identical across locales; use the exact UI labels added in Task 1 (en "Mark as received" / "Move back to my list"; sk "Označiť ako prijaté" and a draft restore label; pl "Oznacz jako otrzymane" and a draft restore label, same strings as in lang files). Reword the existing help key `wishlist.received` in `resources/help/en.json`, `sk.json`, `pl.json` (title unchanged, meaning unchanged, no new key, so `topics.json` stays untouched): a confirmation is asked, the wish moves to Received and is read-only, and it can be moved back from there with Move back to my list. Each text must stay under 400 characters. Mark SK and PL as drafts needing native review in the summary. In the final summary list the touched resources/manual files.
  </action>
  <verify>
    <automated>npx vitest run tests/js/manual tests/js/help tests/js/wishlist && php artisan test --compact tests/Feature/Manual tests/Feature/Help tests/Feature/Wishlist</automated>
  </verify>
  <done>Manual and help describe confirmation and undo in all three languages; coverage tests (ManualCoverage, HelpCoverage, tests/Feature/Manual) and the whole wishlist suites pass.</done>
</task>

</tasks>

<threat_model>
## Trust Boundaries

| Boundary | Description |
|----------|-------------|
| browser to /wishlist/wishes/{id}/received | authenticated member toggles state |

## STRIDE Threat Register

| Threat ID | Category | Component | Disposition | Mitigation Plan |
|-----------|----------|-----------|-------------|-----------------|
| T-rqu-01 | Elevation of privilege | received store/destroy | mitigate | unchanged `access:module,wishlist,edit` middleware plus `canEditList` abort; Pest 403 case for both verbs |
| T-rqu-02 | Information disclosure | reservation visibility | mitigate | no change to presenters or reservation rows; Pest asserts rows survive and recipient view still hides them after mark and restore |
| T-rqu-03 | Tampering | repeated POST/DELETE | accept | both operations idempotent and throttled 60/min; UI confirmation is a UX guard, server remains authoritative |
</threat_model>

<verification>
Vitest and Pest suites in the two tasks pass; `git diff` shows no change to `resources/js/Wishlist/linkPreview.js`, `LinksEditor.vue`, or preview code in OwnWishPanel.vue.
</verification>

<success_criteria>
Requirements 1-4 of issue #17 met and tested; docs updated in en/sk/pl; final commit message contains `Fixes #17`; commit only, no push, no deploy, issue not closed.
</success_criteria>

<output>
Create `.planning/quick/261118-rqu-wishlist-received-state-confirmation-and/261118-rqu-SUMMARY.md` when done
</output>
