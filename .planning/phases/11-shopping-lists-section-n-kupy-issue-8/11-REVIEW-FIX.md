---
phase: 11-shopping-lists-section-n-kupy-issue-8
fixed_at: 2026-11-17T00:00:00Z
review_path: .planning/phases/11-shopping-lists-section-n-kupy-issue-8/11-REVIEW.md, 11-REVIEW-UI.md
iteration: 1
findings_in_scope: 31
fixed: 28
skipped: 3
status: partial
---

# Phase 11: Code Review Fix Report

**Fixed at:** 2026-11-17
**Source reviews:** `11-REVIEW.md` (backend) and `11-REVIEW-UI.md` (frontend)
**Iteration:** 1

All work is on `main`, one commit per finding or small group, every commit carries `Refs #8`. Nothing was pushed. No pushed migration was edited and no new migration was needed.

## Fixed (backend)

- **WR-01** previous responsible person keeps access (commit 1ccfb2c). After a hand-over they become a contributor (viewer if they no longer hold Shopping edit); the creator needs no row. Manual en/sk/pl. Logic change, requires human verification.
- **WR-02** `ResponsiblePerson::effective` (1ccfb2c): stored person, then creator only if eligible, then the lowest-id eligible contributor; otherwise the creator stays as the readable last resort and `hasActive()` is false. An administrator who can sign in and holds Shopping edit then acts as the responsible person and can reach such a list (`ListQuery::visibleTo`, admin only). Manual en/sk/pl. Logic change, requires human verification.
- **WR-03** mail addresses (b4d8158): new `MailAddress` (single line, plain dot-atom local part, host with a dot, `email:rfc,strict`, Symfony `Address` check). The mailer validates every address before a rate-limit slot is spent. The per-address cap is keyed on the lower-cased address without the `+tag`. `queue:prune-failed --hours=72` is scheduled daily. Tests: CRLF, folded and plain quoted address, comment, `a@localhost`, `a@b`, `+tag` variants, mailer-level refusal. Manual en/sk/pl.
- **WR-04** `staging:audit` (8b38c2c): null creator is flagged for items, catalog items, stores and custom units (units with a `key` are the built-ins). Tests for each table and for the built-in exemption.
- **WR-05** reminders (6caf923): consumed only when an outbox push row was written; with push off or no subscription it stays pending. Nothing older than `shopping.reminder_max_age_hours` (24) fires, and the handler drops an older queued row at send time. Manual and help en/sk/pl. Logic change, requires human verification.
- **IN-01** archive and overview eager load responsible, creator and the viewer's member row (fb4c884/b922036 group); `ShoppingAccess::role` uses the loaded relation.
- **IN-02** concurrent `save_to_catalog` no longer returns 500 (savepoint plus re-read of the winner's row). Test with a second connection.
- **IN-03** a merge that would pass 999.99 is refused with `shopping.error.quantity_merge_too_large`; a note on a merged add is appended (refused with `note_too_long` if it would pass 200).
- **IN-06** `contributor_ids` and `viewer_ids` max 50 (`members_count`), 500 items per list (`items_limit`). Manual en/sk/pl.
- **IN-04** emoji-only names keep distinct `NameKey`s (falls back to the lower-cased name) instead of being rejected.
- **IN-07 (part)** orphan files of a failed image swap are deleted at once. The throttle-counts-malformed-requests part is left as is, as decided.
- **Lang keys** added `shopping.error.lock_invalid`, `state_invalid`, `quantity_invalid` in en/sk/pl, plus `quantity_merge_too_large`, `items_limit`, `members_count`, `list_changed`, `too_many_requests`. `forbidden` is now used.
- **UI WR-09** `stores` and `responsibleCandidates` are sent to the overview only when `canCreate`.
- **UI WR-07 (server part)** catalog and store creation flash `created_id`.
- Carry name: the server already cuts with `mb_substr` (code points), unchanged.

## Fixed (frontend)

- **CR-01** (e242d7f) `useMarkQueue`: per-list FIFO queue, absolute-state PUTs one at a time (next one started after Inertia has dropped the finished request), per-item optimistic override kept until that item's own request settles, rollback and toast for the failed item only. Unlock and Finish are disabled while any mark is in flight, and the finish request waits for the queue (`wait` prop). A second tap on an item whose own mark is still queued is ignored. Vitest: several quick taps send every mark in order, none lost, first request never aborted, failure rolls back only that item, Finish/Unlock disabled, Finish waits for the drain.
- **WR-01, WR-02, WR-03** (47a2c08) shared `failure.js`: 403 shows `forbidden`, 404/409 show `list_changed` and reload the page (a vanished list goes to the overview), 429 says to wait, other statuses use the generic text. Wired into mark, lock, finish (dialog closes after a reload), create, copy, add items, edit/remove item, list settings, e-mail, all three Settings tabs, list delete, and the archive paging error state (5xx and no connection now reach `loadFailed`). 419 stays with the existing global redirect to sign-in.
- **WR-04** names are cut with `Array.from` code points in the finish, copy and picker dialogs (`limits.js`).
- **WR-05** (6064f41) typed quantity next to the steppers, steps 1 / 0.1 (kg, l) / 10 (g, ml), unit change converts g to kg and ml to l and rounds a fraction away from units without fractions, an invalid quantity blocks Add with an inline message.
- **WR-06** pressed Bought/Unavailable uses a different icon, a thick border and bold text, not colour alone.
- **WR-07** uploads go to the id returned by the server; 413/5xx/no connection show in the dialog and a retry repeats only the picture.
- **WR-08** guards on remove item (now `router.delete`, no stale transform), list delete and the e-mail dialog (`sending` no longer reset on reopen).
- **WR-09** see backend. **WR-10** reminder dates are formatted in the family time zone. **WR-11** overview rows navigate with `router.visit` on a plain click (modified clicks stay native), the tab and archive page live in the URL through a client-side `router.replace`, and the tab follows Back.
- **WR-12** a `shopping.*` message without a translation falls back to the generic text everywhere.
- **IN-01** toasts sit above the phone sticky bar (`--fh-bar-lift`). **IN-02** persistent polite live region announces `shopping.progress.all_done`. **IN-04** one `NAME_MAX` constant (plus `NOTE_MAX`, `MAX_ITEMS`) instead of six hard-coded 80s, and a paste of more than 5 addresses shows the too-many error.

## Skipped

- **Finish placement on desktop** (UI IN-03): documented deviation, kept.
- **Migration hygiene** (IN-08): no new migration was required, so there was nothing new to add `IF EXISTS` to; pushed migrations untouched. The missing indexes, the unused `users.email` select and the DST-gap check are not done.
- **Unit or store deletion changing archived lists** (IN-05): kept, documented.
- Not in the include list and left: UI IN-04 duplicate error text and focus, IN-05 (360 px bottom navigation needs a visual check), IN-06 minor items.

## Notes for the owner

- Native review needed: the new Slovak and Polish strings in `lang/sk.json` and `lang/pl.json` (`shopping.error.*`), the `resources/manual/{sk,pl}/shopping.json` edits (hand-over, no active responsible person, mail addresses, reminder, item limits, picker quantity, marks and reload) and the `shopping.reminder` help text in `resources/help/{sk,pl}.json`.
- The administrator fallback means an administrator with Shopping edit can see and act on a list nobody else can; it is limited to lists where no eligible person exists.
- `ListQuery::visibleTo` runs one extra query for administrators (scan of open and locked lists).

## Verification

Isolated compose project `fh1111fix` (own Postgres, scratch folder over `storage/logs`), serial, removed with `down -v`. Full serial Pest suite: 3305 passed, 1 skipped (was 3240+ before). Pint `--test`: pass (1058 files). Full Vitest: 167 files, 5506 tests passed (shopping, a11y sweep, manual, help coverage and i18n-phase11 included).

---

_Fixed: 2026-11-17_
_Fixer: Claude (gsd-code-fixer)_
_Iteration: 1_
