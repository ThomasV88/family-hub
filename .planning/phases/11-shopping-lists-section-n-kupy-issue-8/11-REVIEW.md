---
phase: 11-shopping-lists-section-n-kupy-issue-8
reviewed: 2026-11-17T00:00:00Z
depth: deep
files_reviewed: 82
files_reviewed_list:
  - app/Access/Module.php
  - app/Access/Preset.php
  - app/Audit/AuditEvent.php
  - app/Console/Commands/EnqueueShoppingReminders.php
  - app/Console/Commands/PruneWishlist.php
  - app/Console/Commands/StagingAudit.php
  - app/Http/Controllers/Settings/ShoppingCatalogController.php
  - app/Http/Controllers/Settings/ShoppingSettingsController.php
  - app/Http/Controllers/Settings/ShoppingStoresController.php
  - app/Http/Controllers/Settings/ShoppingUnitsController.php
  - app/Http/Controllers/Shopping/CopyController.php
  - app/Http/Controllers/Shopping/FinishController.php
  - app/Http/Controllers/Shopping/ImageController.php
  - app/Http/Controllers/Shopping/ItemController.php
  - app/Http/Controllers/Shopping/ItemStateController.php
  - app/Http/Controllers/Shopping/ListController.php
  - app/Http/Controllers/Shopping/LockController.php
  - app/Http/Controllers/Shopping/MailController.php
  - app/Http/Middleware/NoStoreDynamicResponses.php
  - app/Http/Requests/Settings/UpdatePermissionsRequest.php
  - app/Http/Requests/Shopping/CatalogItemRequest.php
  - app/Http/Requests/Shopping/CopyListRequest.php
  - app/Http/Requests/Shopping/FinishListRequest.php
  - app/Http/Requests/Shopping/ItemStateRequest.php
  - app/Http/Requests/Shopping/MailListRequest.php
  - app/Http/Requests/Shopping/Quantity.php
  - app/Http/Requests/Shopping/StoreItemsRequest.php
  - app/Http/Requests/Shopping/StoreListRequest.php
  - app/Http/Requests/Shopping/StoreRequest.php
  - app/Http/Requests/Shopping/UnitRequest.php
  - app/Http/Requests/Shopping/UpdateItemRequest.php
  - app/Http/Requests/Shopping/UpdateListRequest.php
  - app/Http/Requests/Shopping/UploadShoppingImageRequest.php
  - app/Mail/ShoppingListMail.php
  - app/Models/ShoppingCatalogItem.php
  - app/Models/ShoppingItem.php
  - app/Models/ShoppingList.php
  - app/Models/ShoppingListMember.php
  - app/Models/ShoppingStore.php
  - app/Models/ShoppingUnit.php
  - app/Notifications/Outbox/OutboxHandlers.php
  - app/Notifications/Outbox/ShoppingReminderHandler.php
  - app/Providers/AppServiceProvider.php
  - app/Shopping/Catalog/CatalogService.php
  - app/Shopping/Catalog/StoreService.php
  - app/Shopping/Catalog/UnitService.php
  - app/Shopping/Images/ShoppingImageStore.php
  - app/Shopping/ItemService.php
  - app/Shopping/ItemState.php
  - app/Shopping/ListMembers.php
  - app/Shopping/ListService.php
  - app/Shopping/ListStateService.php
  - app/Shopping/ListStatus.php
  - app/Shopping/Mail/ShoppingListMailer.php
  - app/Shopping/Mail/ShoppingListText.php
  - app/Shopping/MemberRole.php
  - app/Shopping/NameKey.php
  - app/Shopping/Queries/ListPayload.php
  - app/Shopping/Queries/ListQuery.php
  - app/Shopping/Queries/SettingsPayload.php
  - app/Shopping/ResponsiblePerson.php
  - app/Shopping/ShoppingAccess.php
  - app/Shopping/SourceKind.php
  - app/Support/SettingsSections.php
  - app/Wishlist/Catalog/WishImageStore.php
  - config/shopping.php
  - database/factories/ShoppingCatalogItemFactory.php
  - database/factories/ShoppingItemFactory.php
  - database/factories/ShoppingListFactory.php
  - database/factories/ShoppingStoreFactory.php
  - database/factories/ShoppingUnitFactory.php
  - database/migrations/2026_11_28_100000_add_shopping_module_permissions.php
  - database/migrations/2026_11_28_110000_create_shopping_catalog_tables.php
  - database/migrations/2026_11_28_120000_create_shopping_list_tables.php
  - database/seeders/Staging/ShoppingSeeder.php
  - database/seeders/StagingSeeder.php
  - docker/nginx/snippets/laravel-locations.conf
  - routes/console.php
  - routes/web/settings-shopping.php
  - routes/web/shopping-images.php
  - routes/web/shopping-items.php
  - routes/web/shopping-mail.php
  - routes/web/shopping.php
findings:
  critical: 0
  warning: 5
  info: 8
  total: 13
status: issues_found
---

# Phase 11: Code Review Report

**Reviewed:** 2026-11-17
**Depth:** deep
**Files Reviewed:** 82 (backend only; resources/js and lang/ were not in scope)

## Summary

The backend is carefully built. I tried to break the following and could not:

- **Authorisation.** Every list mutation goes through `ListQuery::lockForActor`, which gives a uniform 404 for hidden lists, takes the row lock and re-checks the status under it. The `can*` and `may*` checks match what the services enforce. Items are always scoped by `list_id`, so there is no IDOR. Admin has no bypass. Mass-assignment surfaces are minimal.
- **State machine.** Targets are absolute, so retries converge. The carry-over is protected by the partial unique index plus a savepoint. The CHECK constraints match the service rules.
- **Migration.** The backfill copies the todo level only, never raises it, skips users without a todo row and is `ON CONFLICT DO NOTHING`. The test also runs `up()` twice.
- **Images.** The image path is built only from a ULID and an integer key. The images are re-encoded, the routes are private, the nginx limits match, and the wish and profile sweepers skip the shopping prefix.
- **Mail.** The mail text is plain text, the subject and body strip control characters, and the audit row holds only a count. The test mailer is `array`.

There is no critical issue. The five warnings are a real UX/logic gap (hand-over), a dead-end state, an input-validation gap in the mail path, an audit blind spot, and a misleading reminder flag. The infos are hardening and performance.

## Warnings

### WR-01: A responsible person who hands the list over loses access and is redirected to a 404

**File:** `app/Shopping/ListService.php:72-100`, `app/Http/Controllers/Shopping/ListController.php:56`, `app/Shopping/ShoppingAccess.php:30-47`
**Issue:** Take a list where B is the responsible person and A is the creator. If B changes the responsible person to C, `updateSettings` removes C from the member rows. B is not a member, is no longer `responsible_id` and is not the creator, so `role()` returns null for B. `ListController::update` then returns `back(303)`, which redirects B to `/shopping/lists/{id}`, and B gets a 404 on a list they were just using. The same happens if a creator hands the list to someone and keeps no role, but the creator always stays. The previous responsible person is dropped silently, with no viewer or contributor membership and no hint in the toast. Nothing in the CONTEXT says the previous responsible person should lose access.
**Fix:** When the responsible person changes and the previous effective responsible is not the creator, add them as a contributor (if they have edit) or viewer in `updateSettings`. Or, if losing access is intended, redirect to `shopping.index` in the controller when the actor can no longer view the list:
```php
if (! $access->canView($request->user(), $updated->fresh())) {
    return redirect()->route('shopping.index', [], 303)->with('toast', [...]);
}
```

### WR-02: A list can become permanently stuck when the creator loses Shopping edit

**File:** `app/Shopping/ShoppingAccess.php:52-55, 99-104`, `app/Shopping/ResponsiblePerson.php:24-33`
**Issue:** `effective()` falls back to the creator without checking that the creator is eligible. If the stored responsible person is ineligible (grant removed or sign-in disabled) and the creator also loses Shopping edit, `isResponsible()` is false for everyone. `lock`, `unlock`, `mark` and `finish` all need `isResponsible`, and `mayManage` needs `hasEdit`. In a Locked list, no one can unlock it, finish it or change the responsible person. Delete is Open-only, so the list cannot be removed either. An administrator has no bypass by design, so the only fix is a database edit or restoring a grant for the creator. Production has two users, so this is realistic when a grant is reduced.
**Fix:** In `effective()`, when neither the stored nor the creator is eligible, keep the current result but let `canChangeResponsible` also allow any other member with Shopping edit to take over (for example the `CONTRIBUTOR` role). Alternatively, show a "list has no active responsible person" state in the payload, and allow an administrator who holds Shopping edit to reassign it.

### WR-03: Mail address validation lets through addresses that Symfony rejects at send time, and the per-address cap is easy to bypass

**File:** `app/Http/Requests/Shopping/MailListRequest.php:36`, `app/Shopping/Mail/ShoppingListMailer.php:45-46`
**Issue:**
1. `email:rfc` accepts quoted local parts that contain folding whitespace (CRLF followed by a space). Symfony's `Address` constructor refuses any control character (`vendor/symfony/mime/Address.php`, "Email address contains control characters"). The failure happens inside the queued job. The user gets the success toast, `RateLimiter::hit` has already been spent, and a failed job remains in `failed_jobs` with the serialized mailable, which holds the recipient address in plain text. This conflicts with the "audit without addresses" intent. I have not run the CRLF payload. The reasoning comes from reading the Egulias and Symfony code, so please verify it with a test.
2. `a@localhost` and `a@b` (no dot) pass `email:rfc`, so a user can send mail to internal hostnames.
3. The per-recipient cap is keyed on the exact lower-cased string, so `x+1@d.com`, `x+2@d.com` and `x@d.com(comment)` each get their own bucket. The per-user limits (10 requests per hour, 30 per day, 5 addresses each) still bound the damage to 150 mails per user per day.
**Fix:** Tighten the rule and key the cap on a normalised address:
```php
'addresses.*' => ['required', 'string', 'max:254', 'email:rfc,strict', 'regex:/^[^\x00-\x1F\x7F"()\s]+$/', 'regex:/@[^@]+\.[^@]+$/'],
```
Strip `+tag` and Gmail dots in the key if that matters to the owner. Send with `Mail::to($address)->queue()` inside a try/catch around construction of the `Address`, so a bad address is refused before the limiter is touched. Set `failed_jobs` pruning (`queue:prune-failed`) so addresses do not stay forever.

### WR-04: `staging:audit` does not flag shopping rows whose creator was deleted

**File:** `app/Console/Commands/StagingAudit.php:75-78`
**Issue:** The audit comment for finance says "Null stays flagged": a null creator can only be a real user who was deleted. The new shopping rules do the opposite: `created_by is not null and created_by not in {synthetic}`. A catalog item, store or item created by a real user who was later deleted (`nullOnDelete`) has `created_by = NULL` and passes the audit even though it is real data. The comment claims null only occurs for built-in units. That holds for units (built-ins have a `key`), but not for catalog items, stores or items. This is the safety net for "staging never holds real data".
**Fix:** Flag null for catalog items, stores and items, and make the unit rule use the `key` column:
```php
['shopping_catalog_items', "(created_by is null or created_by not in {$synthetic})"],
['shopping_stores', "(created_by is null or created_by not in {$synthetic})"],
['shopping_units', "(key is null and (created_by is null or created_by not in {$synthetic}))"],
['shopping_items', "(created_by is null or created_by not in {$synthetic}) or (state_changed_by is not null and state_changed_by not in {$synthetic})"],
```
The seeder purge removes synthetic-created rows before the user rows go, so a reseed does not create nulls (the same argument the finance rules already make).

### WR-05: `reminderSent` is true and the reminder is consumed even when nothing was sent

**File:** `app/Console/Commands/EnqueueShoppingReminders.php:44-49`, `app/Shopping/Queries/ListPayload.php` (`reminderSent`)
**Issue:** The reminder is marked consumed (`reminder_enqueued_at = now()`) whether or not a push was queued. If the effective responsible person has push disabled or no subscription at that minute, no outbox row is written, but the UI shows `reminderSent = true`. If they enable push five minutes later, they never get it. The scheduler can also fall behind. Then a reminder that is days old is still sent, because there is no lower bound on `remind_at`, and `stillRelevant` does not check age either.
**Fix:** Store the outcome, for example set `reminder_enqueued_at` only when an outbox row was written and add a nullable `reminder_skipped_at` for the other case, so the UI can say "no push at that time". Skip reminders older than a window (for example more than 6 hours late) and mark them consumed.

## Info

### IN-01: Archive tab does N+1 queries
**File:** `app/Shopping/Queries/ListPayload.php:58-82`, `app/Shopping/ShoppingAccess.php:30-47`
**Issue:** The archive query only does `with('store')`. For each of up to 20 rows, `canCopy` calls `role()`, which calls `effective()`, and this runs `User::find` for the responsible person, `findOrFail` for the creator, plus one `ShoppingListMember` query. That is about 3 to 4 queries per row, and `eligible()` adds one more when the grant cache is cold. The open tab loads `responsible` and `creator` but still does the member query per list.
**Fix:** Add `->with(['store', 'responsible', 'creator'])` to the archive query and preload the actor's membership rows in one query keyed by `list_id` before the map.

### IN-02: Concurrent `save_to_catalog` with the same name returns a 500
**File:** `app/Shopping/ItemService.php:135-146`
**Issue:** Two requests that add the same new name with `save_to_catalog` can both pass the `name_key` lookup. The second insert raises `UniqueConstraintViolationException` inside `DB::transaction`, which becomes a 500 (and the list row lock only serialises requests on one list, not across lists). `CatalogService::save` handles this exception, but `catalogFor` does not.
**Fix:** Use `ShoppingCatalogItem::query()->firstOrCreate(...)` with a catch, or `insertOrIgnore` followed by a re-read.

### IN-03: Merged quantity is capped silently and the merged note is dropped
**File:** `app/Shopping/ItemService.php:51-52`
**Issue:** Adding 500 + 600 of the same catalog item stores 999.99 with a normal "quantity updated" toast. A new note in the second add is discarded when the lines merge. Money is not involved, but the result is surprising.
**Fix:** Reject with `shopping.error.quantity_invalid` when the sum exceeds the max, and append a non-empty new note to the existing one (within 200 characters).

### IN-04: `NameKey` collapses names that have no Latin letters
**File:** `app/Shopping/NameKey.php:14`
**Issue:** `Str::ascii` turns an emoji-only or non-Latin-only name (for example Cyrillic or CJK) into an empty or `?`-filled string, so two different names share the same `name_key`. The second one is refused as a "duplicate". The family is SK, PL and EN, so the real exposure is emoji names.
**Fix:** Fall back to `mb_strtolower($collapsed)` when the ASCII result is empty or loses characters.

### IN-05: Unit and store deletion changes finished lists
**File:** `app/Shopping/Catalog/UnitService.php:30-34`, `database/migrations/2026_11_28_120000_create_shopping_list_tables.php` (`unit_id` is `nullOnDelete`)
**Issue:** Deleting a custom unit silently clears the unit on every item of every list, including archived ones, so "2 plechovka" becomes "2". `usedOn` is only shown in the settings page. Stores keep a name snapshot only for finished lists. Open and locked lists lose the store.
**Fix:** Refuse deletion while `usedOn > 0` (server side), or snapshot the unit label on finish like `store_name`.

### IN-06: Hardening gaps with unbounded input sizes
**File:** `app/Http/Requests/Shopping/UpdateListRequest.php:36-39`, `app/Shopping/ItemService.php:21`
**Issue:** `contributor_ids` and `viewer_ids` have no `max`, so a request with thousands of ids runs one `gate->allows` per id (the cache fills one query per user). Items per list are unbounded: 120 requests per minute with 50 items each. `show` loads every item. A mail has no item cap either.
**Fix:** Add `max:50` to the id arrays, and a per-list item cap (for example 500) checked in `add()`.

### IN-07: Rate limits count malformed requests, and the image upload can leave orphan files
**File:** `routes/web/shopping-mail.php`, `app/Shopping/Images/ShoppingImageStore.php:93-109, 126`
**Issue:** The `throttle` middleware runs before validation, so five typos in an address field burn half of the hourly mail quota. In `put`, if the row is deleted between the file writes and `swap`, `firstOrFail` throws and the two new files stay until the 60-minute sweeper. That is harmless, but not cleaned up at the point of failure.
**Fix:** Delete `$full` and `$thumb` in a catch around `swap` in `put`. Optionally move the mail limiter hit into the mailer, after validation.

### IN-08: Migrations and misc hygiene
**File:** `database/migrations/2026_11_28_100000_add_shopping_module_permissions.php:10,20`, `database/migrations/2026_11_28_120000_create_shopping_list_tables.php`, `app/Shopping/Queries/ListPayload.php:110`
**Issue:**
- `DROP CONSTRAINT module_permissions_module_check` has no `IF EXISTS`. It works on the known schema (and the test runs `up()` twice), but a manually renamed constraint would abort the deploy.
- No index on `shopping_items.catalog_item_id`, `shopping_items.unit_id` or `shopping_lists.store_id`. The `SET NULL` on deleting a catalog item, store or unit scans the child table. This is small at family scale.
- `show` selects `users.email` for members and does not use it (`person()` only checks `email === null`). It is not exposed, but selecting it is unnecessary.
- Reminder timestamps in a DST gap (for example 02:30 on the last Sunday of March) pass `date_format` in UTC but shift one hour in the family zone.

**Fix:** Use `DROP CONSTRAINT IF EXISTS`, add the three indexes, select `id,name` and a computed `email is null` flag, and reject nonexistent local times in `remindUtc()`.

---

_Reviewed: 2026-11-17_
_Reviewer: Claude (gsd-code-reviewer)_
_Depth: deep_
