# Phase 10: Profile settings and child accounts - Research

**Researched:** 2026-11-17
**Domain:** Laravel 13 / Fortify / Inertia 3 + Vue 3 / PostgreSQL: nullable identity, sign-in lockout on every route, private image pipeline reuse, avatar rollout
**Confidence:** HIGH (everything below is read from this repository unless tagged otherwise; no new external library is needed)

<user_constraints>
## User Constraints (from CONTEXT.md)

### Locked Decisions
- Child = a `users` row without e-mail (e-mail nullable, unique when present) plus `sign_in_disabled`. It can never authenticate (no password, reset, passkey, invitation) and never receives mail. Existing Child permission preset and `wishlist_managers` give a parent the management of the child's wish list.
- Admin creates the child directly (Settings > Members). Later conversion to a normal member: add e-mail and invite.
- A member edits own name and photo; a parent or administrator edits a child's name and photo. E-mail is shown, not editable.
- Profile photo: private storage, sanitised re-encode like `app/Wishlist/Catalog/ImageProcessor.php`, no metadata, never publicly reachable; avatars (`resources/js/Components/ui/UiAvatar.vue` and its users) show it everywhere.
- Account menu: new item "Profile settings" above "Settings".

Rules that apply to every plan:
- Every commit that resolves part of an issue carries `Fixes #N` (one trailer per issue); commits touch explicit paths only (never `git add -A`), no attribution lines, never touch `.planning/config.json`.
- CLAUDE.md "User documentation (mandatory)": manual topic and help tips in en, sk, pl (sk and pl are drafts for native review) and the coverage tests.
- Privacy: permissions enforced server-side, tests for hidden data; no secrets printed.
- Release: develop (staging) first with browser verification on synthetic data, then PR to main. The owner approves production.

### Claude's Discretion
Everything not listed above, following existing codebase conventions.

### Deferred Ideas (OUT OF SCOPE)
See "Suggestions recorded for the owner" in `.planning/ISSUES-6-11-OWNER-DECISIONS.md` (language/theme in profile, sign-out-other-devices shortcut, child birthday as occasion, age display, one-click convert, delete/archive child). UI-SPEC also puts delete or archive of a child out of scope.
</user_constraints>

<phase_requirements>
## Phase Requirements

| ID | Description | Research Support |
|----|-------------|------------------|
| GH-7 (SC1-3) | Account-menu item, own name edit, e-mail read-only, photo replaces initials everywhere | Sections "Account menu", "Photo pipeline", "Avatar rollout" |
| GH-6 (SC4) | Admin creates child directly; child cannot sign in by any route | Section "Sign-in lockout (every route)" |
| GH-6 (SC5) | Child appears as Wishlist owner, parent/admin manage wishes on its behalf | Section "Wishlist" (works today once `wishlist_managers` rows exist; admin has NO bypass) |
| GH-6 (SC6) | Data model decision | Locked: nullable e-mail + flag. Section "Data model" |
| GH-6 (SC7) | Privacy tests: child never authenticates, never gets mail, photos not public | Section "Validation Architecture" |
</phase_requirements>

## Summary

The phase needs no new Composer or npm package. Everything is built from code that already exists: `ImageProcessor` (re-encode, EXIF strip, size/pixel caps, decode lock), the private-route pattern of `WishImageController`, `WishlistManager` + `ViewerContext` (recipient-side), `PermissionService`/`Preset::Child`, `InviteService`, `AuditLogger`, the `ui/*` components and the lang/manual/help machinery. The hard parts are cross-cutting, not new tech: (1) making `users.email` nullable without breaking the ~15 places that read it, (2) blocking sign-in on every one of the six code paths that can establish a session, (3) putting profile photos on the one Docker volume that is persisted and backed up (a very easy-to-miss infrastructure trap), (4) threading a photo URL through eleven avatar call sites whose payloads do not all carry a user id, and (5) keeping `staging:audit` / `StagingSeeder` correct once a "synthetic" user can have a NULL e-mail.

Design recommendation (all within the locked decisions): one invariant at the database (`CHECK (email IS NOT NULL OR sign_in_disabled)`), one predicate in code (`User::canSignIn()` = `! sign_in_disabled && email !== null`), enforced at every login site plus a defence-in-depth web middleware that kills any session/remember-cookie of a user who cannot sign in. Photos live under the existing persisted prefix `wish-images/profile-photos/{userId}/` (no compose/backup change), are served by one authenticated controller route with `private, no-cache` + ETag (same as wish images), and are addressed by a version number in the query string. Avatars get the photo through a lazily shared Inertia prop `avatars` (`{userId: version}`) and a new `userId` prop on `UiAvatar`, so no existing payload has to change except two health payloads. Conversion of a child to a member must reuse the same `users` row (invitation gets a nullable `user_id`), otherwise wishes, task assignments and `wishlist_managers` rows are orphaned.

**Primary recommendation:** Build in this order: data model + `canSignIn()` -> sign-in lockout with a route-enumerating differential test -> photo backend -> profile page and menu -> child creation / parent editing -> conversion -> avatar rollout -> docs/staging. Treat the "no sign-in on any route" test as the phase gate.

## Architectural Responsibility Map

| Capability | Primary Tier | Secondary Tier | Rationale |
|------------|-------------|----------------|-----------|
| Block sign-in of children | API / Backend (Fortify callbacks, controllers, middleware) | Database (CHECK constraint) | Server-side only; UI hiding is irrelevant |
| Who may edit whose name/photo | API / Backend (`ProfileAccess` rule + FormRequest) | Browser (hides buttons) | Must be enforced server-side, 403/404 on refusal |
| Photo re-encode and storage | API / Backend (`ImageProcessor`, private disk) | Database (path + version columns) | Untrusted bytes; never trust client MIME |
| Photo delivery | API / Backend (authenticated route, ETag) | Browser cache (revalidate only) | Private file; no nginx static path, no SW caching |
| Avatar rendering and initial fallback | Browser / Client (`UiAvatar`) | API (shared `avatars` prop) | Component decides photo vs initial |
| Child creation and parents link | API / Backend (action + `WishlistManager` sync) | Browser (dialog) | Transactional with permission grants and audit row |
| Conversion to member | API / Backend (`InviteService` accept branch) | Browser (Members panel section) | Single-use token + row lock logic already lives there |
| No mail / no push to children | API / Backend (`User::routeNotificationForMail`, recipient queries) | — | Queued jobs and schedulers run server-side |
| Reminder/occasion exclusion | API / Backend (`OccasionGivers`, `ReminderRecipients`) | — | Long-lived scheduler processes |

## Standard Stack

No new packages. Existing, verified in `composer.json` / `package.json`:

### Core
| Library | Version | Purpose | Why Standard |
|---------|---------|---------|--------------|
| laravel/framework | ^13.17 | Routing, Eloquent, Storage, validation | project stack [VERIFIED: composer.json] |
| laravel/fortify | ^1.42 | Login, reset, 2FA (`authenticateUsing`, `RedirectIfSecondFactor`) | project stack [VERIFIED: composer.json] |
| inertiajs/inertia-laravel + @inertiajs/vue3 | ^3 / ^3.11 | Pages, shared props, `useForm` multipart | project stack [VERIFIED: composer.json, package.json] |
| PHP GD (`ImageProcessor`) | ext | Re-encode to JPEG without metadata | already used for wish images [VERIFIED: app/Wishlist/Catalog/ImageProcessor.php] |
| Pest 4 / Vitest 5 / @vue/test-utils / axe-core | per lockfiles | PHP and JS tests, a11y | project test stack [VERIFIED: composer.json, package.json] |

### Alternatives Considered
| Instead of | Could Use | Tradeoff |
|------------|-----------|----------|
| Nullable e-mail + flag | Separate `people` table | Rejected by owner; would also duplicate Wishlist/Todo/Health FKs that point at `users` |
| New persisted Docker volume for photos | Reuse `wish-images` volume prefix | Reuse needs zero compose/backup/check-compose change; a new volume needs edits in compose.yaml, compose.server.yaml, Dockerfile mkdir, backup.sh, restore-drill.sh, check-compose.sh |
| Per-payload `photo` fields | Shared `avatars` prop + `userId` on `UiAvatar` | Shared prop touches no controller payload; per-payload touches ~10 presenters |

**Installation:** none.

## Package Legitimacy Audit

No external packages are installed by this phase. Nothing to audit. Packages removed: none. Packages flagged: none.

## Architecture Patterns

### System Architecture Diagram

```
Browser
  |  POST /profile (name)            POST /profile/photo (multipart)        GET /people/{id}/photo?v=N
  v                                   v                                       v
[web middleware: NoStore, SetLocale, BlockDisabledSignIn(new), HandleInertia, EnsureRouteDeclaresAccess, access:authenticated]
  |                                   |                                       |
  v                                   v                                       v
ProfileController            ProfilePhotoController                  ProfilePhotoController@show
  |  ProfileAccess::canEdit(actor,target)  (self | admin & child | wishlist-manager & child)
  v                                   |                                       | ProfilePhotoAccess::canView(viewer,subject)
users.name                    ImageProcessor::process(bytes, 5MB, 512, 192)     v
                                      v                                   Storage local:
                              ProfilePhotoStore::put  -----------------> wish-images/profile-photos/{id}/{ulid}-{full|thumb}.jpg
                                      v
                              users.photo_path/thumb_path/photo_version (+ audit row if actor != target)

Admin Settings > Members
  "Add child" -> CreateChild action (1 transaction):
       users row (email NULL, sign_in_disabled, random unusable password, password_set=false)
       + module_permissions from Preset::Child + wishlist_managers rows + audit ChildCreated
  "Make a normal member" -> InviteService::create(..., childUserId) -> invitations.user_id
       accept(): fills the SAME users row, clears sign_in_disabled, sets password/locale, keeps grants and manager rows

Every session-establishing path ---> User::canSignIn() must be true
   Fortify password (authenticateUsing)   Fortify 2FA challenge (login.id only set after password)
   Passkey challenge (pending())          Google callback (resolve)
   Invite accept (Auth::login)            Password reset (reset + notification)
   remember cookie / existing session  -> BlockDisabledSignIn middleware logs out
```

### Recommended Project Structure
```
app/
├── Actions/Profile/            # UpdateProfile, CreateChild (Fortify-style single-purpose actions; app/Actions today holds only Fortify/)
├── Auth/                       # InviteService (accept branch for child conversion)
├── Http/Controllers/Profile/   # ProfileController, ProfilePhotoController
├── Http/Controllers/Settings/  # ChildrenController (store), existing MembersController payload
├── Http/Middleware/            # BlockDisabledSignIn
├── Http/Requests/Profile/      # UpdateProfileRequest, UploadProfilePhotoRequest, StoreChildRequest
├── Profile/                    # ProfileAccess, ProfilePhotoAccess, ProfilePhotoStore, AvatarVersions
├── Wishlist/                   # WishlistManagerSync (extracted from WishlistManagersController::sync)
database/migrations/            # 2026_11_26_100000_add_child_and_photo_columns_to_users.php (+ invitations.user_id)
resources/js/Pages/Profile/Edit.vue
resources/js/Components/Profile/ProfilePhotoEditor.vue, ChildCreateDialog.vue
routes/web/profile.php
resources/manual/{en,sk,pl}/profile.json (new topic), admin.json + wishlist.json edits
```

### Pattern 1: One predicate, many call sites
**What:** `User::canSignIn(): bool { return ! $this->sign_in_disabled && $this->email !== null; }` and `User::routeNotificationForMail(): ?string { return $this->canSignIn() ? $this->email : null; }`. Laravel's `MailChannel::send` returns silently when the route is empty [VERIFIED: vendor/laravel/framework/.../MailChannel.php line 58], so a stray `notify()` cannot mail a child; still exclude children explicitly from recipient queries so outbox rows are not created and counted as "sent".

### Pattern 2: Private image route (copy of wish images)
`WishImageController::show` is the template: `Cache-Control: private, no-cache`, `ETag: "sha1(path)"`, `X-Content-Type-Options: nosniff`, `Vary: Cookie`, `$response->isNotModified($request)`, uniform 404 for refusal/missing. The route name MUST be added to the allowlist in `NoStoreDynamicResponses` (otherwise it is rewritten to `no-store, private` and every avatar refetches the bytes on every page); the error responses must stay `no-store`. The UI-SPEC wording `private, max-age=0, must-revalidate` is equivalent to the existing `private, no-cache`; use the existing header set so `CacheHeadersTest` style tests can be cloned. [VERIFIED: app/Http/Controllers/Wishlist/Owner/WishImageController.php, app/Http/Middleware/NoStoreDynamicResponses.php]

### Pattern 3: Uploading with Inertia
`useForm({ photo: null }).post(url, { forceFormData: true })` (Inertia 3 detects `File` automatically). Validation errors come back as translation keys (`profile.photo.err_type`...) like `wishlist.image.err_*`; the front-end translates them with `t()` (see `UploadWishImageRequest` messages). 303 redirect back with `toast` flash key.

### Anti-Patterns to Avoid
- **Checking `sign_in_disabled` only in `authenticateUsing`:** passkey challenge, Google, invite accept, reset and remember-cookie are separate session paths (see table below).
- **Storing photos on a new `local`-disk directory outside `wish-images/`:** the compose files mount a persistent volume only at `storage/app/private/wish-images`; anything else on the `local` disk is lost on every redeploy and is not in the restic backup (see Pitfall 1).
- **Converting a child by creating a second `users` row:** loses wishes, task links, `wishlist_managers`, permission rows and history. Reuse the row.
- **Giving admins an implicit manager role:** `WishAccess` explicitly has "no admin bypass"; `ViewerContext` is recipient-side for owner and designated manager only [VERIFIED: app/Wishlist/WishAccess.php, ViewerContext.php].

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| Image sanitising | New GD pipeline | `ImageProcessor::process($bytes, 5*1024*1024, fullPx: 512, thumbPx: 192)` | Type sniffing from bytes, pixel cap (13 MP), EXIF orientation applied then stripped, per-container decode lock, white flatten. Signature already accepts `$fullPx`, `$thumbPx`, `$quality`, `$decodeBudget` [VERIFIED] |
| Private file serving | `Storage::url`, `public` disk, `storage:link` | Controller streaming from disk `local` (`serve => false`) | Disk config has no serve route by design [VERIFIED: config/filesystems.php] |
| Permission grants for a new child | Direct `ModulePermission::create` loops | Same loop as `InviteService::accept` (`$preset->grants()` + `updateOrCreate`), plus audit via `PermissionService`-style rows | Keeps grants identical to invited members |
| Wishlist manager sync | Copy of `WishlistManagersController::sync` | Extract to `App\Wishlist\WishlistManagerSync` and call from both controllers | Contains the user-row lock that serialises with reserve/contribute [VERIFIED] |
| Session kill | Custom `sessions` delete | `SessionRevoker::revokeAll($user)` | Also rotates remember token |
| Single-use link, rate limits, row locks | Anything new | `InviteService` (`lockForUpdate`, `pg_advisory_xact_lock`) | Races already handled |
| Toasts, dialogs, menus | New components | `useToast`, `UiDialog`, `UiConfirmDialog`, `UiMenu` | UI-SPEC says reuse |

**Key insight:** every risky primitive (image decode, token handling, session revocation, row locking) already exists and is tested; the phase's risk is wiring, not invention.

## Codebase Facts the Planner Needs

### A. Users table today
- `users`: `id, name, email string unique NOT NULL, email_verified_at, password NOT NULL, remember_token, timestamps` + `is_admin bool, locale(5), google_id unique` + 2FA columns + `password_set bool default true` + `birth_date`. [VERIFIED: migrations]
- Two unique indexes on e-mail: column `users_email_unique` and functional `users_email_lower_unique ON users (lower(email))`. In PostgreSQL NULLs are distinct in unique indexes (default `NULLS DISTINCT`), so any number of children with NULL e-mail coexist with both indexes unchanged. `ALTER COLUMN email DROP NOT NULL` is metadata-only (no table rewrite, no lock beyond a brief ACCESS EXCLUSIVE). [CITED: postgresql.org/docs/current/indexes-unique.html]
- `User` uses attribute-based `#[Fillable(['name','email','password'])]` and `#[Hidden([...])]`; new columns (`photo_path`, `photo_thumb_path`, `photo_version`, `sign_in_disabled`) must be added to `#[Hidden]` for the path columns and are written with `forceFill`.
- `Preset` is not stored on the user; "child" in the data model is identified by `sign_in_disabled` (+ null e-mail), not by the grants.

### B. Every place that reads `users.email` (audit for nullability)
| Place | Behaviour with NULL | Action |
|-------|---------------------|--------|
| `FortifyServiceProvider::authenticateUsing` (`where('email', RequestInput::email())`) | `RequestInput::email` returns `''`; `= ''` never matches NULL | Add `canSignIn()` check; keep dummy-hash comparison for timing; add `CHECK (email <> '')` so '' can never be stored |
| `FortifyServiceProvider::resetPasswordView` | `where('email', '')` no match -> `valid=false` | none |
| `RecordAuthEvents::findByEmail` | `lower(email) = ''` no match | none |
| `User::sendPasswordResetNotification` -> `ResetPasswordNotification` uses `getEmailForPasswordReset()` | null in URL | Early return when `! canSignIn()` |
| `ResetUserPassword::reset` | reached only with a valid token | Throw if `! canSignIn()` (defence in depth) |
| `InviteService::create/accept`, `GoogleLoginService::resolve` | compare lowercase e-mails; NULL never equals | Accept branch for child conversion (below) |
| `MembersController` payload (`'email' => $member->email`) | null in props | UI shows "Managed by ..." instead |
| `HandleInertiaRequests` `auth.user.email`, `AccountMenu` sublabel | only for signed-in user (never a child) | none |
| `NotificationSettingsController` `email` | own user only | none |
| `MemberRow.vue` / `MemberPanel.vue` render `member.email` | blank | conditional render |
| `StagingAudit` rule `email not like '%@example.test'` and `$synthetic`/`$real` sub-selects | `NULL NOT LIKE ...` is NULL, so a real child (NULL e-mail) is NOT counted as non-synthetic | MUST patch (see Staging) |
| `StagingSeeder` purge `User::where('email','like','%@example.test')` | would never delete synthetic children -> duplicates on every staging deploy | Patch |
| `CreateAdmin` validation `unique:users,email` + `required` | admin always has e-mail | none |
| `UserFactory` always sets e-mail | tests need a `child()` state | Add state: `email => null, sign_in_disabled => true, password_set => false, email_verified_at => null` |
| Tests asserting `User::where('email','not like','%@example.test')->count() === 0` (`StagingSeedTest`) | NULL excluded | Update when a synthetic child is seeded |

Other users of `User` that iterate all users: `SecondFactorPolicy::pendingCount()` (`User::query()->get()` counts anyone with Finance/Health access lacking a factor) -> children must be excluded in `appliesTo()` (`return false` when `! canSignIn()`), otherwise a child with a Health grant makes "members still to enrol" never reach zero. `MembersController` computes `security.needs` through the same policy.

### C. Sign-in lockout: every route that can establish a session
| # | Path | File | Current gate | Required change |
|---|------|------|--------------|-----------------|
| 1 | Password login (and TOTP/passkey 2nd step precondition) | `FortifyServiceProvider::authenticateUsing` (used by both `AttemptToAuthenticate` and `RedirectIfSecondFactor::validateCredentials`) | user + `Hash::check` | return null when `! canSignIn()` (still run the hash check against the dummy hash) |
| 2 | Two-factor challenge (code/recovery) | Fortify `two-factor.login.store`, session `login.id` | `login.id` is only written by `RedirectIfSecondFactor` after a correct password | No change if #1 holds; add a test that a forged `login.id` of a child cannot log in (the guard `GuardTwoFactorRoutes::stripUnusableInput` and Fortify's `TwoFactorLoginRequest::challengedUser` both do `User::find`) -> add `canSignIn()` check in `FortifyServiceProvider::twoFactorChallengeView` and a small request guard in `GuardTwoFactorRoutes` for `two-factor.login.store` (abort with the same refusal as an expired challenge) |
| 3 | Passkey second step | `ChallengePasskeyController::pending()` -> `Auth::guard('web')->login($user)` | needs `login.id` and `passkeys()->exists()` | add `canSignIn()` to `pending()` |
| 4 | Google callback | `GoogleController::callback` -> `Auth::login`, `GoogleLoginService::resolve` ('login' by `google_id`, link by verified e-mail) | none | return `GOOGLE_FAILED` when the resolved user `! canSignIn()`; the link-by-e-mail branch cannot match a NULL e-mail but add the check anyway |
| 5 | Invite accept (new account or child conversion) | `InviteAcceptController::store`, `InviteService::accept`, Google invite path | invitation `valid` | For a normal invitation no change. For a conversion invitation the target row has `sign_in_disabled=true` until accept clears it inside the same transaction (see Conversion) |
| 6 | Password reset | Fortify `password.email` (sends mail), `password.update` | token | `sendPasswordResetNotification` no-op + `ResetUserPassword::reset` throws; response stays the enumeration-safe "link sent" |
| 7 | Existing session / remember cookie of a user who later becomes disabled | Laravel `SessionGuard` | none | New middleware `BlockDisabledSignIn` in the web group (after `SetLocale`, before `HandleInertiaRequests`): if `$request->user()?->canSignIn() === false` then `Auth::logout()`, invalidate + regenerate token, redirect `/login` (401 for JSON). Also `SessionRevoker::revokeAll()` whenever the flag is turned on |
| 8 | `Auth::login` / `->login(` callers | exactly four: `ChallengePasskeyController`, `InviteAcceptController`, `GoogleController` (+ `ReauthController` logout) [VERIFIED: grep] | | Add an arch/grep test that fails when a new `Auth::login(` appears outside an allowlist |

Fortify has no `Features::registration()` (`RegistrationAbsentTest` exists) and passkey-only sign-in is deliberately absent (`security-passkeys.php` comment), so no other route logs a user in. `access:guest` routes (`login.store`, `two-factor.*`, invite, google redirect) all stay `guest`. [VERIFIED: routes/web/*.php, config/fortify.php]

The second-factor requirement (`SecondFactorPolicy`) is irrelevant for sign-in of a child (the child never reaches it) but must exclude children in its counters (Section B).

Child password column: `password` stays NOT NULL; store `Hash::make(Str::random(64))` and `password_set=false` (same technique as the Google-only account in `InviteService::accept`). `RedirectIfSecondFactor` and sessions then have nothing to attack, and the flag is a second lock.

### D. Notifications, push, reminders (child must receive nothing)
- Mail: `User::routeNotificationForMail()` override (Pattern 1). Mail senders: `InviteService::queueMail` (ad-hoc route, unaffected), `GoogleLinkedNotification`, `ResetPasswordNotification`, outbox `sendMail` via `notifyNow` (reminders, occasions). [VERIFIED: grep notify]
- Outbox producers that could target a child: `ReminderRecipients::for()` (assignee or creator; Child preset has Todo edit so a child can be a task assignee), `OccasionGivers::for()` (every member with Wishlist edit minus owner/managers), `EnqueueVaccinationReminders` (`VaccinationReminderPlanner` loads users). Exclude `sign_in_disabled` users in `OccasionGivers::for/isGiver` (`whereNot('sign_in_disabled')` and `canSignIn()` in `hasEdit`) and in `ReminderRecipients::canSee`. `SendOutboxItem` also skips on missing push subscriptions, but a mail row would be marked `sent` while `MailChannel` silently returned.
- Push: children have no `push_subscriptions`; `NotificationSettingsController` is self-only. No change beyond the recipient exclusions.
- Wishlist reservations by children: a child with Wishlist edit counts as a possible "giver" in `GivingGuard`; nobody can act as them, so harmless, but `OccasionGivers` exclusion above prevents reminders.

### E. Wishlist: owner list and managers (SC5 is mostly existing behaviour)
- `WishlistMembers::forViewer()` lists every user with a `module_permissions` row for `wishlist` at level >= view; viewer first, collator-sorted. A child created with `Preset::Child` grants (Wishlist Edit) therefore appears automatically as an owner chip. [VERIFIED]
- `WishAccess::canEditList(user, ownerId)`: requires the user's own Wishlist **Edit** grant AND `ViewerContext::isRecipientSideOf(ownerId)` (owner or row in `wishlist_managers`). Admins get nothing by role. Consequence for the UI-SPEC "Parents multi-select (members with Parent preset or admins)": an admin without a Wishlist Edit grant who is chosen as manager still cannot add wishes. Recommendation: the selector lists members with `access.wishlist === 'edit'` and `canSignIn`, and the create action requires at least one. Flag as spec deviation in the plan (Open Question 1).
- `WishlistManagersController::update` is admin-only (`access:admin` + `access:recent`, listed in `SensitiveRoutes::accessGranting()`), takes `manager_ids`, locks the owner row, and dispatches `PurgeManagerReservations` for newly added managers. Child creation should call the extracted `WishlistManagerSync` (new child has no reservations; skip the purge job or keep it, harmless).
- `UpdateWishlistManagersRequest` validates `exists:users,id` and not-the-owner only; add "manager must `canSignIn`" (a child cannot manage another list).
- Birth date / name day for a child: `MemberDatesRequest` + `OccasionAccess::canEditDates` already allow self, designated manager or admin with wishlist edit. No change needed (child birthday as occasion is a deferred suggestion).
- Wish images route `wishlist.images.show` checks the viewer's visible owner list (`WishlistMembers::ids`) and received-at rules; unchanged.

### F. Members settings and presets
- Route `GET /settings/members` is `access:admin` only (`routes/web/settings-members.php`). `MembersController::index` maps users explicitly to an array (`id, name, email, isAdmin, isSelf, access, wishlistManagerIds, security`) and the `presets` prop from `Preset::cases()`; invitations list from `Invitation` (non-accepted). Add to each member: `signInDisabled`, `isChild` (same flag), `photoVersion`, `managerNames` (derived from `wishlistManagerIds`, names only), and for children an `invitation` summary if a conversion invitation is open.
- `MemberPanel.vue` has tabs permissions / wishlist / sessions / security; for a child, hide sessions and security tabs (nothing to show; `/settings/members/{user}/sessions` and `/second-factor` stay admin-only and harmless) and add the Profile tab and the "Make a normal member" card. `PermissionsTab` offers "preset" selection client-side (`Settings/presets.js` + `presets` prop); the Child preset is `Preset::Child`.
- **Gap in the UI-SPEC:** it says a non-admin parent "sees only the Profile tab" of `MemberPanel`, but Settings > Members is admin-only so a parent can never open that panel. A parent needs their own entry point. Recommendation: `GET /profile/people/{user}` (same `Pages/Profile/Edit.vue` with a `subject` prop) reachable from a card "People you manage" on `/profile` (list of children for whom the viewer has a `wishlist_managers` row) and from the Wishlist page header of a managed child. Admin uses the same endpoints from the Members panel Profile tab.
- `PermissionService::save` is the only path changing grants (one transaction with audit rows). Child creation writes initial grants with `$preset->grants()` + `updateOrCreate` (same as `InviteService::accept`) and records a single `ChildCreated` audit row instead of four `permission_changed` rows.
- `SensitiveRoutes::accessGranting()` lists admin routes that widen access; a route that creates a user with Todo/Wishlist edit grants should carry `access:admin` + `access:recent` and be listed there (the test `SensitiveGateMatrixTest` enforces consistency). Conversion (issuing an invitation) already needs `access:recent` via `settings.invitations.*`.

### G. Account menu and avatar trigger
- `resources/js/Shell/TopBar.vue` renders `AccountMenu.vue` (`UiMenu` with `label=t('nav.account')`, `icon=CircleUser`). Items are built in `AccountMenu.vue`: header (name + e-mail sublabel), `settings` ("account.settings", icon `Settings`), `manual`, language radio-group, theme radio-group, optional style guide, separator, logout; `onSelect` routes by `key` (`router.visit('/settings')`). Add `{ type: 'item', key: 'profile', label: t('profile.menu'), icon: UserRound }` immediately before `settings` and `else if (key === 'profile') router.visit('/profile')`.
- `UiMenu` trigger is a `UiButton variant=secondary :icon="icon"`; it has no avatar support. UI-SPEC wants the user's avatar in the trigger and avatar 32 in the header. Add an optional `avatar` prop object (`{ name, userId }`) or a `#trigger-icon` slot to `UiMenu` and a `leading` field on the `header` item type; keep `icon` fallback so existing tests (`AccountMenuStyleGuide.test.js`, `AppShell.test.js`) pass. The header item type is rendered at `UiMenu.vue` `data-testid="menu-header"`.
- Shared auth prop: `HandleInertiaRequests::share()` `auth.user` has `id, name, email, locale, isAdmin`; add nothing there beyond what the global `avatars` map provides.

### H. UiAvatar and its callers (initials, no photo plumbing today)
`UiAvatar` props: `name` (required), `tint` 1..6, `size` validator `[32, 40]`, `decorative`; renders `initial` of `name`. Callers and what id they have today:

| Caller | File | Has user id? | Needed |
|--------|------|--------------|--------|
| Members list row | `Components/Settings/MemberRow.vue` | `member.id` | pass `:user-id` |
| Health access card | `Components/Settings/HealthAccessCard.vue` | `person.linkedUserId` (admin payload) or none for no-account people | pass `:user-id="person.linkedUserId"` (null -> initial) |
| Wishlist person chips | `Wishlist/PersonChips.vue` | `member.id` | pass |
| Wishlist gift list header | `Wishlist/GiftListView.vue` | `list.owner.id` (`ListPage`: `'owner' => ['id','name']`) | pass |
| Contributors | `Wishlist/ContributorList.vue` | `c.id` (= `user_id`) | pass |
| Task column | `Todo/TaskColumn.vue` | `personId` prop | pass |
| Visibility field | `Todo/VisibilityField.vue` | `m.id` | pass |
| Health person header | `Health/PersonHeader.vue` | `person.id` is an opaque UUID subject id, no user id (`PersonPage` exposes `hasAccount` only) | add `photoUserId` (linked `user_id` or null) to the `PersonPage` person payload; it only exists for persons the viewer may already open |
| Health person switcher | `Health/PersonSwitcher.vue` | uses its OWN `<span>{{ person.initials }}</span>` chip, not `UiAvatar` | decide: leave initials (recommended, minimal) or switch to `UiAvatar`; the task text lists it, so state the decision in the plan |
| Style guide | `StyleGuide/Sections/40-Display.vue` | demo | add a photo example only if wanted |
| Account menu | new | `auth.user.id` | pass |

Recommended mechanism: `HandleInertiaRequests::share()` adds a lazy prop `avatars` = `fn () => AvatarVersions::map()` returning `{ "12": 3, ... }` for users that have a photo (one indexed query, tiny family). `UiAvatar` gets props `userId: Number|null` and optional explicit `src`; when `userId` is in `usePage().props.avatars` it renders `<img src="/people/{id}/photo?v={version}" alt="" loading="lazy">` filling the circle (`object-fit: cover`) and falls back to the initial on `@error`. Component tests that mock `@inertiajs/vue3` (e.g. `a11y-sweep.test.js`, `ui/display.test.js` use `usePage` mocks without `avatars`) must tolerate a missing `avatars` prop (`page.props.avatars ?? {}`), and `UiAvatar` must keep working when imported outside an Inertia context (tests mount it with `mountUi`). Use `inject`-free access guarded with try/catch or a tiny composable `useAvatarUrl(userId)`; otherwise ~40 existing tests that mount `UiAvatar` break.
Size validator becomes `[32, 40, 96]`; initial font at 96 uses `--text-2xl`.

### I. Photo pipeline and storage (reuse)
- `ImageProcessor::process($bytes, $maxBytes, $fullPx, $thumbPx, $quality, $decodeBudget)` returns `ProcessedImage(fullBytes, thumbBytes, width, height)`; both outputs are fresh JPEGs without metadata; it fits the LONGEST edge (no square crop). UI-SPEC says "square 512px"; either (a) rely on `object-fit: cover` in `UiAvatar` (recommended; zero change to the shared processor) or (b) add an optional `square: bool` to `render()` that center-crops before resampling (smaller memory, but touches the shared class used by wish images; keep default false and cover with tests). Pick (a) and note it.
- `WishImageStore` is wish-specific (`Wish $wish`, `wish-images/{wishId}`); create `ProfilePhotoStore` with the same write-then-swap semantics (both files confirmed written before touching the row, old files deleted after, `Storage::disk('local')`, `throw=false` means check `put() !== false`, delete both files on failure). Path: `wish-images/profile-photos/{userId}/{ulid}-full.jpg` and `-thumb.jpg`.
- **Persistence trap (HIGH impact):** `compose.yaml` and `compose.server.yaml` mount the persisted volume `uploads` only at `/var/www/html/storage/app/private/wish-images` (`x-uploads` anchor; `scripts/check-compose.sh` asserts exactly that mount; `scripts/backup.sh` tars `UPLOADS_PATH=.../wish-images`). Any other `local`-disk directory (for example `profile-photos/` next to `wish-images/`) lives in the container filesystem and vanishes on redeploy. Storing under the `wish-images/` prefix keeps it persisted and in the nightly restic snapshot with zero infra change. [VERIFIED: compose.yaml, compose.server.yaml, scripts/check-compose.sh, scripts/backup.sh, docs/ops/server.md]
- **Orphan sweeper collision:** `WishImageStore::sweepOrphans()` (daily `wishlist:prune`) deletes every file under `wish-images` that no `wishes` row references and that is older than 60 minutes. Without a change it would delete all profile photos within a day. Change it to skip the `wish-images/profile-photos/` prefix and add `ProfilePhotoStore::sweepOrphans()` (referenced = `users.photo_path/photo_thumb_path`), called from `PruneWishlist` or a new tiny command. Add a regression test.
- nginx: upload bodies above 1m get 413 at the proxy. `docker/nginx/snippets/laravel-locations.conf` has per-route `client_max_body_size` locations (wish image 8m, finance import 3m, medical 12m). Add `location ~ ^/profile(/people/[0-9]+)?/photo$ { client_max_body_size 8m; include security-headers; include fastcgi-app }`. `docker/app/php-uploads.ini` limits (11M/13M, 2 files) already suffice. The dev nginx includes the same snippet. To avoid a second nginx rule for "create child with photo", make creation JSON-only and upload the photo with the photo endpoint right after creation (the dialog chains the two requests).
- Delivery route: `GET /people/{user}/photo/{variant}` (`whereNumber`, `whereIn variant [full, thumb]`), `access:authenticated`. `ProfilePhotoAccess::canView(viewer, subject)`: self, admin, designated manager, or the viewer holds at least View on any module (every avatar is rendered inside a module screen, Members (admin) or the account menu); otherwise uniform 404 (same as unknown id or no photo). Never 403 (do not confirm existence). The query `?v=` is a cache buster only; the server ignores its value.
- Service worker: `resources/js/sw.js` is precache-only (`precacheAndRoute(self.__WB_MANIFEST)`, navigation fallback to `offline.html`, no `registerRoute`/runtime caching), so photos requested by `<img>` (not `navigate`) are never cached by the SW. Keep it that way; add an assertion in `tests/js/sw-precache.test.js` style that `sw.js` contains no `registerRoute`/`CacheFirst` and that the build manifest has no `/people/` entry. [VERIFIED: resources/js/sw.js]
- Cache headers: `private, no-cache` + `ETag` + `Vary: Cookie`; browser revalidates each time with a conditional request and gets 304 (access re-checked). `NoStoreDynamicResponses` allowlist gets the new route name `profile.photo.show` (200/304 only). Inertia history encryption (`Inertia::encryptHistory`) is unrelated.
- Upload validation: new `UploadProfilePhotoRequest` mirroring `UploadWishImageRequest` (`authorize()` runs `ProfileAccess` BEFORE validation so a non-permitted actor gets 403 whatever the body; `rules`: `['required','file','max:'.config('wishlist.upload_max_kb')]`; error keys `profile.photo.err_*`). Process via `ImageProcessor` and map `ImageRejected` reasons as `WishImageController::store` does. Throttle like `throttle:20,1,wishlist.images.store` (named limiter pattern `throttle:N,M,prefix`).
- Add `config/profile.php` (or reuse `config('wishlist.*')` keys) for `photo_full_px=512`, `photo_thumb_px=192`, name max 60.

### J. Profile page and names
- UI-SPEC: name required, max 60. `users.name` is `varchar(255)`; Google accept truncates to 120 and the audit logger truncates labels to 120. Existing members may already have names > 60; validation applies on edit only, and must not reject unchanged long names that predate the rule (`UpdateProfileRequest`: max 60 when the name differs from the stored one, otherwise accept; or raise to 120 to match existing data and note the deviation).
- Name must not contain control characters; trim; collapse whitespace; reject empty after trim.
- E-mail displayed read-only (`readonly` + `aria-readonly`), never in the update payload; `UpdateProfileRequest` must `prohibit` an `email` key (a request carrying `email` is a 422/ignored; test that it does not change).
- Self edit does not need `access:recent` (not access-granting). Edits of ANOTHER user's name/photo (child) are low risk too; no `recent`. Record an audit row `ProfileChangedByOther` only when actor != target.

### K. Audit log conventions
`AuditEvent` enum cases, `metaKeys()` allowlist (content-free; throws on unknown keys or non-scalars, max 120 chars), exhaustive `group()` `match` without default (adding a case without a group throws `UnhandledMatchError` and breaks the audit page), UI strings via `AuditTable.vue` `switch (row.event)` + `lang/*.json` `audit.detail.*`. Add: `ChildCreated` ('child_created', group `account`), `ChildConverted` ('child_converted', group `account`, meta `[]`; the invitation rows already audit `invite_created` with `email`), `ProfileChangedByOther` ('profile_changed_by_other', group `account`, meta `['field']` where field is `name` or `photo`; never the name text). Update `AuditTable.vue`, `lang/{en,sk,pl}.json` (`audit.detail.<event>` and filter labels if the table lists event names), and any test that enumerates `AuditEvent::cases()`. Existing invite events keep working: `invite_created` meta `email, preset`.
`staging:audit` rule on `audit_logs` flags `meta->>'email'` not ending `@example.test`; conversion on staging with a synthetic e-mail passes.

### L. Migrations convention
Files `database/migrations/2026_11_NN_HHMMSS_<verb>_<what>.php`, anonymous `Migration` class, raw `DB::statement` for Postgres specifics (see `users_email_lower_unique`), latest existing file is `2026_11_25_100000_create_help_texts_table.php`, so use `2026_11_26_100000_...`. Deploy runs `migrate --force` after an encrypted `pre-migrate` restic backup (`scripts/deploy.sh`); tests run `RefreshDatabase` on Postgres (`family_hub_test`, DB host `postgres`, so tests run inside the compose environment).
Proposed single migration (reversible):
```php
Schema::table('users', function (Blueprint $t) {
    $t->boolean('sign_in_disabled')->default(false);
    $t->string('photo_path')->nullable();
    $t->string('photo_thumb_path')->nullable();
    $t->unsignedInteger('photo_version')->default(0);   // bumped on every change, used as ?v=
});
DB::statement('ALTER TABLE users ALTER COLUMN email DROP NOT NULL');
DB::statement("ALTER TABLE users ADD CONSTRAINT users_email_not_blank CHECK (email IS NULL OR email <> '')");
DB::statement('ALTER TABLE users ADD CONSTRAINT users_email_or_sign_in_disabled CHECK (email IS NOT NULL OR sign_in_disabled)');
Schema::table('invitations', fn (Blueprint $t) => $t->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete());
```
`down()`: must first refuse or handle rows with NULL e-mail (delete is destructive; recommend `throw` when `users.email IS NULL` rows exist, with the message to remove children first), drop constraints, restore `SET NOT NULL`, drop columns. Never run `change()` on `email` (it would restate type and risk re-creating the dependent unique indexes).
Production table is tiny and populated; `DROP NOT NULL`, `ADD COLUMN ... DEFAULT false` (PG >= 11 is metadata-only) and the two CHECKs (full scan of a few rows; existing rows all satisfy them because every existing e-mail is non-null and non-blank, which is guaranteed by `required|email` on every creation path and the existing NOT NULL) are instant. Nothing needs a backfill. `photo_version` default 0 plus `photo_path IS NULL` = no photo.
Index: avatars query `users where photo_path is not null` on a handful of rows needs no index.

### M. Staging data, `staging:audit`, `StagingSeeder` (must stay synthetic-only)
- `StagingAudit::rules()`: `$synthetic = (select id from users where email like '%@example.test')`, `$real = (select id ... where email not like ...)`, rule `['users', "email not like '%@example.test'"]`, `['invitations', ...]`. With NULL e-mails, `NULL like` and `NULL not like` are both NULL, so a real child would silently pass (no "FAILED") AND would not appear in `$real`, so finance/medical rows it created would pass too (a child cannot create such rows, but `medical_subjects.user_id` links and `created_by` could reference it).
- Fix: mark synthetic children with `email IS NULL AND name LIKE '% (demo)'` (the seeder already suffixes names with "(demo)"; audit rows use `%(demo)` labels). Redefine `$synthetic = (select id from users where email like '%@example.test' or (email is null and name like '% (demo)'))`, `$real = (select id from users where id not in {$synthetic})`, and the users rule `id not in {$synthetic}`. Add a rule for photos: `users` with `photo_path is not null and id not in {$synthetic}`.
- `StagingSeeder::run()` deletes `User::where('email','like','%@example.test')` after `deleteSyntheticOwnedRows`; extend both selects with the synthetic-child condition, and delete the synthetic users' photo files (`DB::afterCommit` like the medical document cleanup, using `ProfilePhotoStore`) else files orphan until the sweeper removes them.
- Seed one synthetic child ("Dieťa (demo)", NULL e-mail, `sign_in_disabled`, `Preset::Child` grants, managed by `parent2` in place of (or in addition to) the existing `child2` manager row) with a GD-generated synthetic photo (no network, no real face). `StagingSeedTest` assertions `User::count() === 7` and the e-mail not-like check must be updated; `docs/ops/staging-data.md` scenario map gets a line.
- Staging verification uses the existing demo accounts (shared seed password in `config('family.staging_seed_password')`), never real addresses.

### N. Test conventions to follow
- Pest (`tests/Feature`, `RefreshDatabase`, Postgres): `User::factory()->withPreset(...)`, `Roles::make()` seven roles, `$this->withoutMiddleware(PreventRequestForgery::class)` for writes, `Storage::fake('local')`, `Tests\Support\ImageFixtures` (in-memory JPEG/PNG/EXIF/GPS), `Inertia\Testing\AssertableInertia`, `Queue::fake()`.
- Endpoint matrix: every NAMED route needs a row in `tests/Support/EndpointMatrix/*.php` with all seven roles (`EndpointMatrixTest` fails on missing/stale rows or missing `access:` middleware). Create `tests/Support/EndpointMatrix/profile.php` (routes `profile.show`, `profile.update`, `profile.photo.store`, `profile.photo.destroy`, `profile.photo.show`, `profile.people.*`, `settings.members.children.store`). Expectations are literal.
- `SensitiveGateMatrixTest` / `SensitiveRoutes::accessGranting()`: add the child-creation route if it carries `access:recent`.
- Arch tests (`tests/Unit/Arch/*`): add `SignInArchTest` (every `Auth::login(` / `->login(` caller in an allowlist; `FortifyServiceProvider` references `canSignIn`). `WishlistPrivacyArchTest` forbids owner-side code from using reservation models; the new `WishlistManagerSync` lives in `App\Wishlist` root and must not use `WishReservation`/`WishContribution` (and `PurgeManagerReservations` dispatch stays in the controller/giving side).
- Privacy differential tests: same request for self/admin/parent-manager/unrelated member/child/no-grants user; assert 404/403 and that the response body contains no photo bytes (`ImageFixtures` marker). Payload tests: Members page must not expose `photo_path`, `photo_thumb_path`, `password`, `remember_token`, `google_id` (`MembersPageTest` has a "does not leak secrets" pattern to extend).
- Vitest + jsdom + axe (`tests/js/helpers/axe.js`, `mount.js`, `pseudoLong.js` for +30% text, `tests/js/a11y-sweep.test.js` registers pages in light/dark and sk/pl/en). Register `Pages/Profile/Edit` there. Locale parity: `tests/js/i18n-phaseN.test.js` (new `i18n-phase10.test.js`: every new key exists in sk, pl, en). i18n messages live in `lang/{en,sk,pl}.json` (nested), NOT in `resources/js` as the UI-SPEC copy table says. Help: `tests/js/help/HelpCoverage.test.js`; manual: `tests/js/manual/ManualCoverage.test.js` and `tests/Feature/Manual/ManualPageTest.php`.
- Components mocking `@inertiajs/vue3` `usePage` (e.g. a11y-sweep) lack an `avatars` prop; `UiAvatar` must degrade to the initial.

### O. User documentation rule (CLAUDE.md, mandatory in every plan that changes behaviour)
- Manual: `resources/manual/{en,sk,pl}/<topic>.json`, `covers` internal ids are validated by `ManualCoverage.test.js`: `module:<key>` for `Shell/modules.js` entries and `settings:<key>` for `SettingsSections.php` keys, plus the `FEATURES` allowlist in that test (`feature:help`, `feature:help-admin`). `/profile` is neither a module nor a Settings section: add `feature:profile` to `FEATURES` and to the `valid` set (the test file must be edited), create `resources/manual/{en,sk,pl}/profile.json` (category `account`, same section ids/block counts/links in all three), and edit `admin.json` section `members-invitations` (child, convert) and `wishlist.json` (a child's list, who manages it), `who-sees-what.json` (photo visibility), and `faq.json` if a question fits. Mark sk/pl for native review in the summary.
- Contextual help: add keys to `resources/help/topics.json` (`profile.photo`, `profile.name`, `members.child`; areas `account` and `settings`, add new `screens` names such as `profile_page` and `members_child` to `en.json`, `sk.json`, `pl.json` `screens`), texts <= 400 chars in all three, placed with `<HelpTip topic="..." />` or `UiField help`. `HelpCoverage.test.js` fails on unused/unknown/missing-language topics. `php artisan help:sync` runs on deploy.
- Plans must list the touched `resources/manual/` files in `files_modified` and run: `npx vitest run tests/js/manual tests/js/help` and `pest tests/Feature/Manual tests/Feature/Help`.

## Common Pitfalls

### Pitfall 1: Photos vanish on redeploy
**What goes wrong:** Photos are written to a `local`-disk path outside `wish-images/`, which is not a volume. **Why:** only `uploads:/.../storage/app/private/wish-images` is persisted and backed up. **Avoid:** store under `wish-images/profile-photos/`. **Warning sign:** `check-compose.sh` or a manual redeploy on staging shows avatars falling back to initials.

### Pitfall 2: The nightly sweeper deletes photos
**What goes wrong:** `WishImageStore::sweepOrphans` removes files under `wish-images` that no wish references. **Avoid:** exclude the prefix and add a profile sweeper; add a test that creates a photo older than 60 minutes and runs `wishlist:prune`.

### Pitfall 3: 413 on upload
**What goes wrong:** nginx default `client_max_body_size 1m` rejects a 5 MB photo before PHP. **Avoid:** add the location block (Section I); do not create the child with a multipart body.

### Pitfall 4: `NULL` semantics break "is it synthetic / is it a duplicate"
`NULL LIKE`, `NULL NOT LIKE`, `NULL = ''` all evaluate to NULL (not true), so SQL guards written for non-null e-mails silently pass. Patch `staging:audit`, seeder purge and any `where email not like` test assertion; never store `''` (CHECK).

### Pitfall 5: A new session path forgets the flag
Six paths (Section C) establish sessions. **Avoid:** predicate `canSignIn()`, the middleware, the grep/arch test and a differential feature test that tries every route for a child (password with a known hash is impossible to construct for a child, so the test forces the strongest case: give the child an e-mail AND a known password AND `sign_in_disabled=true` and prove that password login, 2FA challenge with a forged `login.id`, passkey challenge, Google callback, reset link/update, invitation accept for the same e-mail and an existing session cookie all fail).

### Pitfall 6: Conversion creates a second user
**Avoid:** `invitations.user_id`; `InviteService::accept` branch: lock invitation, lock the user row, verify `sign_in_disabled && email IS NULL`, re-check e-mail uniqueness case-insensitively (the unique index backs it), then set `email`, `email_verified_at`, `password` (or Google id), `locale`, `password_set`, `sign_in_disabled=false`, keep module grants and `wishlist_managers`. The `accept()` return must be that same user, and the audit row `ChildConverted`. Existing `invitations_one_open` partial unique index (one open invite per lower(email)) still applies. The invitation preset field is required NOT NULL: store the child's closest preset (`child`) but do not re-apply grants. The "Invited" tag on the child row derives from an open invitation with `user_id`. Revoking the invitation leaves the child unchanged. Google invite acceptance goes through the same accept (`$googleId`) so it also converts.

### Pitfall 7: Members/Wishlist payload carries `null` e-mail into the Vue
`MemberRow.vue` and `MemberPanel.vue` print `member.email`; `PermissionMatrix`, `WishlistManagersTab` may search/sort by email. Grep the Vue for `.email` (done: only the two components above and Notifications/AccountMenu for self) and add a render test for a child row.

### Pitfall 8: Admin manager without Wishlist Edit grant
See E. The UI-SPEC multi-select of "members with Parent preset or admins" must filter on the actual Wishlist Edit grant, otherwise the admin is a manager who cannot add a wish.

### Pitfall 9: `UiAvatar` used without Inertia
Many unit tests mount `UiAvatar` through `mountUi` without `usePage`. Access `usePage()` defensively (composable returning `{}` outside Inertia).

### Pitfall 10: Name length rule rejects existing members
UI-SPEC max 60, existing data and Google accept allow up to 120/255; validate "max 60 only when changed" or use 120.

### Pitfall 11: Child counted in security/second-factor counters
`SecondFactorPolicy::appliesTo` and `pendingCount()` must exclude children (Section B).

### Pitfall 12: Photo URL leakage in referrer/log
The photo URL has no secret (the auth cookie guards it). `NoReferrer` middleware exists for auth/invite pages; not needed here. Do not log photo bytes or paths in exceptions (`ScrubbedQueryReport` only scrubs `medical_*`).

## Code Examples

### Sign-in predicate and mail routing (User model)
```php
// Source: this repo, app/Models/User.php (proposed addition)
public function canSignIn(): bool
{
    return ! $this->sign_in_disabled && $this->email !== null;
}

public function routeNotificationForMail($notification = null): ?string
{
    return $this->canSignIn() ? $this->email : null;
}

public function sendPasswordResetNotification($token): void
{
    if (! $this->canSignIn()) {
        return;
    }
    $this->notify(new ResetPasswordNotification((string) $token));
}
```

### authenticateUsing (keep timing path)
```php
// Source: app/Providers/FortifyServiceProvider.php (proposed change)
Fortify::authenticateUsing(function (Request $request) {
    $user = User::where('email', RequestInput::email($request))->first();
    $matches = Hash::check(RequestInput::string($request, 'password'), $user?->password ?? self::DUMMY_HASH);

    return ($user && $user->canSignIn() && $matches) ? $user : null;
});
```

### Defence-in-depth middleware
```php
// Source: proposed app/Http/Middleware/BlockDisabledSignIn.php, registered in bootstrap/app.php web(append: [...]) before HandleInertiaRequests
public function handle(Request $request, Closure $next): Response
{
    $user = $request->user();
    if ($user !== null && ! $user->canSignIn()) {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return $request->expectsJson() && ! $request->header('X-Inertia')
            ? response()->json(['message' => 'Unauthenticated.'], 401)
            : redirect('/login');
    }

    return $next($request);
}
```

### Profile photo store (shape)
```php
// Source: modelled on app/Wishlist/Catalog/WishImageStore.php
$processed = $processor->process($bytes, 5 * 1024 * 1024, fullPx: 512, thumbPx: 192);
$disk = Storage::disk('local');
$name = strtolower((string) Str::ulid());
$dir = 'wish-images/profile-photos/'.$user->id;       // MUST stay under wish-images (persisted volume)
// put both; if either returns false -> delete both, throw ProfilePhotoWriteFailed, touch nothing else
// then forceFill(['photo_path'=>..., 'photo_thumb_path'=>..., 'photo_version' => DB::raw('photo_version + 1')])->save(); delete old files
```

### Access rules
```php
// Source: proposed app/Profile/ProfileAccess.php
public function canEdit(User $actor, User $target): bool
{
    if ($actor->is($target)) { return true; }
    if (! $target->sign_in_disabled) { return false; }              // only children are editable by others
    return $actor->is_admin || ViewerContext::for($actor)->isManagerOf($target->id);
}
```
(A manager who is also required to hold the Wishlist Edit grant is NOT needed for editing a name/photo; decide in the plan, recommend: manager row is enough.)

## State of the Art

| Old Approach | Current Approach | When Changed | Impact |
|--------------|------------------|--------------|--------|
| Fortify `RedirectIfTwoFactorAuthenticatable` only knows TOTP | Project subclass `RedirectIfSecondFactor` also challenges passkey-only members | Phase 6 | Child lockout must still go through `authenticateUsing` (shared) |
| `change()` column modifiers with doctrine/dbal | Native schema builder `change()` in Laravel 11+ | Laravel 11 | Still prefer raw `ALTER ... DROP NOT NULL` here (restating the column is unnecessary risk) [ASSUMED: Laravel 13 `change()` re-states full definition; not tested here] |

## Runtime State Inventory

Not a rename phase. State touched by migration only:

| Category | Items Found | Action Required |
|----------|-------------|------------------|
| Stored data | `users` rows (production populated, all with e-mail); `invitations` | Schema migration only, no data migration, no backfill |
| Live service config | None (no external service holds user identity except Google OAuth subject on existing rows) | None |
| OS-registered state | None | None |
| Secrets/env vars | None new | None |
| Build artifacts | `public/build` Vite bundle changes; SW precache manifest regenerates (`scripts/check-sw-precache.mjs` runs in `npm run build`) | normal build |

## Assumptions Log

| # | Claim | Section | Risk if Wrong |
|---|-------|---------|---------------|
| A1 | Laravel 13 `change()` restates the whole column definition (so raw `DROP NOT NULL` is safer) | State of the Art | Low: the recommended raw statement works either way |
| A2 | A manager row alone (no Wishlist Edit grant) should suffice to edit a child's name/photo | Code Examples | Owner might expect the same grant requirement as wishes; trivial to tighten |
| A3 | Any signed-in member with at least View on one module may fetch any member's photo | Section I | If the owner wants photos visible only to people who see the person's list, tighten `ProfilePhotoAccess` |
| A4 | CSS `object-fit: cover` is acceptable instead of server-side square crop | Section I | Non-square photos will be cropped in circles by CSS only; the stored full image keeps its aspect |
| A5 | PostgreSQL version in production is >= 11 (instant `ADD COLUMN DEFAULT`) | Section L | On older PG the tiny table rewrite is still instant at this size |

## Open Questions

1. **Parents selector vs. admin (UI-SPEC says "members with Parent preset or admins")**
   - Known: `WishAccess` has no admin bypass; managers need their own Wishlist Edit grant to edit wishes.
   - Unclear: whether the owner wants an admin without that grant selectable.
   - Recommendation: list members with Wishlist Edit who `canSignIn`; admin appears only if granted; at least one required. Show the admin a hint if they themselves lack the grant.
2. **Entry point for a non-admin parent to edit a child's profile** (UI-SPEC only describes the admin-only Members panel).
   - Recommendation: `/profile/people/{user}` plus a "People you manage" card on `/profile`; document as an addition to the UI contract.
3. **PersonSwitcher in Health uses its own initials chip.** Recommendation: keep initials there (no photo) to avoid exposing a photo next to health person names without need; record the decision.
4. **Name max length 60 vs. existing data.** Recommendation: enforce 60 only on change or use 120.
5. **Admin turning `sign_in_disabled` on an existing normal member** (issue #6 criterion 4 reads "can be marked no direct sign-in"; UI-SPEC shows a read-only switch for children only). Recommendation: out of scope; flag is only set at child creation. If the owner wants it later, the middleware and `SessionRevoker` already handle live sessions.

## Environment Availability

| Dependency | Required By | Available | Version | Fallback |
|------------|------------|-----------|---------|----------|
| PHP GD, `finfo`, `exif` | `ImageProcessor` | assumed present in the app image (already used for wish images) | — | — |
| PostgreSQL | tests and migration | via compose service `postgres` (`phpunit.xml` DB_HOST=postgres) | — | — |
| Node 22 container | Vitest | `package.json` devDependencies (host Node is 18; project notes say run tests in the container) | — | — |

No new external dependency. Probing the host was not needed for this phase.

## Validation Architecture

### Test Framework
| Property | Value |
|----------|-------|
| Framework | Pest 4 (PHP, Postgres `family_hub_test`) and Vitest 5 + jsdom + axe-core |
| Config file | `phpunit.xml`, `tests/Pest.php`, `vitest.config.js` |
| Quick run command | `pest tests/Feature/Auth tests/Feature/Settings tests/Feature/Profile` / `npx vitest run tests/js/settings tests/js/shell tests/js/ui` |
| Full suite command | `pest` and `npx vitest run` (run in the compose environment) |

### Phase Requirements -> Test Map
| Req | Behavior | Test Type | Automated Command | File Exists? |
|-----|----------|-----------|-------------------|-------------|
| #6 SC4/SC7 | Child never authenticates (password, forged 2FA `login.id`, passkey, Google, reset, invite for its e-mail, remember/session) | feature differential | `pest tests/Feature/Auth/ChildSignInBlockedTest.php` | Wave 0 |
| #6 SC7 | No `Auth::login` outside allowlist; Fortify uses `canSignIn` | arch | `pest tests/Unit/Arch/SignInArchTest.php` | Wave 0 |
| #6 SC7 | Child never receives mail (reset, google linked, outbox reminders/occasions, recipients exclude children) | feature | `pest tests/Feature/Notifications/ChildNoMailTest.php` | Wave 0 |
| #6 | E-mail nullable migration, CHECK constraints, uniqueness (case-insensitive, many NULLs) | feature | `pest tests/Feature/Auth/UserEmailNullableTest.php` | Wave 0 |
| #6 SC5 | Child appears as Wishlist owner; manager adds wishes; non-manager and admin-without-grant cannot | feature | `pest tests/Feature/Wishlist/ChildListTest.php` | Wave 0 |
| #6 | Create child: grants, manager rows, audit, validation (>=1 parent), admin only + recent | feature | `pest tests/Feature/Settings/ChildCreateTest.php` | Wave 0 |
| #6 | Convert child: same row kept, grants/managers kept, flag cleared, duplicate e-mail rejected, revoke leaves child | feature | `pest tests/Feature/Invites/ChildConversionTest.php` | Wave 0 |
| #7 SC2 | Name edit self/parent/admin; e-mail untouched; unrelated member refused | feature | `pest tests/Feature/Profile/ProfileUpdateTest.php` | Wave 0 |
| #7 SC3/SC7 | Photo upload re-encode (no EXIF/GPS), size/type rejection, replace deletes old files, remove, private route 200/304/404 matrix, headers, sweeper keeps files | feature | `pest tests/Feature/Profile/ProfilePhotoTest.php` | Wave 0 |
| #7 | Endpoint matrix rows for all new routes | feature | `pest tests/Feature/Access/EndpointMatrixTest.php` | add `EndpointMatrix/profile.php` |
| #7 SC1 | Menu item above Settings, avatar trigger | vitest | `npx vitest run tests/js/shell` | extend existing |
| #7 SC3 | `UiAvatar` photo/initial/error fallback, size 96, callers pass user id | vitest | `npx vitest run tests/js/ui/display.test.js tests/js/wishlist tests/js/todo` | extend |
| #7/#6 | Profile page, `ProfilePhotoEditor`, `ChildCreateDialog`, MemberRow/Panel child states: behavior, a11y (axe), SK/PL +30% text | vitest | `npx vitest run tests/js/profile tests/js/settings/MembersPage.test.js` | Wave 0 |
| docs | Manual, help, lang parity in sk/pl/en | vitest + pest | `npx vitest run tests/js/manual tests/js/help tests/js/i18n-phase10.test.js` and `pest tests/Feature/Manual tests/Feature/Help` | Wave 0 (i18n-phase10) |
| staging | `staging:audit` flags a real child, passes the synthetic seed; reseed leaves no duplicate or photo orphan | feature | `pest tests/Feature/StagingSeedTest.php tests/Feature/StagingDemoSeedTest.php` | extend |

### Sampling Rate
- **Per task commit:** the targeted Pest file(s) or Vitest folder of the touched area.
- **Per wave merge:** `pest tests/Feature/Auth tests/Feature/Settings tests/Feature/Profile tests/Feature/Wishlist tests/Feature/Access tests/Unit/Arch` + `npx vitest run`.
- **Phase gate:** full `pest` + full `npx vitest run` green, then staging browser verification on synthetic data.

### Wave 0 Gaps
- [ ] `tests/Feature/Auth/ChildSignInBlockedTest.php`, `tests/Unit/Arch/SignInArchTest.php`
- [ ] `tests/Feature/Auth/UserEmailNullableTest.php`
- [ ] `tests/Feature/Notifications/ChildNoMailTest.php`
- [ ] `tests/Feature/Profile/{ProfileUpdateTest,ProfilePhotoTest}.php`, `tests/Support/EndpointMatrix/profile.php`
- [ ] `tests/Feature/Settings/ChildCreateTest.php`, `tests/Feature/Invites/ChildConversionTest.php`, `tests/Feature/Wishlist/ChildListTest.php`
- [ ] `tests/js/profile/*`, `tests/js/i18n-phase10.test.js`
- [ ] `UserFactory::child()` state and, if needed, `Roles` helper for a child-account role (do NOT add an 8th role to `Roles::all()` because `EndpointMatrixTest` requires all seven in every row; build the child ad hoc in the dedicated tests)
- Framework install: none.

## Security Domain

### Applicable ASVS Categories
| ASVS Category | Applies | Standard Control |
|---------------|---------|-----------------|
| V2 Authentication | yes | `canSignIn()` on all six session paths + middleware; random unusable password; no reset/invite for children |
| V3 Session Management | yes | `SessionRevoker::revokeAll` + `BlockDisabledSignIn` for live sessions and remember tokens |
| V4 Access Control | yes | `ProfileAccess`, `ProfilePhotoAccess`, `access:admin` + `access:recent` for creation; uniform 404 for photo refusal; route matrix tests |
| V5 Input Validation | yes | FormRequest rules (name trim/length, `prohibit` e-mail), `ImageProcessor` byte sniffing, size/pixel caps |
| V6 Cryptography | no new | passwords hashed by framework; none hand-rolled |
| V12 Files | yes | private disk, no serve route, re-encode strips metadata/GPS, nginx body cap, `nosniff` |

### Known Threat Patterns
| Pattern | STRIDE | Standard Mitigation |
|---------|--------|---------------------|
| Image bomb / polyglot upload | DoS/Tampering | `ImageProcessor` pixel cap, decode lock, re-encode to fresh JPEG |
| EXIF/GPS leak from phone photos | Information disclosure | Re-encode drops all metadata (existing test fixtures cover EXIF/GPS) |
| IDOR on photo or profile edit (numeric ids) | Information disclosure/Tampering | Policy classes + uniform 404/403; differential tests |
| Account takeover via child row (reset/invite/Google) | Spoofing | `canSignIn()` everywhere; reset notification and reset action refuse; Google refuses; invitation conversion only by admin with `access:recent` |
| Admin-created child silently becomes loginable | Elevation | CHECK `email IS NOT NULL OR sign_in_disabled`; accept branch clears flag only inside the locked transaction |
| Photo cached on a shared device / by proxy | Information disclosure | `private, no-cache` + `Vary: Cookie`; SW has no runtime cache; `Cache-Control` test |
| E-mail enumeration via reset | Information disclosure | Keep the identical "link sent" response for unknown/child/disabled |

## Files Likely Touched

Backend: `database/migrations/2026_11_26_100000_*.php`, `app/Models/User.php`, `database/factories/UserFactory.php`, `app/Providers/FortifyServiceProvider.php`, `app/Http/Controllers/Auth/GoogleController.php` (or `app/Auth/GoogleLoginService.php`), `app/Http/Controllers/Security/ChallengePasskeyController.php`, `app/Http/Middleware/GuardTwoFactorRoutes.php`, `app/Http/Middleware/BlockDisabledSignIn.php` (new), `bootstrap/app.php`, `app/Actions/Fortify/ResetUserPassword.php`, `app/Security/SecondFactorPolicy.php`, `app/Wishlist/Occasions/OccasionGivers.php`, `app/Tasks/ReminderRecipients.php`, `app/Medical/Reminders/VaccinationReminderPlanner.php` (check recipients), `app/Wishlist/Catalog/WishImageStore.php` (sweeper), `app/Console/Commands/PruneWishlist.php`, `app/Profile/*` (new), `app/Actions/Profile/*` (new), `app/Http/Controllers/Profile/*`, `app/Http/Controllers/Settings/MembersController.php`, `app/Http/Controllers/Settings/WishlistManagersController.php` (+ `app/Wishlist/WishlistManagerSync.php`), `app/Http/Requests/Settings/UpdateWishlistManagersRequest.php`, `app/Http/Requests/Profile/*`, `app/Auth/InviteService.php`, `app/Models/Invitation.php`, `app/Http/Controllers/Settings/InvitationsController.php`, `app/Http/Requests/Settings/StoreInvitationRequest.php` (+ child conversion request), `app/Http/Middleware/NoStoreDynamicResponses.php`, `app/Http/Middleware/HandleInertiaRequests.php`, `app/Audit/AuditEvent.php`, `app/Medical/PersonPage.php` (`photoUserId`), `routes/web/profile.php` (new) and `routes/web/settings-members.php`, `database/seeders/StagingSeeder.php` (+ `Staging/WishlistSeeder.php`), `app/Console/Commands/StagingAudit.php`, `docker/nginx/snippets/laravel-locations.conf`, `config/profile.php` (or wishlist keys).

Frontend: `resources/js/Components/ui/UiAvatar.vue`, `UiMenu.vue`, `resources/js/Shell/AccountMenu.vue`, `resources/js/Pages/Profile/Edit.vue`, `resources/js/Components/Profile/ProfilePhotoEditor.vue`, `ChildCreateDialog.vue`, `resources/js/Components/Settings/{MemberRow,MemberPanel,InviteDialog?,AuditTable}.vue`, `resources/js/Pages/Settings/Members.vue`, `resources/js/Settings/presets.js` (no change expected), avatar call sites (`PersonChips`, `ContributorList`, `GiftListView`, `TaskColumn`, `VisibilityField`, `HealthAccessCard`, `PersonHeader`), `resources/js/Pages/Wishlist/Index.vue` (optional "edit profile" link).

Lang/docs: `lang/{en,sk,pl}.json`, `resources/manual/{en,sk,pl}/{profile,admin,wishlist,who-sees-what}.json` (+ `_categories` unchanged), `resources/help/{topics,en,sk,pl}.json`, `tests/js/manual/ManualCoverage.test.js` (FEATURES), `docs/ops/staging-data.md`, `docs/ops/server.md` (one line: photos live in the wish-images volume).

Tests: see Wave 0 Gaps; existing to update: `tests/Feature/StagingSeedTest.php`, `StagingDemoSeedTest.php`, `tests/Feature/Settings/MembersPageTest.php`, `tests/Feature/CacheHeadersTest.php`, `tests/js/shell/*`, `tests/js/AccountMenuStyleGuide.test.js`, `tests/js/a11y-sweep.test.js`, `tests/js/ui/display.test.js`.

## Proposed Plan Split (small plans, waves)

| Plan | Wave | Scope | Depends on |
|------|------|-------|------------|
| 10-01 Data model and sign-in predicate | 1 | Migration (email nullable, flag, photo columns, CHECKs, `invitations.user_id`), `User::canSignIn()`/mail route, factory `child()`, `SecondFactorPolicy` exclusion, `staging:audit` + seeder purge patch (without seeding the child yet), tests `UserEmailNullableTest` | — |
| 10-02 Sign-in lockout on every route | 2 | `authenticateUsing`, 2FA guard, passkey `pending()`, Google resolve, reset notification + action, `BlockDisabledSignIn`, recipient exclusions (reminders, occasions, vaccination), `SignInArchTest`, `ChildSignInBlockedTest`, `ChildNoMailTest` | 10-01 |
| 10-03 Profile photo backend | 2 (parallel with 10-02, disjoint files) | `ProfilePhotoStore`, `ProfileAccess`/`ProfilePhotoAccess`, photo controller + routes + request, `NoStoreDynamicResponses` allowlist, sweeper exclusion + profile sweeper, nginx location, config, `AvatarVersions` + shared `avatars` prop, audit event `ProfileChangedByOther`, endpoint matrix rows, tests incl. cache headers | 10-01 |
| 10-04 Profile page and account menu (#7 UI) | 3 | `ProfileController` (name), `Pages/Profile/Edit.vue`, `ProfilePhotoEditor`, `UiAvatar` (`userId`, `src`, size 96, error fallback), `UiMenu` avatar trigger, `AccountMenu` item, lang keys, help `profile.*`, manual `profile.json`, vitest + a11y sweep registration, `ManualCoverage` FEATURES | 10-03 |
| 10-05 Child creation and parent/admin editing | 3 (parallel with 10-04 except shared lang files, coordinate) | `WishlistManagerSync` extraction, `CreateChild` action + `ChildrenController` + request, `access:admin`+`recent` + `accessGranting()`, `ChildCreated` audit, `MembersController` payload, `MemberRow`/`MemberPanel`/`ChildCreateDialog`, `/profile/people/{user}` + "People you manage", Wishlist `ChildListTest`, help `members.child`, manual `admin.json`/`wishlist.json`, lang | 10-02, 10-03 (photo endpoints) |
| 10-06 Convert child to member | 4 | `InviteService` conversion branch, `InvitationsController`/request, Members panel card, `ChildConverted` audit, tests `ChildConversionTest`, manual/help/lang | 10-05 |
| 10-07 Avatars everywhere | 4 (parallel with 10-06) | Pass `userId` at all call sites, `PersonPage` `photoUserId`, `HealthAccessCard`, decision on `PersonSwitcher`, vitest per caller, a11y | 10-04 |
| 10-08 Staging seed, docs and verification | 5 | Seed synthetic child + synthetic photo, `staging:audit` coverage test, `docs/ops/*`, `i18n-phase10.test.js`, full-suite run, manual/help coverage run, mark sk/pl drafts, staging browser verification on synthetic data, review | all |

Wave conflicts to watch: `lang/*.json`, `resources/help/*`, `resources/manual/*` are edited by several plans; assign each plan a distinct key namespace (`profile.*`, `settings.members.child.*`, `settings.members.convert.*`) and keep edits additive to avoid merge conflicts, or serialise 10-04/10-05/10-06 on those files.

## Risks to Existing Behaviour

- **E-mail nullable migration on populated production table:** low risk, instant metadata change; the deploy already takes a `pre-migrate` restic snapshot. Rollback story: `down()` refuses while NULL e-mails exist. Every reader of `users.email` was inventoried (Section B); the only silent failures are the NULL-comparison cases in `staging:audit` and the staging seeder (staging only).
- **Uniqueness semantics:** PostgreSQL treats NULLs as distinct, so multiple children are fine and the existing unique indexes are untouched; the functional index `lower(email)` also ignores NULL. The `CHECK (email <> '')` closes the empty-string hole. Case-insensitive conflicts on conversion are already guarded by the application check plus the index (a race yields a unique violation that `accept()` must map to the "already belongs to a member" field error rather than a 500; wrap in try/catch of `UniqueConstraintViolationException`).
- **Shared `ImageProcessor` and sweeper:** excluded prefix change in `sweepOrphans` must keep wish orphan cleanup working (keep existing `PruneWishlistTest` green).
- **`NoStoreDynamicResponses` allowlist** change must not weaken other routes (test `CacheHeadersTest`).
- **`UiAvatar` / `UiMenu` signature changes** touch ~10 components and ~40 tests; keep new props optional and defensive.
- **Seed/test counts:** `StagingSeedTest` pins `User::count() === 7`; adding the synthetic child changes it (update deliberately, in 10-08).
- **Session path regression:** the new middleware runs on every request; it must be cheap (uses the already-loaded user) and must not break guest requests (`$request->user()` null).
- **Fortify redirect for authenticated "guest" routes:** unchanged.

## Sources

### Primary (HIGH confidence): this repository (read in this session)
- `app/Models/User.php`, `app/Providers/FortifyServiceProvider.php`, `app/Auth/{InviteService,GoogleLoginService,RedirectIfSecondFactor}.php`, `app/Http/Controllers/Auth/{GoogleController,InviteAcceptController}.php`, `app/Http/Controllers/Security/{ChallengePasskeyController,ReauthPasskeyController}.php`, `app/Http/Middleware/{GuardTwoFactorRoutes,EnsureRouteDeclaresAccess,RequireAccess,NoStoreDynamicResponses,HandleInertiaRequests}.php`, `bootstrap/app.php`, `config/{fortify,filesystems,wishlist}.php`
- `app/Wishlist/{WishAccess,ViewerContext,WishlistMembers}.php`, `app/Wishlist/Catalog/{ImageProcessor,WishImageStore,ProcessedImage}.php`, `app/Http/Controllers/Wishlist/Owner/WishImageController.php`, `app/Http/Controllers/Settings/{MembersController,WishlistManagersController,InvitationsController}.php`, `app/Access/*`, `app/Audit/*`, `app/Console/Commands/{StagingAudit,PruneWishlist}.php`, `database/seeders/StagingSeeder.php`
- `database/migrations/*users*`, `*invitations*`; `resources/js/{Shell,Components/ui,Components/Settings,Wishlist,Todo,Health}/*`; `routes/web/*`; `docker/nginx/snippets/laravel-locations.conf`; `compose.yaml`, `compose.server.yaml`, `scripts/{check-compose,backup}.sh`, `docs/ops/server.md`
- `tests/Pest.php`, `tests/Feature/Access/EndpointMatrixTest.php`, `tests/Support/{EndpointMatrix,Roles,SensitiveRoutes,ImageFixtures}.php`, `tests/Unit/Arch/*`, `tests/js/**`; `.planning/v1.0-ISSUE-3-NOTES.md`, `.planning/v1.0-ISSUE-4-NOTES.md`
- `vendor/laravel/framework/src/Illuminate/Notifications/Channels/MailChannel.php` (empty route returns silently)

### Secondary (MEDIUM confidence)
- PostgreSQL documentation on unique indexes and NULLs (`NULLS DISTINCT` default) [CITED: postgresql.org/docs/current/indexes-unique.html; not re-fetched this session, behaviour well known]

### Tertiary (LOW confidence)
- None.

## Metadata

**Confidence breakdown:**
- Standard stack: HIGH, nothing new, all from the repo.
- Architecture: HIGH for sign-in paths, storage trap, sweeper, nginx, staging audit (each read in code); MEDIUM for UI mechanics (`avatars` prop, `UiMenu` avatar) which are design proposals.
- Pitfalls: HIGH, each traced to a file.

**Research date:** 2026-11-17
**Valid until:** 2026-12-17 (repo-internal facts; re-check if Phases 11-13 land first, since they also touch `lang/*`, `resources/help/*`, `resources/manual/*`)
