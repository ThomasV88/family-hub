# Phase 11: Shopping lists (Nákupy, issue #8) - Research

**Researched:** 2026-11-17
**Domain:** Laravel 13 / Inertia 3 + Vue 3 / PostgreSQL: a new module on the existing permission layer, per-record access list, state machine, private images, outbox push reminder, plain-text mail to arbitrary addresses
**Confidence:** HIGH. Every fact marked `[VERIFIED: codebase]` was read from this repository in this session. No new Composer or npm package is needed, so there is no slopcheck step. Items marked `[ASSUMED]` are design recommendations or unverified expectations.

<user_constraints>
## User Constraints (from CONTEXT.md)

### Locked Decisions
- New module `shopping` in `app/Access/Module.php`; default grants follow To-Do; not audited, not sensitive.
- Catalog items (name, image, default unit) and store chains (name, logo) are managed in the section's own settings page. Units: pcs, g, kg, ml, l, pack, bunch, tbsp, tsp, extendable by an administrator.
- A list: store, one responsible person (changeable), contributors (edit items), viewers (read only). Only the responsible person locks, unlocks and marks Bought/Unavailable. Locked lists: no add/remove/edit of items.
- Finish shopping appears when every item is Bought or Unavailable; the list moves to the archive. Unavailable items offer a new OPEN list with the same store, responsible person and rights. Archived or existing lists can be copied.
- E-mail: to any typed address (max 5 per send, per-user send limit), plain text with items, quantities, store and responsible person.
- Reminder: one date and time per list; push goes to the responsible person only. If the responsible person is removed the creator becomes responsible.

Rules that apply to every plan:
- Every commit that resolves part of an issue carries `Fixes #N` (one trailer per issue); commits touch explicit paths only (never `git add -A`), no attribution lines, never touch `.planning/config.json`.
- CLAUDE.md "User documentation (mandatory)": manual topic and help tips in en, sk, pl (sk and pl are drafts for native review) and the coverage tests.
- Privacy: permissions enforced server-side, tests for hidden data; no secrets printed.
- Release: develop (staging) first with browser verification on synthetic data, then PR to main. The owner approves production.

### Claude's Discretion
Everything not listed above, following existing codebase conventions.

### Deferred Ideas (OUT OF SCOPE)
See "Suggestions recorded for the owner" in `.planning/ISSUES-6-11-OWNER-DECISIONS.md`. For Shopping that means: starter catalog in sk/pl/en, quick "add again" from the last list, grouping by store aisle, shared "needed soon" list, price notes per item. None of these is built in this phase.
</user_constraints>

<phase_requirements>
## Phase Requirements

| ID | Description | Research Support |
|----|-------------|------------------|
| GH8-SC1 | Create list, add items via catalog pop-up with images, free text always possible, quantity with unit | Data model (items, units), Pattern "Add-item dialog", Pitfall decimal comma |
| GH8-SC2 | Store chain per list; chains and catalog managed in the section settings page | Settings page registry, Image store, Settings gate decision |
| GH8-SC3 | Responsible person, contributors, viewers; only authorised members open a list; server-side | Access matrix, `ShoppingAccess`, privacy tests |
| GH8-SC4 | Lock/unlock by responsible person only; locked = no item edits; only responsible marks Bought/Unavailable | State machine, row lock, idempotent absolute-state endpoint |
| GH8-SC5 | Finish shopping when all items resolved; list goes to archive | State machine, archive query |
| GH8-SC6 | Unavailable carry-over into a new OPEN list keeping store, responsible and rights | Carry-over design (single transaction, unique partial index) |
| GH8-SC7 | Copy an existing or archived list | Copy semantics |
| GH8-SC8 | E-mail the list | Mail design (queued Mailable, plain text, limiters) |
| GH8-SC9 | Reminder date/time, push to the responsible person | Outbox handler design, scheduler command |
| GH8-SC10 | Conflicts/proposals flagged (archived rights, removed responsible, catalog deletion while used, e-mail privacy) | "Decisions taken here" table, Assumptions Log |
</phase_requirements>

## Summary

The module is built almost entirely from existing parts: `Module` enum + `AccessGate` + `access:module,shopping,<level>` route middleware for the coarse grant; a record-level access class modelled on `TaskAccess`/`WishAccess` for the per-list roles; `ImageProcessor` + a new store class modelled on `ProfilePhotoStore` for catalog images and store logos; the notification outbox (`OutboxWriter`, `OutboxHandlers`, `SendOutboxItem`) for the push reminder; and a queued Mailable for the e-mail. The expensive part is not new technology but the ripple of adding a fifth module: `Preset::grants()` is positional, `PermissionService::save()` and `UpdatePermissionsRequest` demand every module in the payload, `module_permissions` has a CHECK constraint listing the four module names, existing members need a backfilled `shopping` grant, and about 30 JS and PHP tests hard-code four modules.

The second ripple is the documentation and test "coverage" machinery. As soon as `MODULES` in `resources/js/Shell/modules.js` gains `shopping`, `ManualCoverage.test.js` demands a manual topic that `covers` `module:shopping`; as soon as `SettingsSections.php` gets `'key' => 'shopping'` it demands `settings:shopping` coverage; every route needs an `EndpointMatrix` row with all seven roles; the help registry has a closed list of areas and screens. Plan the manual skeleton (all section ids, three languages) in the very first UI-facing plan, then fill it.

The third is concurrency. A list is a small state machine (open, locked, done) with several actors on several devices. Every mutating endpoint must take a row lock on the list, re-check the state under the lock and be idempotent (absolute state in the request, not a toggle). Finish plus carry-over must be one transaction guarded by a unique partial index.

**Primary recommendation:** Build in this order: module + permissions ripple (with backfill) -> schema + models -> access class and services (state machine) with Pest tests -> images/catalog/stores/units backend -> list controllers and endpoint matrix -> Vue pages (settings, overview, detail) -> e-mail -> reminder -> docs, staging seed and audit. Treat the privacy differential tests and the state-machine concurrency tests as the phase gate.

## Architectural Responsibility Map

| Capability | Primary Tier | Secondary Tier | Rationale |
|------------|-------------|----------------|-----------|
| Module grant (none/view/edit) | API / Backend (`access:module` middleware, `AccessGate`) | Browser (hides nav) | Server decides; nav hiding is cosmetic `[VERIFIED: codebase modules.js comment]` |
| Per-list roles (creator, responsible, contributor, viewer) | API / Backend (`ShoppingAccess` + list-scoped query) | Database (membership table) | Hidden lists must be a uniform 404, never a client filter |
| Lock / unlock / mark / finish state machine | API / Backend (service, row lock) | Database (CHECK constraints, partial unique index) | Concurrent devices; the DB must reject impossible states |
| Catalog and store images | API / Backend (`ImageProcessor`, private disk, auth route) | Database (paths + version) | Untrusted bytes; never public |
| Catalog search in the add-item dialog | Browser (filter a preloaded list, accent-insensitive) | API (sends the catalog once) | Catalog is small (hundreds); avoids a request per keystroke |
| Quantity/unit validation and formatting | API (validation, `numeric(10,3)`) | Browser (Intl formatting, decimal comma input) | Server is authority; display is locale-specific |
| E-mail of a list | API / Backend (queued Mailable, limiters) | Browser (dialog) | Abuse protection must be server-side |
| Reminder scheduling | API / Backend (scheduler + outbox) | Database (`remind_at`, `reminder_enqueued_at`) | Same path as task reminders |
| Push delivery | Worker container (`SendOutboxItem`) | Service worker (shows it) | Existing infrastructure |

## Standard Stack

No new packages. Everything below already exists in the repo `[VERIFIED: codebase]`.

### Core
| Component | Where | Purpose |
|-----------|-------|---------|
| Laravel 13.17, Pest 4.7, Inertia Laravel 3 | composer.json | Framework and tests |
| Vue 3 + `@inertiajs/vue3`, vue-i18n, `@lucide/vue` (has `ShoppingCart`) | package.json, node_modules | UI; icon export confirmed in `lucide-vue.d.ts` |
| Vitest 5 + axe-core 4.14 + `@vue/test-utils` | package.json, tests/js | JS tests and a11y |
| `App\Wishlist\Catalog\ImageProcessor` | app/Wishlist/Catalog | Sanitising re-encode (EXIF strip, caps, decode lock) |
| `App\Notifications\Outbox\*`, `App\Push\*` | app/Notifications, app/Push | Reminder delivery |
| `Illuminate\Support\Facades\RateLimiter` | framework | Mail limits |

### Alternatives Considered
| Instead of | Could Use | Tradeoff |
|------------|-----------|----------|
| New `ShoppingImageStore` | Extend `WishImageStore` | `WishImageStore` is bound to a `Wish` row. Copying `ProfilePhotoStore` (row-lock swap, version bump, own sweeper) is the proven precedent `[VERIFIED: codebase]` |
| Outbox for the e-mail | Direct queued Mailable | Outbox rows require `user_id` (FK to users) `[VERIFIED: migration]`; recipients here are arbitrary addresses, so the outbox cannot be used |
| Browser polling | Inertia partial reload every N s | Optional for a locked list shown on several devices; precedent: `setInterval` pollers in `OwnWishPanel.vue` (3 s) and `AiLoginHealthCard.vue`. Use 20-30 s and only while the list is locked `[ASSUMED]` |

**Installation:** none.

## Package Legitimacy Audit

No external packages are installed in this phase. Slopcheck not applicable. Packages removed: none. Packages flagged: none.

## Architecture Patterns

### System Architecture Diagram

```
Browser (Vue pages)
  /shopping (overview + archive tab) ---+
  /shopping/lists/{id} (detail)         |   Inertia GET (props filtered server-side)
  Add-item dialog (catalog preloaded)   |
  Settings > Shopping (catalog,stores,units)
        |  Inertia writes (PUT/POST/DELETE, absolute state)
        v
nginx -> php-fpm -> routes/web/shopping*.php
        middleware: access:module,shopping,view|edit  (access:admin for units)
        FormRequest (validation, decimal comma, e-mail list)
        Controller -> ShoppingAccess::can*(user, list)  --no--> 404 (hidden) / 403 (visible, not allowed)
                   -> ListService (DB::transaction, lockForUpdate list row, re-check status)
                           |-> shopping_lists / _items / _list_members (CHECKs, partial unique index)
                           |-> ShoppingImageStore -> private disk wish-images/shopping/...
                           |-> ShoppingMailer -> RateLimiter -> queue (database) -> worker -> SMTP (Stalwart)
        scheduler (every minute)
          shopping:enqueue-reminders -> OutboxWriter (push, dedupe key) -> outbox:dispatch -> SendOutboxItem
                                         -> ShoppingReminderHandler (stillRelevant, pushMessage) -> WebPushSender
```

### Recommended Project Structure
```
app/Shopping/
  ShoppingAccess.php          # record-level rules, abilities() for the UI
  ShoppingListStatus.php      # enum open|locked|done
  ItemState.php               # enum pending|bought|unavailable
  ListRole.php                # enum contributor|viewer (creator/responsible are derived)
  ResponsiblePerson.php       # effective responsible (falls back to creator)
  ListService.php             # create, update header, sync members, lock, unlock, setItemState, finish(+carry), copy, delete
  ItemService.php             # add/update/remove item under the list lock
  Queries/ListQuery.php       # visibleTo(user) scope, overview + archive pages, detail payload
  Queries/CatalogQuery.php
  Images/ShoppingImageStore.php   # catalog + store images, sweeper
  Mail/ShoppingListMailer.php, ShoppingListMail.php (Mailable, text only)
  Reminders/ReminderPlanner.php
app/Models/ShoppingList, ShoppingItem, ShoppingCatalogItem, ShoppingStore, ShoppingUnit, ShoppingListMember
app/Http/Controllers/Shopping/{ListController,ItemController,ItemStateController,LockController,FinishController,CopyController,MailController,ReminderController,MembersController,ImageController}.php
app/Http/Controllers/Settings/Shopping{Catalog,Stores,Units}Controller.php (or one SettingsShoppingController)
app/Notifications/Outbox/ShoppingReminderHandler.php
app/Console/Commands/EnqueueShoppingReminders.php, PruneShopping.php (or fold sweeper into wishlist:prune)
routes/web/shopping.php, shopping-items.php, shopping-images.php, settings-shopping.php  (the glob in routes/web.php loads every file)
resources/js/Pages/Shopping/{Index,Show}.vue, Pages/Settings/Shopping.vue
resources/js/Shopping/*.vue  (ListCard, ItemRow, AddItemDialog, ListPanel, FinishDialog, MailDialog, ReminderField, ...)
resources/manual/{en,sk,pl}/shopping.json ; resources/help additions
database/seeders/Staging/ShoppingSeeder.php
```

### Pattern 1: Module + coarse gate + record-level class
Route middleware `access:module,shopping,view|edit` (GET view, writes edit) decides the module grant. Inside the controller a `ShoppingAccess` class (constructor-injected `AccessGate`, no admin bypass) decides per list, exactly as `TaskAccess` does (`hasEditGrant()` plus creator/assignee) `[VERIFIED: codebase app/Tasks/TaskAccess.php]`. Loading is always through a scope (`ShoppingList::visibleTo($user)`, like `Task::scopeVisibleTo`) so a list you cannot see is `findOrFail` -> 404, the same as an unknown id.

### Pattern 2: State change under a row lock, absolute target state
```php
// Source: pattern of app/Profile/ProfilePhotoStore::swap and app/Wishlist/WishlistManagerSync [VERIFIED: codebase]
DB::transaction(function () use ($listId, $actor, $target) {
    $list = ShoppingList::query()->whereKey($listId)->lockForUpdate()->firstOrFail();
    abort_unless($this->access->canLock($actor, $list), 403);          // effective responsible only
    abort_if($list->status !== ShoppingListStatus::Open, 409);          // idempotent callers treat "already locked" as success
    abort_if(! $list->items()->exists(), 422);                          // cannot lock an empty list
    $list->forceFill(['status' => 'locked', 'locked_at' => now(), 'locked_by' => $actor->id])->save();
});
```
Item add/edit/delete take the same `lockForUpdate` on the list row and re-check `status = open`, so a lock that races an add either wins first (the add gets 409) or loses (the add lands before the lock). Item state changes (`PUT .../items/{item}/state` with `{state: pending|bought|unavailable}`) are absolute, so a double tap or a retry converges and never flips twice.

### Pattern 3: Private image route
Copy `ProfilePhotoController::show`: `private, no-cache` + ETag + `nosniff` + `Vary: Cookie`, every refusal the same 404, immutable query-string version (`?v=`) for the client `[VERIFIED: codebase]`. Route names must be added to the allowlist in `NoStoreDynamicResponses` (it lists `wishlist.images.show`, `health.documents.preview`, `profile.photo.show` today) or the middleware overwrites the headers with `no-store` and every thumbnail re-downloads `[VERIFIED: codebase]`.

### Pattern 4: Add-item dialog
The detail page already receives the catalog (id, name, defaultUnitId, thumb version) once (a lazy/`Inertia::once`-style prop, or a prop of `Pages/Shopping/Show`); the dialog filters it in the browser with `normalize('NFD').replace(/\p{M}/gu,'')` + lower-case so "mliecko" finds "Mlieko". The last row of the dialog is always "Add 'typed text' as a free item". Quantity input accepts `0,5` and `0.5`. Use `UiDialog`, `UiField`, `UiInput`, `UiSelect` from `resources/js/Components/ui` `[VERIFIED: codebase]`.

### Anti-Patterns to Avoid
- **Positional module arrays:** `Preset::grants()` pairs levels to `Module::cases()` by index `[VERIFIED: codebase]`. Adding a case silently mis-assigns levels. Rewrite it as an explicit map keyed by module value while adding `Shopping`.
- **Toggle endpoints** for bought/unavailable (not idempotent on retries).
- **Reading list items through the catalog** for display: items carry their own `name`, `unit_id`, `quantity`; the catalog link only supplies the image and may disappear.
- **Admin bypass:** admin sees no list by being admin (matches tasks and wishlist `[VERIFIED: codebase "There is no admin bypass"]`).
- **Escaping plain-text mail via Blade `{{ }}`:** it HTML-encodes `&` and quotes. Build the body as a string and pass it as `HtmlString` (as `PlainTextMail::withPlainPart` does) or use `{!! !!}` `[VERIFIED: codebase trait]`.

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| Image sanitising | New GD code | `ImageProcessor::process(bytes, max, fullPx, thumbPx)` | Handles type sniff, pixel cap, EXIF, decode lock, memory budget `[VERIFIED]` |
| Reminder dedupe and retry | Own sent-flag and retry loop | `OutboxWriter::enqueue` with a unique `dedupe_key` + `SendOutboxItem` | Unique key, backoff, stuck-row recovery already exist `[VERIFIED]` |
| Permission checks | Inline `is_admin`/level compares | `AccessGate`, `access:` middleware, one `ShoppingAccess` | Matrix tests enforce it |
| Mail throttling | Counter table | `RateLimiter` / `throttle:` middleware with a named prefix | Cache table driver exists; precedent `throttle:20,1,wishlist.images.store` |
| Reminder time maths | Offset arithmetic | Convert a family-timezone local date-time to UTC with Carbon and `config('family.timezone')` | Same convention as tasks (`Europe/Bratislava` default) `[VERIFIED: config/family.php]` |
| Settings page chrome | New layout | `SettingsLayout.vue` + `SettingsSections::for()` | Pages like `FinanceAccounts.vue` show the pattern |
| Quantity arithmetic | Float math | `numeric(10,3)` in Postgres, string through PHP, `Intl.NumberFormat` for display | Avoids 0.1+0.2 artefacts |

## Codebase Facts the Planner Needs

### A. Adding a module end to end `[VERIFIED: codebase]`
1. `app/Access/Module.php`: add `case Shopping = 'shopping';`. `audited()` and `sensitive()` need no change (not listed = false). Because `RequireAccess` only audits/gates for those two, no other middleware change.
2. `app/Access/Preset.php`: `grants()` uses `match` with a positional list of four `AccessLevel`s zipped with `Module::cases()`. Rewrite to a keyed map. Targets (follow To-Do): Parent edit, Child edit, Grandparent edit, Guest view (Guest has To-Do view). Existing `PresetTest` hard-codes the four-key arrays and the test "covers all four modules"; update to five.
3. `database/migrations/2026_11_13_110000_create_module_permissions_table.php` created `module_permissions_module_check CHECK (module IN ('todo','wishlist','finance','health'))`. New migration must drop and re-add the constraint with `'shopping'`, then backfill: `INSERT INTO module_permissions (user_id, module, level, created_at, updated_at) SELECT user_id, 'shopping', level, now(), now() FROM module_permissions WHERE module='todo' ON CONFLICT (user_id, module) DO NOTHING`. Without the backfill every existing member loses the new section (the gate defaults to `None`). `down()` must delete the shopping rows before restoring the old constraint.
4. `PermissionService::save()` throws `InvalidArgumentException` unless every `Module::cases()` key is present and rejects unknown keys; `UpdatePermissionsRequest` has the same rule. So the front end must send `shopping`: `resources/js/Settings/presets.js` `MODULE_KEYS = ['todo','wishlist','finance','health']` must gain `'shopping'` (it drives `PermissionMatrix.vue`, `PermissionsTab.vue`). Add `permissions.module.shopping` and update the four `permissions.preset.*_hint` strings in `lang/{en,sk,pl}.json`.
5. `HandleInertiaRequests` shares `auth.access` = `AccessGate::levels($user)` (iterates all modules) so the front end gets `shopping` automatically `[VERIFIED: line 73]`.
6. Grants are written from presets in `UserFactory::withPreset`, `InviteService` line 209, `CreateChild` line 34, all by looping `grants()`; they pick up `shopping` once `Preset` is fixed. `StagingSeeder` sets grants beyond presets; check it still passes.
7. Navigation: `resources/js/Shell/modules.js` `MODULES` + `ACCESS_KEY`. Add `{ key: 'shopping', labelKey: 'nav.shopping', titleKey: 'module.shopping', icon: ShoppingCart, href: '/shopping' }` and `ACCESS_KEY.shopping = 'shopping'`; lang keys `nav.shopping`, `module.shopping`. The bottom nav is a single grid row (`grid-auto-flow: column`), so six items on a phone must be checked in a browser (labels short: "Nákupy", "Zakupy", "Shopping"). `tests/js/shell/moduleAccess.test.js` and `AppShell.test.js` assert the exact module lists and must be updated.
8. Test fixtures that list four modules (grep found): `tests/Feature/Access/PresetTest.php`, `AccessGateTest.php`, `Settings/MembersPageTest.php`, `Settings/PermissionSaveTest.php`, `tests/Support/EndpointMatrix/settings-members.php`, and JS tests `Home`, `todo/*`, `shell/AppShell`, `finance/*`, `wishlist/*`, `settings/*`, `errors/Forbidden`, `security/a11y-security`, `health/AiReviewEdit`. Most only supply an `access` object (extra or missing key harmless); the ones that assert the exact permission payload or list need edits. Run the whole suites once after the enum change and fix the red ones in the foundation plan.
9. `ProfilePhotoAccess::canView` lets anyone with any module != none see profile photos; a Shopping-only member qualifies automatically (loop over `levels`).

### B. Settings section registry and coverage `[VERIFIED: codebase]`
- `app/Support/SettingsSections.php` is a plain list; add a block guarded by `Route::has('settings.shopping')` and a gate check (`app(AccessGate::class)->allows($user, Module::Shopping, AccessLevel::View)`). `SettingsShellTest` expects exactly 3 sections for a member with no grants, so gating on the module keeps that test green.
- `ManualCoverage.test.js` greps `'key' => '(\w+)'` from this file: every key needs `settings:<key>` in some manual topic `covers` (non-admin topic unless the line has `$user->is_admin && ...`). The admin-only units card is not a separate settings key: put it in a section of the shopping topic flagged `adminOnly: true` with its own text.
- Same test: `MODULES` each need `module:<key>` coverage; `covers` may only name existing modules/settings keys/FEATURES; sk and pl topics must have the same files, section ids, block types and counts, item counts, links (parity test); no HTML, no `**` leftovers; internal links must resolve to `Route::get('...')` literals found by regexp in `routes/web*.php` (a link to `/shopping` requires a `Route::get('/shopping'` literal) and links to admin pages only from `adminOnly` parts. The `adminPaths` set is fixed, so a new admin path would need a test edit.
- Contextual help: `resources/help/topics.json` (key + `area` + `screen` + `field`, place must be unique), texts in `resources/help/{en,sk,pl}.json`; each of these files also has closed `areas` and `screens` maps, so a new area `shopping` and new screens (e.g. `list_panel`, `settings_catalog`) must be added to all three files. Text max 400 chars. `HelpCoverage.test.js` fails on an unknown or unused topic (it greps `topic="..."`, `help="..."`, `:help="...'a.b'..."` in all `.vue` files). `php artisan help:sync` carries defaults into the DB on deploy.
- Lang: `lang/{en,sk,pl}.json` are nested JSON loaded whole into the shared `messages` prop. Add a top-level `shopping` object. Parity test style (`tests/js/i18n-phase10.test.js`) iterates named subtrees: add `tests/js/i18n-phase11.test.js` with `SUBTREES = ['shopping', ...]`.

### C. Wishlist and To-Do analogues `[VERIFIED: codebase]`
- `Task`: `creator_id` FK users `restrictOnDelete`, `assignee_id` nullOnDelete, CHECK constraints for enum columns, partial indexes, `task_members` pivot (unique pair, index on user) for "selected" visibility; `scopeVisibleTo` builds an OR with `whereExists`. Controller loads with `visibleTo`, then `abort_unless` abilities; the Inertia props include `abilities` per row computed server-side (copy this: `ShoppingAccess::abilities($user, $list)`).
- Member pickers: `TaskController::index` selects only people with the module level >= view, names only. For Shopping add `sign_in_disabled = false and email is not null` (use `User::canSignIn()` semantics) because a Phase 10 child cannot sign in and could never lock or view `[VERIFIED: User::canSignIn]`.
- `WishlistManagerSync` shows the "replace a relationship set under a user row lock, return added ids" pattern and `isEligible()` (canSignIn + module Edit). Model `ShoppingMembers::sync()` the same way, with the list row locked.
- Finance accounts: per-record member table `finance_account_members` (composite PK, cascade) and an admin settings page `FinanceAccounts.vue`; precedent for a list page with a side panel (`UiDetailPanel`).
- Member removal: the app has no "delete member" route (only the staging seeder deletes users, and `tasks.creator_id` is RESTRICT). So "removed from the family" can only mean: user row deleted by ops/seeder, grant dropped below the needed level, or `sign_in_disabled` set. Resolve this lazily (see Decisions), do not rely on an event.

### D. Images `[VERIFIED: codebase]`
- Storage: private `local` disk. The only persisted and backed-up path on the uploads volume is `wish-images/` (Dockerfile creates `storage/app/private/wish-images`; `docs/ops/server.md` line 79). Profile photos therefore live under `wish-images/profile-photos/`. Use `wish-images/shopping/catalog/{id}/` and `wish-images/shopping/stores/{id}/`.
- **Sweeper trap:** `WishImageStore::sweepOrphans` walks `allFiles('wish-images')` and deletes everything not referenced by a `Wish` row older than 60 min, skipping only `wish-images/profile-photos/`. It is called by `wishlist:prune` daily. It will delete every shopping image unless `wish-images/shopping/` is also skipped there. Change that skip to a prefix list and add a unit test; give `ShoppingImageStore` its own `sweepOrphans` and call it from `PruneWishlist` (or a new `shopping:prune` scheduled daily in `routes/console.php`).
- Route allowlist: add the new route names to `NoStoreDynamicResponses` (see Pattern 3).
- nginx: `docker/nginx/snippets/laravel-locations.conf` has per-route `location ~` blocks with `client_max_body_size 8m` for `/wishlist/wishes/N/image` and `/people/N/photo`; everything else keeps 1m. Add one block for `^/shopping/(catalog|stores)/[0-9]+/image$` (8m with app cap 5 MiB) with the same two `include` lines. A test already reads this file (`ImportPreviewTest` counts `client_max_body_size 3m;` occurrences exactly once), so do not add another `3m` block.
- `ImageProcessor::process($bytes, $maxBytes, $fullPx, $thumbPx)` output is a fresh JPEG on a white canvas, so a transparent logo gets a white background `[VERIFIED: resample()]`. Acceptable; state it in the help text. Suggested config `config/shopping.php`: catalog image full 640 / thumb 240, store logo full 320 / thumb 120, max bytes 5 MiB, name max 80 / 60, item note max 200, quantity bounds.
- Upload UX: Inertia multipart POST with `useForm`; errors come back as translation keys (`ValidationException::withMessages(['image' => 'shopping.image.err_size'])`), the same as profile photos.
- Cleaning when a catalog item or store is deleted: remove files after commit (`DB::afterCommit`), the sweeper is the safety net. Staging purge must remove `wish-images/shopping/...` directories of synthetic rows.

### E. Push, reminders, outbox `[VERIFIED: codebase]`
- Task reminders: `EnqueueReminders` (scheduled every minute, `withoutOverlapping`) selects due rows `FOR UPDATE SKIP LOCKED` in a transaction, writes outbox rows per recipient and channel with dedupe key `task_reminder:{id}:{at}:{user}:{channel}`, then nulls `remind_at`. `OutboxHandlers::HANDLERS` registry maps kind to handler; a handler implements `kind()`, `stillRelevant()`, `sendMail()`, `pushMessage()` (title + same-origin url).
- `SendOutboxItem` claims atomically, re-checks `stillRelevant` and `NotificationPreferences::enabled($user, $channel)` at send time, so a skipped row is normal. **Default preferences: mail on, push OFF** (`NotificationPreferences::enabled`), and a push is only enqueued if the user has a `PushSubscription` row. The responsible person must have turned push on in Settings > Notifications; the reminder field needs a hint and help tip saying so. The notifications page intro text currently says "reminded about tasks" and should be reworded (`notifications.intro`, and the manual topic that describes it).
- Design: `shopping_lists.remind_at timestamptz null` (UTC instant, entered as family-timezone local date and time) + `reminder_enqueued_at timestamptz null`. Command `shopping:enqueue-reminders` (every minute, `withoutOverlapping`): lists with `remind_at <= now()`, `reminder_enqueued_at is null`, `status <> 'done'`; recipient = effective responsible; channel push only (the issue asks for push; no e-mail reminder); `dedupe_key = "shopping_reminder:{listId}:{remindAtTs}:{userId}:push"`; set `reminder_enqueued_at = now()`. Editing `remind_at` resets `reminder_enqueued_at` to null. `ShoppingReminderHandler::stillRelevant` = list exists, not done, `remind_at` timestamp equals payload, the recipient is still the effective responsible and still has module view and `canSignIn()`. `sendMail` is never called (only push rows are written) but must be implemented; throw a `LogicException`. Payload carries ids and timestamps only (the writer's docblock forbids titles). Push title built from the list name at send time in the recipient's locale: new keys in `lang/{l}/notifications.php`; `url` `/shopping/lists/{id}`.
- Index: partial index on `remind_at` where `remind_at is not null and reminder_enqueued_at is null` (mirrors `tasks_remind_at_index`).
- A reminder time in the past is rejected on save (so editing other fields of an old list does not re-fire); an already-consumed reminder shows as "sent".

### F. Mail `[VERIFIED: codebase]`
- There is no Mailable in the repo; mail goes through Notifications. `InviteService` sends to an arbitrary address with `Notification::route('mail', $address)->notify(...)` and a `ShouldQueue` notification with a `failed()` hook. Default mailer is env `MAIL_MAILER` (log by default; production SMTP is the owner's Stalwart on the `mail` stack, port 587, per CLAUDE.md global notes). Queue: `database` driver, separate worker container `[VERIFIED: config/queue.php; CLAUDE.md constraints]`.
- Recommended: a queued `Mailable` with a text-only content (no HTML part). Build the body once at request time as a string snapshot (what the sender saw is what is mailed), in the **sender's** locale. Content: list name, store name, responsible person's name, one line per item `- 2 kg Potatoes`, a note column for item notes, item states if locked/archived (Bought/Unavailable markers are optional; plain list of items is the minimum). No link into the app (recipients may not be members), no e-mail address of any member (no Reply-To, no member e-mails in the body); the "From" is the app default. Subject: `list name` with CR/LF stripped.
- Abuse protection (server-side): FormRequest `addresses` array, min 1, max 5, each `email:rfc`, `max:254`, lower-cased, de-duplicated; route middleware `throttle:` plus a named limiter `shopping-mail` = per user `Limit::perHour(10)` and `Limit::perDay(30)`; plus a per-recipient-address cap (e.g. 5 per day, key `sha1(address)`) so the feature cannot be used to flood one inbox; a refused request returns a translated 429 message (see `AppServiceProvider` limiter handlers for the response convention). Exact numbers are `[ASSUMED]`; the owner only fixed "max 5 per send and a per-user limit".
- Who may send: creator, responsible, contributors (module Edit); viewers and members outside the list cannot. A child with no e-mail may send (it needs none).
- Header injection: validate addresses with Laravel's `email:rfc`; Symfony Mailer rejects CR/LF in headers; test it.
- Failure feedback: the request returns "Queued" immediately; a hard failure ends in `failed_jobs`. Show the success toast as "The e-mail is being sent". Do not claim delivery.

### G. Units and quantity `[ASSUMED design, shaped by codebase conventions]`
- `shopping_units(id, key varchar(20) unique nullable, name varchar(30) nullable, position, archived_at)`. Built-in rows are inserted by the migration with `key` in (`pcs`,`g`,`kg`,`ml`,`l`,`pack`,`bunch`,`tbsp`,`tsp`) and no `name`; their label comes from `shopping.unit.<key>` in the three language files (so SK says "ks", PL "szt."). Administrator-added units have `key = null` and a free `name` (one string for all languages). A CHECK enforces `(key is not null) <> (name is not null)`. Units are archived, never hard-deleted (items and catalog rows reference them by FK `restrictOnDelete`); built-ins cannot be archived.
- Items: `quantity numeric(10,3)` CHECK `> 0 AND < 1000000`, `unit_id` nullable (a unit-less "3 apples" is normal). Input decimal comma must be normalised before validation (`str_replace(',', '.', ...)` in `prepareForValidation`); Slovak and Polish users type `0,5`. Display with `Intl.NumberFormat(locale, { maximumFractionDigits: 3 })`.
- Carry-over and copy keep `quantity` and `unit_id` as is.

### H. Staging and tests `[VERIFIED: codebase]`
- `StagingSeeder` runs on every staging deploy; it purges synthetic users' rows table by table in `deleteSyntheticOwnedRows` (RESTRICT FKs such as `tasks.creator_id` must be deleted first). New tables need: a `ShoppingSeeder` in `database/seeders/Staging/` called from `StagingSeeder`; purge lines (lists whose creator is synthetic; catalog items and stores `created_by` synthetic; delete image directories after commit like the profile photos); a rule in `StagingAudit::rules()` (`created_by is null or not in synthetic`) for every new owner-linked table; additions to `demoCounts()` in `tests/Feature/StagingDemoSeedTest.php`; a note in `docs/ops/staging-data.md`. Seed ~30 catalog items, 5 stores with generated logos (draw with GD in the seeder and pass through `ImageProcessor`, as the seeder already does for a child photo), 6-8 lists across all states (open, locked half-bought, done, with a carry-over, one with a reminder, one with contributors and viewers).
- Pest runs against Postgres with `RefreshDatabase` (`tests/Pest.php`); HTTP matrix rows must list all seven roles (`guest, admin, parent, child, grandparent, guestPreset, noGrants`); expectations are literal.
- Role semantics for the matrix with the new module: parent/child/grandparent edit, guestPreset view, admin and noGrants none (admin has no grant: 403). Row spec: `method`, `uri`, optional `params` closure (create fixtures), `data`, `expect`.
- `SensitiveGateMatrix` / `SensitiveRoutes` concern finance/health URIs and names only; shopping routes must NOT contain `health` or `medical` in name or URI, and must not live in `App\Http\Controllers\Health`. No change needed there. The admin-only units routes use `access:admin` (not `access:recent`, as nothing sensitive is granted).
- JS: Vitest with `mountUi` and axe helpers (`tests/js/helpers`); `a11y-sweep.test.js` mounts pages with a mocked Inertia; pseudo-long strings test layout. Add Shopping pages to it.

## Data Model (recommended)

All timestamps `timestampTz`, enum-like columns `string` + CHECK (the repo convention), FKs explicit, new tables inherit app-role privileges via default privileges `[VERIFIED: docker/postgres/init/01-roles.sh]`.

```
shopping_units
  id bigint pk, key varchar(20) null unique, name varchar(30) null, position smallint, archived_at null, timestamps
  CHECK ((key IS NOT NULL) <> (name IS NOT NULL))
  UNIQUE (lower(name)) WHERE name IS NOT NULL

shopping_stores
  id, name varchar(60) not null, logo_path null, logo_thumb_path null, image_version int default 0,
  created_by FK users nullOnDelete, timestamps
  UNIQUE (lower(name))

shopping_catalog_items
  id, name varchar(80) not null, default_unit_id FK shopping_units null (restrict),
  image_path null, thumb_path null, image_version int default 0,
  created_by FK users nullOnDelete, timestamps
  UNIQUE (lower(name))   -- accent-insensitive uniqueness is not needed; the UI search is accent-insensitive

shopping_lists
  id bigint, name varchar(80) not null,
  store_id FK shopping_stores null ON DELETE SET NULL, store_name varchar(60) null  -- snapshot set when the list is finished
  creator_id FK users restrictOnDelete, responsible_id FK users null ON DELETE SET NULL,
  status varchar(8) default 'open'  CHECK IN ('open','locked','done'),
  locked_at null, locked_by FK users null set null, done_at null, done_by FK users null set null,
  remind_at null, reminder_enqueued_at null,
  source_list_id FK shopping_lists null ON DELETE SET NULL, source_kind varchar(5) null CHECK IN ('copy','carry'),
  timestamps
  CHECK ((status='done') = (done_at IS NOT NULL))
  CHECK (status <> 'locked' OR locked_at IS NOT NULL)
  CHECK ((source_list_id IS NULL) OR (source_kind IS NOT NULL))
  UNIQUE (source_list_id) WHERE source_kind='carry'        -- one carry-over list per finished list (double submit safe)
  INDEX (status), INDEX (creator_id), INDEX (responsible_id), INDEX (done_at DESC) WHERE status='done'
  INDEX (remind_at) WHERE remind_at IS NOT NULL AND reminder_enqueued_at IS NULL

shopping_list_members              -- contributors and viewers only
  list_id FK cascade, user_id FK cascade, role varchar(12) CHECK IN ('contributor','viewer'), created_at,
  PK (list_id, user_id), INDEX (user_id)

shopping_items
  id, list_id FK cascade, catalog_item_id FK null ON DELETE SET NULL,
  name varchar(120) not null, quantity numeric(10,3) not null default 1 CHECK (quantity > 0 AND quantity < 1000000),
  unit_id FK shopping_units null (restrict), note varchar(200) null,
  state varchar(12) default 'pending' CHECK IN ('pending','bought','unavailable'),
  state_changed_at null, state_changed_by FK users null set null,
  position integer not null, created_by FK users null set null, timestamps
  INDEX (list_id, position)
```

Notes:
- `responsible_id` is nullable only so a user row deletion does not fail; the effective responsible is `responsible_id` if that user `canSignIn()` and holds shopping Edit, else `creator_id` (see Decisions). Never write the fallback back silently except through an explicit "reconcile" path; the lazy resolver is enough.
- Bigint ids like `tasks`; hidden lists answer 404 uniformly, so enumeration yields nothing.
- Sorting overview: open and locked lists by `updated_at desc`; archive by `done_at desc`, paginated (20 per page).
- Progress counts for the overview card via two `withCount`-style subselects (items total and resolved), not by loading items.
- Row locking granularity: the list row is the lock for everything under it (items, members, status, reminder).

## Access Rules Matrix

"Module level" is the AccessGate level for `shopping`. Every row first requires the module level shown. There is no admin bypass. A member with no role on a list gets 404 (uniform with unknown id).

| Action | Needs module | Creator | Responsible (effective) | Contributor | Viewer | Other member | Admin without role |
|--------|-------------|---------|------------------------|-------------|--------|--------------|--------------------|
| See list, items, archived list | view | yes | yes | yes | yes | 404 | 404 |
| Create a list | edit | n/a | n/a | n/a | n/a | yes (becomes creator) | only with grant |
| Edit header (name, store), reminder | edit | yes | yes | no (403) | no | 404 | 404 |
| Set responsible, contributors, viewers | edit | yes | yes | no | no | 404 | 404 |
| Add, edit, remove items (state open) | edit | yes | yes | yes | no | 404 | 404 |
| Lock, unlock | edit | no | **yes only** | no | no | 404 | 404 |
| Mark Bought / Unavailable / pending (state locked) | edit | no | **yes only** | no | no | 404 | 404 |
| Finish shopping (+ carry-over choice) | edit | no | **yes only** | no | no | 404 | 404 |
| Copy a list | edit | yes | yes | yes | yes | 404 | 404 |
| Send e-mail | edit | yes | yes | yes | no | 404 | 404 |
| Delete a list (open or done; locked must be unlocked first) | edit | yes | no | no | no | 404 | 404 |
| Archived list: any mutation except copy and delete by creator | n/a | 409 | 409 | 409 | 409 | 404 | 404 |
| Catalog items, stores: add, edit, image | edit | any member with edit | | | | | |
| Catalog items, stores: delete | edit | store blocked while an open or locked list uses it | | | | | |
| Units: add or archive | admin (and module view to reach the page) | | | | | | admin |

A view-only module level (`guestPreset`) acts as viewer everywhere even if listed as contributor or responsible; the picker only offers eligible people (`canSignIn()`; responsible and contributor need edit; viewer needs view) and every write re-validates eligibility server-side (a form could send any id).

## Decisions taken here (flagged for the owner, issue section 8)

| # | Question | Default chosen | Why |
|---|----------|----------------|-----|
| D1 | Rights on archived lists | Read-only for everyone; copy allowed; creator can delete | Matches the owner decision "archived lists are read-only, can be copied" |
| D2 | Responsible removed (row deleted, loses shopping Edit, or cannot sign in) | Effective responsible = creator, resolved lazily everywhere (access, reminders, display "responsible: X (owner)") | No event hook exists because members cannot be deleted in-app |
| D3 | Catalog item deleted while used | Allowed. List items keep their name, quantity, unit; image link disappears (`SET NULL`) | Lists must stay readable; catalog is a convenience |
| D4 | Store deleted while used | Blocked while any open or locked list uses it (message names the count); done lists keep `store_name` snapshot | Avoids a locked list silently losing its store |
| D5 | Copy semantics | New list: copier is creator and responsible; same store; all items reset to pending (quantity and unit kept); contributors and viewers NOT copied; reminder not copied; name "<name>" + localised " (copy)" | Copy never extends access to others; the carry-over is the "keep everything" path |
| D6 | Carry-over semantics | New open list keeps store, original creator, same responsible and same contributors and viewers; items = unavailable items with quantity and unit, reset to pending; reminder not carried; `source_kind='carry'` | Issue text section 5 |
| D7 | Carry-over question | Shown in the finish dialog only when at least one item is unavailable: "Some items could not be bought. Create a new shopping list from them?" Yes/No, in the same request as finish | One atomic transaction |
| D8 | Who sees the settings page | `access:module,shopping,view` for the page, `edit` for catalog/store writes, `access:admin` for units writes | An admin without the module grant cannot open the page and must grant themselves the module first (same as other modules; flag if undesired) |
| D9 | Lock of an empty list | Refused (422) | Finishing an empty list is meaningless |
| D10 | Unlock after some items were marked | Allowed; marks are kept; finishing requires every item resolved again | Owner said yes to unlock |
| D11 | E-mail sender locale and content | Sender locale; items, quantities, units, store, responsible name; no links, no member e-mails | Recipients are unknown; privacy |
| D12 | Reminder channel | Push only to effective responsible; respects their push preference (default off) | Owner: push, responsible only |
| D13 | Minimal catalog starter | **No starter data in migrations.** Units are seeded (the nine built-ins); catalog and stores start empty on production. Staging seed provides demo catalog. The add dialog shows an empty-state hint when the catalog is empty ("Type a name to add an item, or fill the catalog in Settings") | A starter catalog is explicitly in the owner's suggestion list, not in scope; free text always works |
| D14 | Archive retention | Keep forever; paginated | No retention rule was asked for |
| D15 | Item notes and aisle grouping, prices | Optional short `note` per item only; no prices, aisles | Suggestions list is deferred |

## Common Pitfalls

### Pitfall 1: Existing members lose access to the new module
**What goes wrong:** the enum gains `shopping` but nobody has a `module_permissions` row. **Why:** `AccessGate::level()` defaults to `None`. **Avoid:** backfill from the todo level in the migration, test that every existing user gets a shopping row equal to their todo level. **Warning sign:** the nav item is missing on staging for existing users.

### Pitfall 2: Permissions page returns 422 or throws
**What goes wrong:** the matrix or tab posts four keys; `UpdatePermissionsRequest` requires five. **Avoid:** update `MODULE_KEYS` in the same plan as the enum; update `PermissionSaveTest` and `MembersPageTest`.

### Pitfall 3: `Preset::grants()` mis-assigns levels
Positional zip with `Module::cases()`. A fifth module with a four-element array produces `null` levels or shifted values. Rewrite to keyed arrays and keep the `PresetTest` exact assertions.

### Pitfall 4: The nightly sweeper deletes all shopping images
See facts D. Fix `WishImageStore::sweepOrphans` skip list in the same plan that creates the first image file, and prove it with a test that creates a shopping file older than 60 minutes and runs `wishlist:prune`.

### Pitfall 5: 413 on upload or `no-store` thumbnails
Missing nginx location (default 1m) and missing `NoStoreDynamicResponses` allowlist entry. Both are silent in Pest (nginx is not in the test path); add a test that greps the nginx snippet like `ImportPreviewTest` does and one that asserts the `Cache-Control` of the image route.

### Pitfall 6: Race between lock and add-item, double finish, double carry-over
Each endpoint locks the list row and re-checks the state; the carry-over unique partial index makes a second finish request fail cleanly (catch the unique violation and redirect to the existing carry list). Test with two sequential calls replaying the same request (true parallelism is not available in Pest; assert the second call is a 409 or a harmless redirect and that exactly one carry list exists).

### Pitfall 7: Decimal comma
`0,5` fails `numeric` validation. Normalise in `prepareForValidation`; also strip spaces; reject exponent notation (`1e3`).

### Pitfall 8: Blade escaping in plain-text mail
`{{ }}` writes `&amp;`. Build the text in PHP and pass an `HtmlString` or use `{!! !!}`; test with an item named `Ben & Jerry's <3`.

### Pitfall 9: Reminder never arrives
Push default is OFF and needs a `PushSubscription`. Surface this in the UI hint and in the manual. Also: `remind_at` in family timezone vs UTC (store UTC; display in `config('family.timezone')`; DST test around the last Sunday of March/October).

### Pitfall 10: Coverage tests break the build mid-phase
`MODULES` gains `shopping` -> manual topic required; `SettingsSections` gains `shopping` -> `settings:shopping` required; every new route needs a matrix row; every new `topic="..."` needs a registry entry in three languages and a placement. Land the manual skeleton (all section ids, three languages, parity) in the plan that adds the nav entry, and register each help topic in the plan that places the tip.

### Pitfall 11: Shared lang, help and manual files edited by parallel plans
`lang/{en,sk,pl}.json`, `resources/help/*.json`, `resources/help/topics.json` and `resources/manual/*/shopping.json` are single files. Give each plan its own sub-namespace (`shopping.settings.*`, `shopping.list.*`, `shopping.detail.*`, `shopping.mail.*`, `shopping.reminder.*`, `shopping.unit.*`, `shopping.image.*`) and do NOT run two plans that edit these files in the same wave unless they use isolated worktrees. The plan split below serialises them.

### Pitfall 12: Responsible person who cannot act
A child account (no sign-in) or a view-only member chosen as responsible makes the list impossible to lock or finish. Validate eligibility on every write and apply the effective-responsible fallback.

### Pitfall 13: Name collisions
`App\Wishlist\Giving\ShoppingSummary`, `ShoppingController` and `resources/js/Wishlist/ShoppingSummary.vue` already exist (a gift-buying summary). Do not reuse the word for routes, classes or lang keys: the lang file already has a top-level-ish `shopping` key at line ~1111 under another object (`wishlist.shopping`) `[VERIFIED: lang/en.json]`. A new top-level `shopping` object is fine (different path) but confirm no existing top-level `shopping` key before adding. Use namespace `App\Shopping`, route prefix `/shopping`, route names `shopping.*`.

### Pitfall 14: Quantity and unit change while another device is shopping
Items are immutable while locked; only the state changes. Archive finish snapshots `store_name`.

### Pitfall 15: Bottom navigation overflow
Six items in a single-row grid. Check at 360 px width; if labels clip, shorten via `nav.shopping` or use icon-only labels with `aria-label` (the existing `NavItem` decides; verify in a browser on staging).

## Code Examples

### Keyed preset map `[ASSUMED: shape; replaces the verified positional code]`
```php
public function grants(): array
{
    $edit = AccessLevel::Edit; $view = AccessLevel::View; $none = AccessLevel::None;
    $map = match ($this) {
        self::Parent      => ['todo' => $edit, 'wishlist' => $edit, 'finance' => $edit, 'health' => $none, 'shopping' => $edit],
        self::Child, self::Grandparent => ['todo' => $edit, 'wishlist' => $edit, 'finance' => $none, 'health' => $none, 'shopping' => $edit],
        self::Guest       => ['todo' => $view, 'wishlist' => $none, 'finance' => $none, 'health' => $none, 'shopping' => $view],
    };
    // Same key order as Module::cases() (PresetTest asserts it).
    return array_combine(array_map(fn (Module $m) => $m->value, Module::cases()), array_map(fn (Module $m) => $map[$m->value], Module::cases()));
}
```

### Idempotent item state endpoint
```php
// routes/web/shopping-items.php
Route::put('/shopping/lists/{list}/items/{item}/state', [ItemStateController::class, 'update'])
    ->whereNumber(['list', 'item'])
    ->middleware(['access:module,shopping,edit', 'throttle:240,1,shopping.items.state'])
    ->name('shopping.items.state');
```
```php
// FormRequest: state in pending|bought|unavailable. Service: lock list row, require status=locked, effective responsible, set absolute state.
```

### Carry-over inside finish
```php
DB::transaction(function () use ($listId, $actor, $carry) {
    $list = ShoppingList::query()->whereKey($listId)->lockForUpdate()->firstOrFail();
    abort_unless($this->access->canFinish($actor, $list), 403);
    if ($list->status === ShoppingListStatus::Done) { return $this->existingCarry($list); }   // idempotent
    abort_unless($list->status === ShoppingListStatus::Locked && ! $list->items()->where('state', 'pending')->exists(), 409);
    $list->forceFill(['status' => 'done', 'done_at' => now(), 'done_by' => $actor->id, 'store_name' => $list->store?->name])->save();
    if ($carry && $list->items()->where('state', 'unavailable')->exists()) { /* create list, copy members, copy items as pending, source_kind='carry' */ }
});
```

### Reminder command skeleton (mirrors `EnqueueReminders`)
```php
$lists = ShoppingList::query()->whereNotNull('remind_at')->where('remind_at', '<=', now())
    ->whereNull('reminder_enqueued_at')->where('status', '<>', 'done')
    ->orderBy('remind_at')->limit(200)->lock('for update skip locked')->get();
foreach ($lists as $list) {
    $user = $responsible->effective($list);
    if ($user !== null && $preferences->enabled($user, 'push') && PushSubscription::query()->where('user_id', $user->id)->exists()) {
        $outbox->enqueue('shopping_reminder', $user, 'push', 'shopping_list', $list->id,
            "shopping_reminder:{$list->id}:{$list->remind_at->timestamp}:{$user->id}:push",
            ['list_id' => $list->id, 'remind_at' => $list->remind_at->timestamp]);
    }
    ShoppingList::query()->whereKey($list->id)->update(['reminder_enqueued_at' => now()]);
}
```

## State of the Art

| Old approach | Current approach | Impact |
|--------------|------------------|--------|
| Per-module hand wiring | `Module` enum + `access:module` + coverage tests that fail on omissions | Omissions surface as red tests, so the checklist in section A is exhaustive |
| Per-record rights in controllers | Dedicated access class with `abilities()` shipped in props | Follow it for Shopping |

## Assumptions Log

| # | Claim | Section | Risk if Wrong |
|---|-------|---------|---------------|
| A1 | Mail limits of 10 per hour, 30 per day per user and 5 per day per recipient address | Facts F | Too strict or too loose; owner only fixed max 5 addresses and "a per-user limit". Easy to change in one limiter |
| A2 | E-mail sender may be creator, responsible or contributor, not viewers | Access matrix | Owner may want viewers to share too |
| A3 | Copy resets people (copier becomes creator and responsible; no contributors or viewers) | D5 | Owner may expect rights to be copied |
| A4 | Admin units page requires the admin to hold the module grant | D8 | An admin without the module cannot reach the page; alternative is a separate admin-only route |
| A5 | Polling a locked detail page every 20-30 s is acceptable | Alternatives | Battery use; can be dropped without functional loss |
| A6 | Store delete blocked only for open or locked lists | D4 | Owner might prefer silent unlink |
| A7 | Catalog and image sizes (640/240 and 320/120) | Facts D | Visual only |
| A8 | The wishlist `shopping` lang key at line ~1111 is nested under `wishlist` and a new top-level `shopping` does not collide | Pitfall 13 | Quick grep in the plan's first task removes the doubt |

## Open Questions

1. **Should a member with only module `view` (Guest preset) ever be offered as a contributor?** Recommendation: no; the picker filters by edit and the server re-validates.
2. **Overview card on Home?** Not requested; skipped. A "lists waiting for you" card could follow later.
3. **E-mail reminder in addition to push?** Owner said push only; not built.

## Environment Availability

| Dependency | Required By | Available | Notes |
|------------|------------|-----------|-------|
| PHP 8.3.6 (CLI) | tests | yes | `php -v` |
| PostgreSQL test DB `family_hub_test` | Pest | per `tests/Pest.php` | not probed here |
| Node + Vitest | JS tests | yes (node_modules present) | not run here |
| `@lucide/vue` `ShoppingCart` | nav icon | yes | in `lucide-vue.d.ts` |
| SMTP (Stalwart) | real mail | production/staging only | tests use the `log`/array mailer (`Mail::fake`) |
| Web push keys | reminder | `PushConfig::configured()`; absent locally | `SendOutboxItem` marks `push_not_configured` skipped; tests use the fake sender as `PushDeliveryTest` does |

Missing dependencies with no fallback: none.

## Validation Architecture

### Test Framework
| Property | Value |
|----------|-------|
| Framework | Pest 4.7 (PHP, Postgres `family_hub_test`, `RefreshDatabase`) and Vitest 5 (+ axe-core 4.14) |
| Config file | `tests/Pest.php`, `phpunit.xml`, `vitest` config in repo root |
| Quick run command | `./vendor/bin/pest tests/Feature/Shopping` and `npx vitest run tests/js/shopping` |
| Full suite command | `composer test` and `npm test` (`vitest run`) |

### Phase Requirements to Test Map
| Req | Behavior | Test type | File (new, Wave 0) |
|-----|----------|-----------|--------------------|
| SC3 | Module grants, presets, backfill, permission save with five modules | Pest | `tests/Feature/Access/PresetTest.php` (update), `tests/Feature/Shopping/ModuleBackfillTest.php` |
| SC3 | Every route x seven roles | Pest matrix | `tests/Support/EndpointMatrix/shopping*.php`, `settings-shopping.php` |
| SC3 | Privacy differential: non-member, viewer, contributor, other member, admin cannot read or write a list, items, images, e-mail, reminder (404/403 table) | Pest | `tests/Feature/Shopping/ListPrivacyTest.php` |
| SC1,2 | Items, quantity, unit, free text, decimal comma | Pest | `ItemTest.php`, `QuantityInputTest.php` |
| SC4,5 | State machine: lock/unlock/mark/finish, empty list, locked edits rejected, idempotent replays | Pest | `ListStateMachineTest.php` |
| SC6,7 | Carry-over (once only) and copy semantics | Pest | `CarryOverTest.php`, `CopyTest.php` |
| SC2 | Image pipeline, private route headers, sweeper skip, orphan sweep | Pest | `ShoppingImageTest.php`, `PruneShoppingTest.php`; nginx snippet grep |
| SC8 | Mail: max 5, validation, limiter, plain text with `&`, no member e-mail, queued | Pest | `ShoppingMailTest.php` |
| SC9 | Reminder: enqueue once, dedupe, stale payload skipped, responsible fallback to creator, push preference | Pest | `ShoppingReminderTest.php` |
| D2 | Effective responsible resolution | Pest | `ResponsiblePersonTest.php` |
| UI | Pages, dialog search (accents), finish dialog, mark flow, a11y, pseudo-long, i18n parity | Vitest | `tests/js/shopping/*.test.js`, `tests/js/i18n-phase11.test.js`, `a11y-sweep` additions |
| Docs | Manual and help coverage | Vitest + Pest | `ManualCoverage.test.js`, `HelpCoverage.test.js`, `tests/Feature/Manual` (existing, must stay green) |
| Staging | Seed fills, purge idempotent, audit passes | Pest | `StagingDemoSeedTest` (update counts), `StagingAuditTest` rule |

### Sampling Rate
- **Per task commit:** the Pest file or Vitest file of the task.
- **Per wave merge:** `./vendor/bin/pest tests/Feature/Shopping tests/Feature/Access tests/Feature/Settings` plus `npx vitest run tests/js/shopping tests/js/manual tests/js/help tests/js/shell`.
- **Phase gate:** full `composer test` and `npm test` green, then browser verification on staging with synthetic data.

### Wave 0 Gaps
- [ ] `tests/Support/EndpointMatrix/shopping*.php` files (matrix test fails on any route without a row)
- [ ] Updates to the four-module fixtures listed in Facts A.8
- [ ] `tests/js/shopping/` helpers (fixtures for lists, catalog) and `tests/js/i18n-phase11.test.js`
- [ ] `ShoppingListFactory`, `ShoppingItemFactory`, `ShoppingCatalogItemFactory`, `ShoppingStoreFactory` in `database/factories`

## Security Domain

### Applicable ASVS Categories
| ASVS Category | Applies | Standard Control |
|---------------|---------|-----------------|
| V2 Authentication | no (no new auth path) | existing Fortify |
| V3 Session Management | no | existing |
| V4 Access Control | yes | `access:` middleware + `ShoppingAccess` + uniform 404 + matrix and differential tests |
| V5 Input Validation | yes | FormRequests, enum rules, `email:rfc`, max lengths, numeric bounds |
| V6 Cryptography | no | none stored beyond images |
| V8 Data Protection | yes (low) | private disk, `private, no-cache` images, `Vary: Cookie`, no list data in logs |
| V12 Files | yes | `ImageProcessor` (sniff by bytes, caps, re-encode), no public path |
| V13 API / abuse | yes | named rate limiters on mail and uploads |

### Known Threat Patterns
| Pattern | STRIDE | Standard Mitigation |
|---------|--------|---------------------|
| List id enumeration | Information disclosure | `visibleTo` scope, uniform 404, bigint ids carry no information |
| Forged member ids (responsible/contributor not eligible) | Elevation | server re-validation with eligibility rule on every write |
| Mail relay abuse (spam through the app) | Abuse | max 5 addresses, per-user and per-address limits, plain text only, fixed template, no free-form body |
| Mail header injection | Tampering | `email:rfc`, CR/LF stripped from subject, Symfony Mailer header checks |
| Malicious image upload | Tampering | `ImageProcessor` re-encode, MIME from bytes, size and pixel caps |
| State race (lock vs add, double finish) | Tampering | row lock, status re-check, CHECKs, partial unique index |
| Stored XSS via item or list names | Tampering | Vue text interpolation only (never `v-html`); names length-capped |
| Reminder to a user who lost access | Information disclosure | `stillRelevant` re-checks recipient and module level at send time |

## Files Likely Touched

New: everything under "Recommended Project Structure", 4-5 migrations, factories, `config/shopping.php`, `tests/Feature/Shopping/*`, `tests/js/shopping/*`, `resources/manual/{en,sk,pl}/shopping.json`.

Modified (shared, edit with care):
- `app/Access/Module.php`, `app/Access/Preset.php`
- `app/Support/SettingsSections.php`
- `app/Http/Middleware/NoStoreDynamicResponses.php`
- `app/Wishlist/Catalog/WishImageStore.php` (sweeper skip), `app/Console/Commands/PruneWishlist.php` (call new sweeper)
- `app/Notifications/Outbox/OutboxHandlers.php`
- `routes/console.php` (reminder command, prune)
- `docker/nginx/snippets/laravel-locations.conf`
- `resources/js/Shell/modules.js`, `resources/js/Settings/presets.js`
- `lang/{en,sk,pl}.json`, `lang/{en,sk,pl}/notifications.php`
- `resources/help/{en,sk,pl}.json`, `resources/help/topics.json`
- `resources/manual/{en,sk,pl}/who-sees-what.json`, `getting-started.json` or `overview.json` (mention the module), `admin.json` (units, preset table), and the notifications part of `account-security.json`
- `database/seeders/StagingSeeder.php`, `app/Console/Commands/StagingAudit.php`, `docs/ops/staging-data.md`, `docs/ops/server.md` (images path note)
- Tests: `PresetTest`, `AccessGateTest`, `MembersPageTest`, `PermissionSaveTest`, `EndpointMatrix/settings-members.php`, `moduleAccess.test.js`, `AppShell.test.js`, `StagingDemoSeedTest`, `a11y-sweep.test.js`, `SettingsShellTest` (stays 3 for no-grant member; add a case with the grant)

User documentation (CLAUDE.md rule): the manual topic is `shopping` (`covers: ["module:shopping", "settings:shopping"]`), category `modules`, order about 35 (between tasks 30 and wishlist). Section ids to fix up front: `overview`, `create-list`, `add-items`, `stores-catalog`, `people`, `lock-and-shop`, `finish`, `copy`, `email`, `reminder`, `units` (adminOnly). Help tips (area `shopping`): `shopping.responsible`, `shopping.contributors_viewers`, `shopping.lock`, `shopping.unavailable`, `shopping.carry_over`, `shopping.email`, `shopping.reminder`, `shopping.units`, `shopping.image`. Slovak and Polish are drafts and must be reported for native review in each summary.

## Proposed Plan Split

Small plans; waves group plans that touch disjoint files. Shared lang/help/manual files are serialised across waves (one plan per wave owns each), and every plan's `files_modified` lists the manual files or says "no user-visible change" with a reason.

**Wave 1 (foundation; two plans, disjoint files)**
- **11-01 Module and permissions ripple.** `Module::Shopping`, keyed `Preset::grants()`, migration (constraint + backfill + down), `MODULE_KEYS`, `permissions.module.shopping` and preset hints in 3 languages, update the four-module tests (Facts A.8). No user-visible section yet, but the permission matrix gains a column: reword the manual `admin.json` permissions part in 3 languages.
- **11-02 Schema and models.** Migrations for units (+ nine built-ins), stores, catalog items, lists, members, items; models with casts; factories; `config/shopping.php`; model/constraint tests (CHECKs, partial unique index, cascades, SET NULL).

**Wave 2 (domain; two plans, disjoint files)**
- **11-03 Access and state machine.** `ShoppingAccess`, `ResponsiblePerson`, `ListService`/`ItemService` (create, update, members sync, lock, unlock, state, finish + carry-over, copy, delete), `ListQuery::visibleTo`; Pest tests for access matrix at class level, state machine, idempotency, carry-over once, copy.
- **11-04 Images, catalog, stores, units backend.** `ShoppingImageStore`, image controller + routes, catalog/store/unit services and settings controllers + routes (`settings-shopping.php`), `SettingsSections` block, `NoStoreDynamicResponses`, sweeper fix in `WishImageStore` + prune, nginx block, endpoint matrix rows and tests. Needs a manual skeleton if the settings key appears: land the `shopping.json` skeleton here (all section ids, 3 languages) so `settings:shopping` is covered.

**Wave 3 (HTTP + first UI; three plans)**
- **11-05 List controllers and routes.** Controllers, FormRequests (decimal comma, eligibility rules), routes (`shopping.php`, `shopping-items.php`), Inertia props (`abilities`, catalog), matrix rows, privacy differential tests.
- **11-06 Settings page UI.** `Pages/Settings/Shopping.vue`: catalog (image upload, default unit), stores (logo), units (admin). Lang `shopping.settings.*`, `shopping.image.*`, `shopping.unit.*`; help tips (`shopping.image`, `shopping.units`) with registry in 3 languages; manual sections `stores-catalog`, `units`; Vitest and a11y.
- **11-07 Shell navigation and overview page.** `modules.js` entry, `nav.shopping`/`module.shopping`, `Pages/Shopping/Index.vue` (open/locked lists, archive tab, empty states, create list dialog, copy action); fix `moduleAccess`/`AppShell` tests; check the six-item bottom nav in a browser. Runs after 11-05 (needs props) and must not run in parallel with 11-06 because both edit `lang/*.json`: schedule 11-06 and 11-07 sequentially within the wave, or make 11-07 depend on 11-06.

**Wave 4 (detail UI and features; sequential on shared files)**
- **11-08 List detail page.** `Pages/Shopping/Show.vue`: header (store logo, responsible, people panel), add-item dialog with accent-insensitive catalog search and free text, quantity/unit, item edit/remove, lock/unlock, shopping mode (Bought/Unavailable absolute buttons, optimistic UI with rollback), finish dialog with the carry-over question, optional poll while locked. Lang `shopping.detail.*`, help tips `shopping.responsible`, `shopping.contributors_viewers`, `shopping.lock`, `shopping.unavailable`, `shopping.carry_over`; manual sections `add-items`, `people`, `lock-and-shop`, `finish`, `copy`.
- **11-09 E-mail.** `ShoppingListMailer`, Mailable (text only, `HtmlString`), limiters, controller + route + matrix row + tests; dialog UI; lang `shopping.mail.*`, `mail` strings; help `shopping.email`; manual `email`. Depends on 11-08 (dialog opens from the detail page).
- **11-10 Reminder.** `EnqueueShoppingReminders`, `ShoppingReminderHandler`, registry line, scheduler line, notifications lang, `ReminderField` in the list panel, hint about push preference; reword the notifications page intro and the manual notification text; help `shopping.reminder`; manual `reminder`. Depends on 11-08.

**Wave 5 (closure)**
- **11-11 Staging seed, audit, docs.** `ShoppingSeeder`, seeder purge, `StagingAudit` rules, demo counts, `docs/ops/staging-data.md`, `docs/ops/server.md`, `who-sees-what` and getting-started manual text in 3 languages, final manual and help review.
- **11-12 Cross-cutting verification.** `a11y-sweep` additions, `i18n-phase11.test.js`, pseudo-long layout check, full suites, review fixes, then the staging browser verification checklist (create list, lock, mark, finish with carry-over, copy, e-mail to `log` mailer or Stalwart test address, reminder with a push subscription, image upload on iPhone width, bottom nav).

Parallelism rule of thumb: Wave 1 and Wave 2 pairs are safe in parallel. Waves 3 to 4 serialise any pair that both edits `lang/*.json`, `resources/help/*`, or `resources/manual/*/shopping.json`.

## Sources

### Primary (HIGH confidence, this repository)
- `app/Access/{Module,Preset,AccessGate,PermissionService}.php`, `app/Http/Middleware/{RequireAccess,NoStoreDynamicResponses,HandleInertiaRequests}.php`
- `app/Tasks/{TaskAccess,ReminderRecipients}.php`, `app/Models/Task.php`, `database/migrations/2026_11_14_100000_create_tasks_table.php`
- `app/Wishlist/{WishAccess,WishlistManagerSync,ViewerContext,WishlistMembers}.php`, `app/Wishlist/Catalog/{WishImageStore,ImageProcessor}.php`, `app/Profile/{ProfilePhotoStore,ProfilePhotoAccess}.php`, `app/Http/Controllers/Profile/ProfilePhotoController.php`
- `app/Console/Commands/{EnqueueReminders,DispatchOutbox,PruneWishlist,StagingAudit}.php`, `app/Jobs/SendOutboxItem.php`, `app/Notifications/**`, `routes/console.php`, `app/Auth/InviteService.php`
- `database/seeders/StagingSeeder.php`, `tests/Support/{Roles,EndpointMatrix,SensitiveRoutes}.php`, `tests/js/manual/ManualCoverage.test.js`, `tests/js/help/HelpCoverage.test.js`, `tests/js/i18n-phase10.test.js`
- `docker/nginx/snippets/laravel-locations.conf`, `docker/postgres/init/01-roles.sh`, `docs/ops/server.md`
- `.planning/phases/11-*/11-CONTEXT.md`, `.planning/ISSUES-6-11-OWNER-DECISIONS.md`, `.planning/ROADMAP.md` Phase 11, GitHub issue #8 and its comment (owner answers: unlock yes; only responsible marks items; units yes with measure units; copy yes; reminder to responsible only)

### Secondary / Tertiary
- None. No external documentation was needed; no web or Context7 lookups were made because the phase uses only framework features already used elsewhere in the repo.

## Metadata

**Confidence breakdown:**
- Standard stack: HIGH, nothing new.
- Architecture and module wiring: HIGH, read from code and tests.
- Data model and access matrix: MEDIUM-HIGH, a design that follows the verified conventions; owner-flagged defaults in the Decisions table.
- Mail limits and polling: MEDIUM, `[ASSUMED]` numbers.
- Pitfalls: HIGH for those derived from code (sweeper, nginx, coverage tests, positional presets, backfill).

**Research date:** 2026-11-17
**Valid until:** 30 days, or until Phases 10 to 12 change `Preset`, `SettingsSections` or the manual/help machinery.
