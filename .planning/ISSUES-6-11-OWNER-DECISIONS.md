# Owner decisions for phases 10-13 (GitHub issues #6, #7, #8, #9, #11)

Recorded 2026-11-17. The owner reviewed the four phases, answered only the open questions and asked for autonomous
execution afterwards. Everything marked "default" was decided by Claude from the code and the issue text and can be
changed by the owner later.

## Owner answers

- **E-mail of a shopping list (#8):** any e-mail address typed by the user (not only members). Guard: at most 5 addresses
  per send and a per-user send limit.
- **AI for recipes from a URL (#9):** no separate consent switch. It works when an administrator has the AI agents on.
  A one-time notice says the page text goes to the AI provider. No personal or health data is involved.
- **Autonomy:** per phase plan, implement, test, review, verify on staging through the browser, push to `develop`
  (staging), open the PR to `main` and continue with the next phase without stopping. The owner approves PRs and the
  production deployment (GitHub environment approval). Claude never approves or deploys production itself.
- **Phase 13 and Phase 12 gates** (full recognised text is stored; the app fetches web pages): both agreed as
  decisions to be taken inside the phases. The defaults below apply.

## Defaults decided by Claude (change by telling Claude)

### Phase 10 (profile and children, #7 and #6)
- A child is a normal `users` row with no e-mail (e-mail becomes nullable, unique when present) and a
  `sign_in_disabled` flag. Such a row can never authenticate (no password, reset, passkey or invitation) and never
  receives mail. It uses the existing Child permission preset and the existing `wishlist_managers` link, so a parent
  manages the child's wish list on the child's behalf.
- An administrator can later turn a child into a normal member by adding an e-mail and sending an invitation.
- A parent or administrator can edit a child's name and photo. A member edits their own.
- Profile photo: same sanitising re-encode as wish images (no metadata), private, shown in every avatar.

### Phase 11 (Shopping, #8)
- New module `shopping`, defaults follow the To-Do module. Not an audited or sensitive module.
- Units: pcs, g, kg, ml, l, pack, bunch, tbsp, tsp (administrator can add more in the section settings).
- Only the responsible person locks, unlocks and marks items Bought or Unavailable (owner comment on the issue).
- Reminder push goes to the responsible person only (owner comment). One reminder per list.
- Archived lists are read-only, can be copied into a new open list.
- If the responsible person is removed from the family, the list owner (creator) becomes responsible.

### Phase 12 (Recipes, #9)
- New module `recipes`. Shared means visible to all members who have the module. Private is author only.
- Fetching the page uses the existing SSRF-safe fetcher (`app/Wishlist/Fetch/SafeFetcher.php`). The page text (not the
  raw HTML) is handed to the AI agent as text input, so the web-search-disabled Codex setup does not change.
- Cooking progress (ticked ingredients and done steps) is stored per member.
- Included in v1 from the issue's own suggestion list: servings with recalculation, preparation time, categories,
  favourites, search and filter, personal notes. Deferred to the backlog: cooking history, meal planning.

### Phase 13 (AI OCR and full-text search, #11)
- Evaluation comes first: measure with and without pre-processing on a labelled synthetic set (degraded copies of
  synthetic documents: rotated, blurred, low contrast, noisy) and keep pre-processing only if it measurably helps.
- The recognised text is stored in its own table, plain text in the database like the other medical data (no field
  encryption, because it must be searchable by Postgres full-text search with `unaccent`).
- Same access as the document: hidden for archived documents and hidden persons, deleted with the document, never in
  exports, logs or the AI calls table. Search results show only documents the viewer may see.
- Prompt and schema version bump, notice text updated, manual updated. Backfill of existing documents only through the
  existing guarded bulk run.

## Suggestions recorded for the owner (not built unless listed above)

- Shopping: starter catalog in sk, pl and en without images, quick "add again" from the last list, items grouped by
  store aisle, shared "needed soon" list, price notes per item.
- Recipes: cooking history, weekly meal planner that builds one combined shopping list, import by photo of a
  cookbook page (reuses the AI image path), tags.
- Profile: language and theme preference moved into the profile page, notification preferences there, a "sign out
  other devices" shortcut.
- Child accounts: birthday as an occasion in Wishlist, age shown, one-click convert to normal account.
- AI and documents: the stored recognised text could also drive a "documents mentioning this drug" view.
