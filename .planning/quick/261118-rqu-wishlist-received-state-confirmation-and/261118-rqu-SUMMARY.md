---
phase: quick-261118-rqu
plan: 01
subsystem: wishlist
tags: [wishlist, received, confirm-dialog, undo, i18n]
requirements: [GH-17]
completed: 2026-11-18
---

# Quick 261118-rqu: Wishlist received confirmation and undo (#17) Summary

Mark as received now asks for confirmation, and a received wish has a confirmed "Move back to my list" button that calls the existing DELETE route.

## What changed

- `OwnWishPanel.vue`: two `UiConfirmDialog`s (receive, restore; Cancel is the default focus as in the existing dialogs), `Undo2` restore button for received wishes, Mark as received still hidden in create mode. Link preview code untouched.
- `ReceivedController::destroy` returns toast `wishlist.toast.unreceived`. Permission checks unchanged. No audit logging exists in the wishlist code, so none added.
- `lang/en|sk|pl.json`: `wishlist.received.{restore,confirm_*,restore_*,cancel}` and `wishlist.toast.unreceived`.
- Tests: Vitest (confirm gates POST, cancel sends nothing, create has no button, restore confirm gates DELETE); Pest (undo restores state with toast, reservation rows intact, recipient list payload still hides reservation, 403 for a member without edit on both verbs).

## Verification

- Vitest `tests/js/wishlist`, `help`, `manual`, `brand`: 24 files, 361 passed (includes LinkPreview tests; no OwnWishPanel-touching a11y sweep outside tests/js/wishlist).
- Pest `tests/Feature/Wishlist`, `Manual`, `Help` (docker project `fhrqu261118`, torn down with `down -v`): 246 passed.

## User documentation

Touched: `resources/manual/en|sk|pl/wishlist.json` (received paragraph), `resources/help/en.json`, `sk.json`, `pl.json` (reworded `wishlist.received`; no new key, `topics.json` unchanged). **SK and PL texts (manual, help, lang) are drafts and need native review.**

## Deviations from Plan

- Added a generic `wishlist.received.cancel` label (no suitable generic cancel key existed under wishlist).
- Pest privacy assertion checks the `list` Inertia prop rather than the whole page, because the canary giver is also a family member shown in the members list.
- Docs went into a second commit (code first), the `Fixes #17` trailer is on the final summary commit.

## Known Stubs

None.

## Self-Check: PASSED
