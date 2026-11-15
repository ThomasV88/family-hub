# Phase 3: To-Do - Research

**Researched:** 2026-11-13
**Domain:** Laravel 13 + Inertia 3/Vue task module, recurrence engine, notification outbox on Postgres, Web Push (VAPID) in PHP
**Confidence:** MEDIUM (versions verified on Packagist; library DST/clamp behaviour and iOS push details are `[ASSUMED]` until the Wave 0 spike tests prove them)

<user_constraints>
## User Constraints (from CONTEXT.md)

### Locked Decisions
**Tasks, assignment, visibility**
- Fields: title, notes, due date (optional date or date+time), status To do / In progress / Done, one optional assignee (defaults to creator), creator.
- Visibility per task: Personal (creator only, hidden even from admins), Family (everyone with todo access), or Selected members (chosen list).
- Edit rights: needs todo `edit` grant; creator and assignee can edit; view-only members can read but not change. All checks go through the Phase 2 `requireAccess` policy layer plus record-level query scopes, with tests in the endpoint matrix.

**Recurrence**
- Patterns: daily, weekly (chosen weekdays), monthly (day of month or last day), custom interval (every N days/weeks/months); stored as RRULE strings.
- Per task: recur from schedule (fixed by rule) or from completion (next date counted from completion).
- Month-end clamps to the last day of shorter months. Due times are stored with the family time zone (default Europe/Bratislava) so local times survive DST changes.
- Completing a recurring task creates the next occurrence and keeps completion history; a single occurrence can be skipped.
- The recurrence engine is a reusable service (Finance recurring expenses in Phase 5 use it).

**Reminders**
- Default reminder at due time (08:00 for date-only tasks); optional per-task "remind me before" (15 min, 1 hour, 1 day).
- Channels: email (Stalwart SMTP via database queue) and Web Push (VAPID); each member chooses channels in settings. iOS push works only for the installed PWA, with an explanatory hint in the UI.
- A scheduler command finds due reminders and writes them to a notification outbox table; the worker sends with retry and dedupe. The outbox is reusable (Phase 4 wishlist occasions).
- Push text is a short title only ("Task due: ..."), never notes, to avoid lock-screen leaks.

### Claude's Discretion
Table and class names, push library choice (verify packagist: e.g. a maintained Web Push PHP package, package legitimacy checkpoint required), UI details following the Phase 01.1 design system and sketch 004, test organisation.

### Deferred Ideas (OUT OF SCOPE)
Task priority/labels, multiple assignees, wishlist occasion reminders (Phase 4 reuses the outbox).
</user_constraints>

<phase_requirements>
## Phase Requirements

| ID | Description | Research Support |
|----|-------------|------------------|
| TODO-01 | Create a task with title, notes, due date, status | `tasks` table, FormRequest validation, quick add (title only) + detail panel, Inertia controllers under `access:module,todo,*` |
| TODO-02 | Assign a task to a member | `assignee_id`, validated against members with todo >= view; board columns per assignee |
| TODO-03 | Personal vs shared (family / chosen) | `visibility` enum + `task_members`; `Task::visibleTo()` scope; invisible = 404; no admin bypass |
| TODO-04 | Recurring tasks from schedule or completion | `RecurrenceEngine` (rlanvin/php-rrule inside), one open occurrence per series, `recurrence_mode` |
| TODO-05 | Month-end and DST correct | BYSETPOS clamp trick, wall-clock expansion in the stored time zone, fixed-clock Pest tests across 2027 transitions and Feb |
| TODO-06 | Email + push reminders (iOS installed only) | `notification_outbox`, `reminders:enqueue` scheduler, `SendOutboxItem` job, minishlink/web-push, SW `push` handler |
</phase_requirements>

## Summary

Phase 3 is mostly assembly on top of what exists: the Phase 2 policy layer (`AccessGate`, `access:` middleware, `EndpointMatrix` harness with route files auto-globbed from `routes/web/*.php`), the database queue with `worker` and `scheduler` containers, the localized-notification pattern (`InviteNotification`), and the Phase 01.1 `Ui*` components. Two external packages are needed: a recurrence library and a Web Push library. Everything else (outbox, visibility scopes, preferences) is plain Eloquent/Postgres.

The riskiest parts are semantic, not library-shaped. (1) Month-end clamping contradicts RFC 5545 (BYMONTHDAY=31 skips short months); it is expressible in pure RRULE with `BYMONTHDAY=28,29,30,31;BYSETPOS=-1`-style sets, and the target day must live in the rule, never be derived from the previous (already clamped) due date. (2) DST: store local wall-clock (date, time, IANA zone) as truth and derive the UTC instant; never add 24h to a UTC timestamp. (3) Reminders must be idempotent: a unique dedupe key on the outbox plus a `remind_at` column that the scheduler consumes under `FOR UPDATE SKIP LOCKED`. (4) Record-level privacy: Personal tasks must be invisible to admins and return 404, enforced by one query scope used by every read and write path, with matrix and record-level tests.

Web Push in PHP is mature: `minishlink/web-push` v11.0.0 (2026-07-23, PHP >=8.2, PSR-18 based). The service worker already guards against runtime caching by token scan; `push`, `notificationclick` and `pushsubscriptionchange` handlers use none of the forbidden tokens, so they fit the guard unchanged. iOS delivers push only to a Home Screen web app (iOS/iPadOS 16.4+), the permission request must come from a user gesture, and every push must display a notification.

**Primary recommendation:** Build a domain-agnostic `App\Recurrence\RecurrenceEngine` (wraps `rlanvin/php-rrule` ^3.0, one open occurrence per series) and a generic `notification_outbox` + `SendOutboxItem` job; add `minishlink/web-push` ^11 for push; gate both composer installs behind a `checkpoint:human-verify` because slopcheck could not run.

## Project Constraints (from CLAUDE.md)

- Stack is Laravel/Inertia/Vue/Postgres; database queue (no Redis) with separate `worker`, `scheduler`; custom policy layer (no Spatie); Laravel localization + vue-i18n; Pest + Vitest. Ignore Node/Next recommendations in CLAUDE.md PART 2.
- Privacy: per-module permissions enforced server-side. SK/PL/EN from the start. Phone/desktop/iPad responsive.
- Service worker must stay precache-only (FOUND-03); `scripts/check-sw-precache.mjs` forbids `registerRoute`, `runtimeCaching`, `NetworkFirst`, `StaleWhileRevalidate`, `CacheFirst`, `cache.put`, `caches.open`.
- Global rules: secrets (`.env`, `app.env`, `secrets/`) never printed or committed; tests only on staging; production only via PR to `main`; no restart of other stacks.
- File-changing work goes through GSD commands. Follow existing patterns: `NoStoreDynamicResponses`, `lang/{en,sk,pl}.json` (+ `lang/*/mail.php`), Inertia pages in `resources/js/Pages`, per-feature route files in `routes/web/`, matrix files in `tests/Support/EndpointMatrix/`.
- No new AI/per-token concerns this phase.

## Architectural Responsibility Map

| Capability | Primary Tier | Secondary Tier | Rationale |
|------------|-------------|----------------|-----------|
| Task CRUD, status, assign | API / Backend (controllers + FormRequests) | Browser (forms) | Server is the trust boundary; Vue hides nothing sensitive |
| Visibility (Personal/Family/Selected) | Database (EXISTS predicates in one scope) | API / Backend | Must hold for every query path incl. reminders; 404 for invisible |
| Permission checks | API / Backend (`access:` middleware + `AccessGate`) | — | Phase 2 pattern; Vue nav hiding is cosmetic |
| Recurrence expansion | API / Backend (`RecurrenceEngine`) | — | Single reusable service; client only displays text |
| Time-zone/DST due computation | API / Backend (Carbon in stored IANA zone) | Database (UTC `remind_at`) | Wall-clock is truth, UTC instant derived |
| Reminder detection | Scheduler container (`reminders:enqueue`) | Database (`remind_at` index, `SKIP LOCKED`) | Cron-like, idempotent |
| Reminder delivery | Worker container (`SendOutboxItem`) | SMTP (Stalwart), push services | Retries/backoff, keeps requests fast |
| Push subscribe / permission prompt | Browser (Push API, user gesture) | API (store subscription) | Only the browser can create a subscription |
| Push display and click | Service worker (`push`, `notificationclick`) | — | Must show a notification on every push (iOS) |
| Channel preferences | API / Backend + settings page | Database | Per-user, per-channel rows |

## Standard Stack

### Core
| Library | Version | Purpose | Why Standard |
|---------|---------|---------|--------------|
| rlanvin/php-rrule | ^3.0 (v3.0.0, 2026-07-29; v2.6.0 2025-04-25) | RFC 5545 RRULE parse/expand inside `RecurrenceEngine` | Requires only PHP >=7.3 (fits the ^8.3 constraint), 700+ stars, active release in 2026 [VERIFIED: repo.packagist.org p2 API]. Supports BYSETPOS, BYMONTHDAY negative values, DTSTART with timezone `[ASSUMED, confirm in spike; README fetch did not document it]` |
| minishlink/web-push | ^11.0 (v11.0.0, 2026-07-23; v10.1.0 2026-05-28) | VAPID Web Push sending | De-facto PHP Web Push library, repo github.com/web-push-libs/web-push-php; PHP >=8.2, ext curl/json/mbstring/openssl; v11 uses PSR-18 (auto-discovers Guzzle, which Laravel already ships) [VERIFIED: packagist p2 + GitHub releases] |
| laravel/framework (existing) | ^13.17 | Scheduler, database queue, Notifications/Mail, Carbon | Already installed |

### Supporting
| Library | Version | Purpose | When to Use |
|---------|---------|---------|-------------|
| (none new on JS side) | — | Push subscribe code is ~40 lines against `navigator.serviceWorker`/`PushManager`; `workbox-*` already present | Do not add a push JS package |
| vue-i18n, @lucide/vue (existing) | installed | Strings, icons (repeat, lock, bell) | UI |

### Alternatives Considered
| Instead of | Could Use | Tradeoff |
|------------|-----------|----------|
| rlanvin/php-rrule | simshaun/recurr v6.0.0 (2025-10-04) | Requires PHP >=8.4 but `composer.json` says ^8.3 and the host dev PHP is 8.3.6 (Docker image is 8.4); rejected |
| rlanvin/php-rrule | Hand-rolled Carbon logic (limited pattern set) | Viable fallback if the spike shows DST/clamp problems; keep the `RecurrenceEngine` interface so the swap is internal |
| laravel-notification-channels/webpush | minishlink directly | Extra abstraction/package; outbox already is the channel layer. Do not add |
| Drag-and-drop lib (vuedraggable/sortablejs) | Status via checkbox + status select in detail panel | CONTEXT/sketch 004 do not require drag; native HTML5 DnD is unusable on iOS touch; defer drag |

**Installation:**
```bash
composer require rlanvin/php-rrule:^3.0 minishlink/web-push:^11.0   # inside the app container
```

**Version verification:** 2026-11-13 via `repo.packagist.org/p2/<pkg>.json`: rlanvin/php-rrule v3.0.0, minishlink/web-push v11.0.0, simshaun/recurr v6.0.0 (PHP >=8.4, rejected).

## Package Legitimacy Audit

slopcheck could not be installed (pip install was blocked by the sandbox in this session), so per protocol every package is `[ASSUMED]`. Both are long-standing, widely used PHP libraries with the expected source repos confirmed on Packagist. Planner: insert one `checkpoint:human-verify` before the first `composer require`, including `composer show` of transitive deps and a check for `scripts` in each package's `composer.json`.

| Package | Registry | Age | Downloads | Source Repo | slopcheck | Disposition |
|---------|----------|-----|-----------|-------------|-----------|-------------|
| rlanvin/php-rrule | Packagist | many years (v2.5.1 2024) | high | github.com/rlanvin/php-rrule | not run | Approved pending human check [ASSUMED] |
| minishlink/web-push | Packagist | many years (v9 → v11) | high | github.com/web-push-libs/web-push-php | not run | Approved pending human check [ASSUMED] |
| web-token/jwt-library, php-http/discovery (transitive of web-push) | Packagist | years | high | spomky-labs / php-http orgs | not run | Transitive [ASSUMED] |

**Packages removed:** none. **Flagged [SUS]:** none (tool unavailable).

## Architecture Patterns

### System Architecture Diagram

```
Browser (Todo page / QuickAdd / Settings > Notifications)
  | Inertia visits (POST/PATCH/DELETE)           | pushManager.subscribe (user gesture) -> POST /settings/notifications/push
  v                                               v
nginx -> php-fpm: web group -> access:module,todo,{view|edit} -> TaskController / TaskCompletionController
              |  Task::visibleTo($user) scope on EVERY query (404 when not visible)
              |  on create/update: TaskScheduler::recompute(task)  -> remind_at (UTC) from local date/time/zone/offset
              |  on complete: tx + lock row -> RecurrenceEngine::next() -> insert next open occurrence
              v
        Postgres: tasks, task_members, notification_preferences, push_subscriptions, notification_outbox
              ^                                             |
Scheduler container: reminders:enqueue (every minute) --- SELECT tasks WHERE remind_at<=now() AND open
   FOR UPDATE SKIP LOCKED -> recipients(assignee|creator, re-check visibility+access, prefs)
   -> INSERT outbox rows (unique dedupe_key, ON CONFLICT DO NOTHING), clear remind_at (tx)
Scheduler: outbox:dispatch (every minute) -> pending rows with available_at<=now -> dispatch SendOutboxItem
              v
Worker container (database queue): SendOutboxItem(id)
   claim row (UPDATE ... WHERE status='pending') -> re-check task still open/visible/access
   channel mail: Mail::to(user)->locale(user.locale) -> Stalwart SMTP
   channel push: WebPush::sendOneNotification per subscription; 404/410 => delete subscription
   success -> sent; transient fail -> release with backoff; final fail -> failed
Push service (FCM/Mozilla/APNs) -> device -> sw.js `push` => showNotification(title only) ; `notificationclick` => focus/open /todo?task=ID
```

### Recommended Project Structure
```
app/
├── Recurrence/        # RecurrenceRule (VO), RecurrenceMode enum, RecurrenceEngine, RuleBuilder (UI fields <-> RRULE string)
├── Tasks/             # TaskStatus, TaskVisibility enums; TaskService (create/update/complete/skip), ReminderTimeCalculator
├── Notifications/Outbox/   # OutboxItem model, OutboxWriter (idempotent insert), SendOutboxItem job, channels/MailSender, PushSender
├── Models/            # Task, TaskMember(pivot), NotificationPreference, PushSubscription, OutboxItem
├── Http/Controllers/  # TaskController, TaskStatusController, TaskSkipController, Settings/NotificationController, PushSubscriptionController
├── Console/Commands/  # EnqueueReminders (reminders:enqueue), DispatchOutbox (outbox:dispatch), PushVapidKeys (push:vapid)
routes/web/todo.php, routes/web/notifications.php
tests/Support/EndpointMatrix/todo.php, notifications.php
resources/js/Pages/Todo/Index.vue; resources/js/Todo/{TaskColumn,TaskCard,TaskDetailPanel,RecurrenceField,useQuickAdd}.js|vue
resources/js/push.js (subscribe/unsubscribe helpers); resources/js/sw.js (push handlers)
lang/{en,sk,pl}.json (+ keys for UI), lang/*/mail.php (reminder mail), lang/*/notifications.php (push title)
```

### Data model (Postgres)

`tasks`: `id`, `series_id uuid null`, `title varchar(200)`, `notes text null`, `status` (todo|in_progress|done), `visibility` (personal|family|selected), `creator_id`, `assignee_id null`, `due_date date null`, `due_time time null`, `timezone varchar(64)` (copied from `config('family.timezone')` at creation), `remind_before_minutes int default 0`, `remind_at timestamptz null` (computed UTC, index), `recurrence_rule text null` (RRULE string incl. DTSTART-free rule only), `recurrence_mode` (schedule|completion) null, `completed_at timestamptz null`, `completed_by null`, `skipped_at timestamptz null`, timestamps. Use FK `ON DELETE` appropriate (creator restrict, assignee set null).
Indexes: `(assignee_id, status)`, `(creator_id)`, `(visibility)`, partial `(remind_at) WHERE remind_at IS NOT NULL`, **partial unique `(series_id) WHERE series_id IS NOT NULL AND completed_at IS NULL AND skipped_at IS NULL`** (one open occurrence per series; makes double-complete race-safe).
`task_members(task_id, user_id)` unique pair, index `user_id`. Rows only for `selected`.
`notification_preferences(user_id, channel, enabled)` unique `(user_id, channel)`; absent row = default (email on, push off).
`push_subscriptions`: `user_id`, `endpoint text`, `endpoint_hash char(64) unique`, `p256dh` and `auth` with Laravel `encrypted` cast, `content_encoding`, `label` (via existing `UserAgentLabel`), `last_success_at`, timestamps.
`notification_outbox`: `id`, `kind varchar` (`task_reminder`; Phase 4 adds `wish_occasion`), `user_id`, `channel` (mail|push), `subject_type`/`subject_id` (morph or plain pair), `dedupe_key varchar unique`, `payload jsonb` (ids and an occurrence timestamp only, never notes/titles; text is rendered at send time), `status` (pending|sending|sent|failed|skipped), `attempts smallint`, `available_at timestamptz`, `sent_at`, `last_error varchar(255)` (class name/short message, no content), timestamps. Index `(status, available_at)`.

### Pattern 1: Recurrence engine (reusable, domain-agnostic)
**What:** `RecurrenceEngine::next(RecurrenceRule $rule, CarbonImmutable $anchorLocal, RecurrenceMode $mode, ?CarbonImmutable $completedAtLocal, string $tz, CarbonImmutable $now): ?CarbonImmutable`. No Task dependency, so Finance can pass bills.
- Persist the RRULE string without DTSTART (e.g. `FREQ=MONTHLY;INTERVAL=1;BYMONTHDAY=28,29,30,31;BYSETPOS=-1`); DTSTART is supplied per call as the local wall-clock `DateTime` in the stored zone.
- `RuleBuilder` maps UI fields to RRULE and parses back (daily, weekly + `BYDAY`, monthly day N or last day, custom `INTERVAL`). Reject anything else on write (allowlist), validate length.
- **Clamp via RRULE:** monthly day N<=28: `BYMONTHDAY=N`. N in 29..31: `BYMONTHDAY=<28..N>;BYSETPOS=-1` (set 28,29,30,31 for N=31; 28,29,30 for N=30; 28,29 for N=29) picks the largest existing day not above N. "Last day": `BYMONTHDAY=-1`. `[ASSUMED: BYSETPOS applies to the BYMONTHDAY expansion within each month in php-rrule; prove with test]`
- **From schedule:** next = first occurrence strictly after the completed occurrence's scheduled local due AND after `now` (no pile-up of overdue copies; completing a 3-day-late daily task yields tomorrow, not yesterday).
- **From completion:** build the same rule with `DTSTART = completion local date + the original local time`; next = first occurrence after completion. For weekly/monthly the rule's BYDAY/BYMONTHDAY still apply, for pure interval rules (`FREQ=DAILY;INTERVAL=3`) it is completion + 3 days.
- Skip = same computation as complete but marks `skipped_at` instead of `completed_at` (history keeps both).
- Hard cap iteration (e.g. look-ahead 5 years / 1000 occurrences) so a malformed rule cannot hang a request.

### Pattern 2: DST-safe local times
Store `due_date`, `due_time`, `timezone`. Compute `CarbonImmutable::parse("$date $time", $tz)` then `->utc()` only for `remind_at`. Nonexistent local time (2027-03-28 02:30 Europe/Bratislava) resolves forward to 03:30 for that day only and the next occurrence returns to 02:30 because the engine iterates wall-clock fields; ambiguous time (2027-10-31 02:30) resolves to the first (summer) instant. Date-only tasks: reminder wall-clock 08:00 in the zone. `app.timezone` stays UTC; add `config/family.php` (`'timezone' => env('FAMILY_TIMEZONE','Europe/Bratislava')`). 2027 transitions: last Sunday of March = 2027-03-28, last Sunday of October = 2027-10-31 `[ASSUMED, EU rule; assert in the test via Carbon offsets]`.

### Pattern 3: Reminder scan and outbox (idempotent)
```php
// reminders:enqueue, every minute, withoutOverlapping, safe to run twice
DB::transaction(function () {
    $tasks = Task::query()->whereNotNull('remind_at')->where('remind_at', '<=', now())
        ->whereNull('completed_at')->whereNull('skipped_at')
        ->orderBy('remind_at')->limit(200)->lockForUpdate()->skipLocked()->get();   // Postgres FOR UPDATE SKIP LOCKED
    foreach ($tasks as $task) {
        foreach ($recipients->for($task) as [$user, $channel]) {
            OutboxItem::query()->insertOrIgnore([                      // ON CONFLICT DO NOTHING on dedupe_key
                'kind' => 'task_reminder', 'user_id' => $user->id, 'channel' => $channel,
                'dedupe_key' => "task_reminder:{$task->id}:{$task->remind_at->timestamp}:{$user->id}:{$channel}",
                'payload' => json_encode(['task_id' => $task->id]), 'status' => 'pending', 'available_at' => now(), ...
            ]);
        }
        $task->forceFill(['remind_at' => null])->save();               // consumed
    }
});
```
- Recipient = assignee, or creator if unassigned. Personal task: creator only. Filter by `notification_preferences` and (push) existing subscriptions. Re-check `AccessGate` todo >= view and `visibleTo` at enqueue AND at send (revoked access or completed task => `skipped`).
- A task edited after the reminder fired (due moved) gets a new `remind_at`, hence a new dedupe key, hence a new reminder. Marking done or deleting clears `remind_at`.
- `outbox:dispatch` (every minute) selects `pending` with `available_at <= now()` ordered, dispatches `SendOutboxItem($id)`; also resets rows stuck in `sending` for > 10 min to `pending`. `SendOutboxItem`: `$tries = 5`, `backoff() = [60, 300, 900, 3600]`; first statement atomically claims (`UPDATE ... SET status='sending', attempts=attempts+1 WHERE id=? AND status='pending'`, abort if 0 rows) so duplicate dispatches never double-send.
- Mail: a `TaskReminderMail` (Mailable, not queued itself, since the job is already on the queue) with `->locale($user->locale)` and `trans(..., [], $locale)` like `InviteNotification`. Push: title only via `trans('notifications.task_due', ['title' => $task->title], $locale)`; no notes.
- Reuse for Phase 4: `kind` + `payload` + generic recipient resolution closure per kind; `SendOutboxItem` dispatches by `kind` to a renderer class.

### Pattern 4: Web Push
```php
// Source: github.com/web-push-libs/web-push-php (README, v11) [CITED]; confirm signatures in the spike [ASSUMED]
$webPush = new WebPush(['VAPID' => [
    'subject' => config('push.subject'),                 // mailto:...
    'publicKey' => config('push.public_key'),
    'privateKey' => config('push.private_key'),
]]);
$report = $webPush->sendOneNotification(
    Subscription::create(['endpoint' => $s->endpoint, 'publicKey' => $s->p256dh, 'authToken' => $s->auth, 'contentEncoding' => 'aes128gcm']),
    json_encode(['title' => $title, 'url' => '/todo?task='.$task->id, 'tag' => $dedupeKey]),
    ['TTL' => 3600, 'urgency' => 'normal'],
);
if ($report->isSubscriptionExpired()) { $s->delete(); }     // 404/410
elseif (! $report->isSuccess()) { throw new RuntimeException('push failed'); }   // retry
```
- VAPID keys: generate once with a console command wrapping `VAPID::createVapidKeys()`; print instructions to put `VAPID_PUBLIC_KEY`, `VAPID_PRIVATE_KEY`, `VAPID_SUBJECT` into server `app.env` (which feeds app, worker, scheduler), never into git, never log the private key. Separate keys per environment (staging vs prod). Public key is exposed to the browser through an Inertia shared prop or a settings page prop. Add the three names to `.env.example` as empty placeholders. Rotating keys invalidates all subscriptions (browsers bind subscriptions to the key): UI must offer re-subscribe.
- Payload tiny (limit is ~4 KB encrypted, ~3993 bytes plaintext `[ASSUMED]`); title truncated to ~80 chars.
- Service worker additions (compatible with the precache guard, none of the forbidden tokens):
```js
self.addEventListener('push', (event) => {
    let data = {};
    try { data = event.data ? event.data.json() : {}; } catch { /* fall through to generic title */ }
    event.waitUntil(self.registration.showNotification(data.title || 'Family Hub', {
        tag: data.tag, data: { url: typeof data.url === 'string' && data.url.startsWith('/') ? data.url : '/' },
        icon: '/icons/pwa-192x192.png',
    }));   // iOS/Safari require a visible notification for every push (no silent push)
});
self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    const url = event.notification.data?.url || '/';
    event.waitUntil(self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((list) => {
        const open = list.find((c) => 'focus' in c);
        return open ? open.navigate(url).then((c) => c && c.focus()) : self.clients.openWindow(url);
    }));
});
```
Extend `tests/js/sw-precache.test.js` to assert the real `resources/js/sw.js` still passes `checkPrecache` and contains `push` and `notificationclick` listeners. `injectManifest` already reads `resources/js/sw.js`, so no vite config change.
- Subscribe flow (`resources/js/push.js`): feature-detect (`'serviceWorker' in navigator && 'PushManager' in window`), call `Notification.requestPermission()` only inside a click handler, `registration.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey })`, POST `subscription.toJSON()` to the API (CSRF via Inertia/axios-like fetch with the XSRF header). Unsubscribe removes both browser and server record.
- iOS UI hint: detect `navigator.standalone`/`matchMedia('(display-mode: standalone)')`; when on iOS Safari non-installed, show "Add to Home Screen to enable push" and disable the toggle. iOS 16.4+ required [CITED: webkit.org/blog/13878]. The manifest already has `display: standalone`.
- `Notifications` settings page: email toggle, push toggle (with per-device list and "send test" optional), iOS hint. Add route to `SettingsSections` and matrix.

### Pattern 5: Record-level visibility on top of Phase 2
```php
// Task::scopeVisibleTo(Builder $q, User $u)
$q->where(fn ($q) => $q
    ->where('creator_id', $u->id)
    ->orWhere('visibility', 'family')
    ->orWhere(fn ($q) => $q->where('visibility', 'selected')
        ->whereExists(fn ($s) => $s->select(DB::raw(1))->from('task_members')
            ->whereColumn('task_members.task_id', 'tasks.id')->where('task_members.user_id', $u->id))));
```
- Route middleware: list/show `access:module,todo,view`; create/update/status/skip/delete `access:module,todo,edit`. Then **every** controller fetches via `Task::visibleTo($user)->findOrFail($id)` (invisible = 404, not 403, so existence is not leaked). Add a `TaskPolicy`-style helper `TaskAccess::canEdit($user,$task)` (todo edit AND visible); delete and visibility change only by creator `[ASSUMED interpretation, see Open Questions]`.
- No admin bypass: do not reference `is_admin` anywhere in task code; a Pest test asserts an admin with no grants gets 403 and an admin with todo edit still gets 404 for another user's Personal task.
- Invariants validated server side: Personal => `assignee_id` is creator or null; Selected => assignee and creator auto-added to `task_members`; assignee must hold todo >= view (query `module_permissions`); `selected` member ids must hold todo >= view. Changing visibility away from `selected` deletes pivot rows. Changing assignee recomputes recipients on next `remind_at` only.
- N+1: board query = `Task::visibleTo($u)->with(['assignee:id,name', 'members:id'])->where(open OR completed_at > now()-30d)` ; done tasks capped (30 days, `limit`), history for a series loaded lazily on panel open.
- Inertia props contain only what the viewer may see: do not share member emails; pass `{id,name}` of users that hold todo access.

### Pattern 6: Extending the endpoint matrix
Add `tests/Support/EndpointMatrix/todo.php` and `notifications.php`. Every new route must be named and carry an `access:` rule, otherwise the Phase 2 "registers every route" and "declares an access rule" tests fail (that is the intended gate). Expected statuses per role: `guest` 302; read routes 200 for parent/child/grandparent/guestPreset (todo view), 403 for `admin` (no grants) and `noGrants`; mutating routes 302/200 for parent/child/grandparent, 403 for guestPreset/admin/noGrants. Record routes use `params` closures with a Family task created for the test. Separate Pest file `tests/Feature/Todo/VisibilityTest.php` covers record-level rules (matrix is route-level only): 404 on others' Personal, Selected membership, admin no-bypass, creator-only delete, mass-assignment (`creator_id`, `status` bogus values rejected).

### Quick add and UI constraints (existing components)
- `QuickAdd.vue` currently clears input and opens the coming-soon panel; replace with `router.post('/todo/tasks', {title}, {preserveScroll:true, onSuccess: clear})`. Keep `event.isComposing` guard, `enterkeyhint="done"`, registration with `shell.registerAddHandler`. Quick add creates a task: Family visibility `[ASSUMED default]`, assignee = creator, no due date. Optional light parsing is out of scope. Disable submit for users with only view (hide FabAdd/QuickAdd per `auth.access.todo`).
- Update `resources/js/Shell/modules.js`: `tasks` `href: '/todo'`. Replace the Home dashboard To-do card placeholder with real "my open tasks today/overdue" (query visibleTo, limit 5).
- Board (sketch 004 B): column per member plus an "unassigned" column; filter chips All/Mine/Shared/Personal; phone shows the current user's column first; container-query grid 3/2/1 columns; `UiDetailPanel` docked >=900px, overlay on iPad, bottom sheet on phone (modes already implemented, injected via `fh-panel-mode`). Use `UiCheckbox`, `UiTag`, `UiChip`, `UiSelect`, `UiTextarea`, `UiSwitch`, `UiConfirmDialog`, `UiToastRegion`/`useToast`. Status changes via checkbox (done) and status select in the panel; **no drag** (see Alternatives).
- Recurrence field options per sketch: none, daily, weekly (+ weekday chips), monthly (day / last day), custom "every N days/weeks/months", plus a "repeat after completion" switch. Show human-readable text from lang keys (not library `humanReadable()`; it needs intl and English only).
- Vitest: add page tests to `tests/js/` plus the axe sweep entries (`a11y-sweep.test.js`) for the Todo page in sk/pl/en and light/dark; i18n parity test (`i18n-phase2.test.js` pattern) must include the new keys. SK/PL plural forms: use `trans_choice`/vue-i18n pipe plurals with 3 forms (SK/PL) in strings such as "3 tasks due"; `tests/Unit/TransChoiceTest.php` pattern exists.

### Anti-Patterns to Avoid
- **Adding `P1D`/24h to a UTC timestamp:** breaks DST. Always operate on local wall-clock then convert.
- **Deriving next monthly day from the previous clamped due date:** Jan 31 -> Feb 28 -> Mar 28. The target day stays in the rule.
- **Materialising many future occurrences:** one open occurrence per series is enough and keeps history clear.
- **Putting task titles/notes in `notification_outbox.payload`, logs or `last_error`:** only ids.
- **Checking `is_admin` for tasks or using `Gate::before`.**
- **Dispatching mail/push directly from the scheduler or from the request.**
- **Caching any task API response in the service worker** (precache-only guard stays).

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| RRULE parsing/expansion (BYDAY, INTERVAL, BYSETPOS, negative BYMONTHDAY) | Own parser/iterator | rlanvin/php-rrule inside `RecurrenceEngine` | Edge cases (leap years, interval alignment, weekday sets). Only the small RuleBuilder allowlist/mapper is ours |
| VAPID signing + aes128gcm payload encryption | Own JWT/ECDH/HKDF | minishlink/web-push | Cryptography; report object classifies 404/410 |
| Queue, retries, backoff | Cron + own retry table logic | Laravel database queue (`tries`, `backoff`) | Already running worker |
| Scheduling | Own loop | `Schedule::command(...)->everyMinute()->withoutOverlapping()` | Scheduler container exists |
| Localized mail rendering | Own templating | `Mailable` + `trans(..., $locale)` like InviteNotification | Pattern exists |
| Time-zone math | Manual offsets | Carbon `CarbonImmutable` with IANA zones | DST handled by tzdata |
| Permission layer | New ACL | Existing `AccessGate` + `access:` middleware | Phase 2 contract |
| Drag and drop | Custom HTML5 DnD | Not needed this phase | iOS touch unsupported |

**Key insight:** the only genuinely custom logic is the product semantics (clamp, from-completion, no pile-up, visibility, dedupe). Keep it in small, pure, heavily tested classes.

## Runtime State Inventory

Not a rename/refactor phase. New runtime state to provision (not code): VAPID keys in server `app.env` for staging and production (generated by the artisan command, never committed); `FAMILY_TIMEZONE` optional; no other external registration. None of the existing categories hold state to migrate: stored data none, live service config none, OS-registered state none, build artifacts: rebuild `public/sw.js` through `npm run build` (the SW source changes).

## Common Pitfalls

### Pitfall 1: Month-end drift and RFC skip
**What goes wrong:** "monthly on the 31st" skips Feb/Apr/Jun/Sep/Nov, or drifts to the 28th forever. **Why:** RFC semantics, or iterating from clamped dates. **Avoid:** BYSETPOS clamp sets, target day stored in rule. **Detect:** test Jan 31 2027 -> Feb 28 -> Mar 31 -> Apr 30; Jan 31 2028 -> Feb 29; day 30 and day 29 and last-day variants.

### Pitfall 2: DST
**What goes wrong:** reminder at 07:00 or 09:00 local after a transition; nonexistent 02:30 throws or shifts permanently. **Avoid:** wall-clock truth + zone column, assert both local time and UTC instant in tests across 2027-03-28 and 2027-10-31 (and 2026 dates), including `remind_before` crossing the transition.

### Pitfall 3: Double reminders / lost reminders
**What goes wrong:** scheduler overlap or retry duplicates sends; a crash between insert and clear loses or repeats. **Avoid:** single transaction for insert+clear, unique `dedupe_key`, atomic claim in the job, `withoutOverlapping`, stuck-`sending` recovery. **Detect:** test running `reminders:enqueue` twice yields one row per recipient/channel; job run twice sends once.

### Pitfall 4: Double-complete creates two next occurrences
**Avoid:** transaction + `lockForUpdate` on the task + partial unique index on open occurrence per series; completing an already completed task is a no-op 200/redirect.

### Pitfall 5: Push to expired subscriptions and silent-push on iOS
Delete on 404/410; always call `showNotification` in the `push` handler (iOS revokes subscriptions that do not display). Permission prompt only from a click. Push only works over HTTPS and (iOS) in the installed app.

### Pitfall 6: Personal-task leakage through side channels
Reminder mail/push for someone who lost access, dashboard counts, search, audit/log lines, `Inertia` shared props, error messages. **Avoid:** one scope, re-check at send, ids-only payloads, 404 on invisible, matrix + record tests, no title in logs.

### Pitfall 7: Worker/scheduler lack VAPID env or timezone
`app.env` feeds all three containers; staging and prod need separate keys. Verify in the compose healthcheck/smoke by a `push:vapid --check` (does not print the private key).

### Pitfall 8: `queue:work --tries=3` conflicts with job-level tries
Job `$tries`/`backoff()` override the worker flag; set both explicitly in the job class so behaviour is local and testable.

## Code Examples

### Clamp rule builder (monthly day N)
```php
// Source: RFC 5545 BYSETPOS semantics; verify with php-rrule in the spike [ASSUMED]
public static function monthlyDay(int $n, int $interval = 1): string
{
    $days = $n <= 28 ? [$n] : range(28, $n);
    $r = "FREQ=MONTHLY;INTERVAL={$interval};BYMONTHDAY=".implode(',', $days);
    return $n <= 28 ? $r : $r.';BYSETPOS=-1';
}
// last day: "FREQ=MONTHLY;INTERVAL=1;BYMONTHDAY=-1"
```

### Next occurrence from schedule
```php
$rrule = new RRule($rule->toString() + ['DTSTART' => $anchorLocal]);   // DateTime in the family zone
foreach ($rrule as $occ) {                                              // bounded: break after N iterations
    $local = CarbonImmutable::instance($occ)->setTimezone($tz);
    if ($local > $anchorLocal && $local > $nowLocal) return $local;
}
```

### Fixed-clock DST test (Pest)
```php
it('keeps 08:00 local across spring-forward', function () {
    Carbon::setTestNow(CarbonImmutable::parse('2027-03-26 12:00', 'Europe/Bratislava'));
    $next = engine()->next(rule('FREQ=WEEKLY;BYDAY=SU'), local('2027-03-21 08:00'), RecurrenceMode::Schedule, null, 'Europe/Bratislava', now());
    expect($next->format('Y-m-d H:i'))->toBe('2027-03-28 08:00')
        ->and($next->utc()->format('H:i'))->toBe('06:00');   // was 07:00 UTC before the change
});
```

### Scheduler registration (routes/console.php)
```php
Schedule::command('reminders:enqueue')->everyMinute()->withoutOverlapping()->name('reminders-enqueue');
Schedule::command('outbox:dispatch')->everyMinute()->withoutOverlapping()->name('outbox-dispatch');
Schedule::command('outbox:prune')->daily();   // delete sent/skipped rows older than 30 days
```

## State of the Art

| Old Approach | Current Approach | When Changed | Impact |
|--------------|------------------|--------------|--------|
| web-push depended on Guzzle directly | v11 uses PSR-18 + php-http/discovery (Guzzle auto-detected); `flushPooled` needs an HTTPlug async client | v11.0.0, 2026-07-23 | Use `sendOneNotification` (sequential) at family scale; no pooled sending |
| minishlink v9 `$timeout/$clientOptions` constructor args | removed in v11 | v11 | Do not copy old tutorials |
| iOS no web push | Home Screen web apps, iOS/iPadOS 16.4+ | 2023 | Installed PWA only (matches TODO-06) |

**Deprecated/outdated:** simshaun/recurr for this project (needs PHP 8.4, repo constraint 8.3); Laravel notification-channel wrappers for webpush (unneeded).

## Assumptions Log

| # | Claim | Section | Risk if Wrong |
|---|-------|---------|---------------|
| A1 | php-rrule v3 supports BYSETPOS with BYMONTHDAY and expands in the DTSTART timezone preserving wall-clock | Pattern 1/2 | Clamp/DST wrong; fallback to Carbon-based hand-rolled engine behind same interface |
| A2 | web-push v11 API names (`sendOneNotification`, `isSubscriptionExpired`, `Subscription::create`) unchanged from v10 | Pattern 4 | Minor code edits; confirm from vendor source after install |
| A3 | Encrypted payload limit ~4 KB; titles truncated to 80 chars is safe | Pattern 4 | Rejected pushes; trivially lowered |
| A4 | Overdue-from-schedule rule: next = first occurrence after both the completed due date and now (no pile-up) | Pattern 1 | Product behaviour mismatch; owner confirmation |
| A5 | "Remind me before" replaces (not adds to) the at-due reminder: one reminder at `due - offset` (offset 0 default) | Data model | Owner may expect two reminders |
| A6 | Edit interpretation: todo `edit` grant AND visible is required; any such member may edit fields/status of Family tasks; only the creator may delete or change visibility | Pattern 5 | Permission model mismatch |
| A7 | Quick add default visibility = Family, assignee = creator | UI | Privacy default may need to be Personal |
| A8 | Reminder recipient = assignee, else creator; Personal = creator only | Pattern 3 | Wrong audience |
| A9 | EU DST 2027 dates 03-28 and 10-31 | Pattern 2 | Test dates off by a week (test via offsets instead) |
| A10 | iOS push requires installed app, user gesture, always-visible notification | Pattern 4 | UI hint wrong; verified partially at WebKit blog (installed, gesture, 16.4); "no silent push" is training knowledge |
| A11 | slopcheck unavailable; both packages legitimate | Audit | Human checkpoint covers it |

## Open Questions (RESOLVED)

RESOLVED by orchestrator decisions: remind-before replaces the at-due reminder; recipient is the assignee (creator if unassigned, creator only for Personal); quick add defaults Family + creator as assignee; overdue recurrence yields the first occurrence after now; edit rights per CONTEXT plus status change by any member who can see the task and has an edit grant; ext-curl to be checked in the app image.

1. **Reminder offset semantics (A5)** — CONTEXT says default at due time plus optional "remind me before". Recommendation: single reminder at `due - offset`, offset 0 default; confirm with owner if two reminders are wanted.
2. **Who may edit Family tasks (A6)** — recommendation above; confirm.
3. **Unassigned tasks** — sketch lists "unassigned column" as open; recommend allow (`assignee_id` null) but CONTEXT says default creator, so null only if user clears it.
4. **Done-task retention on board** — recommend last 30 days visible, "Show completed" loads more.
5. **Overdue behaviour for date-only tasks** — overdue after end of local day; recommend.
6. **Completion history UI** — recommend a simple list in the detail panel of completed/skipped occurrences of the series.

## Environment Availability

| Dependency | Required By | Available | Version | Fallback |
|------------|------------|-----------|---------|----------|
| PHP | host tests | ✓ (host) | 8.3.6 (Docker image 8.4) | run in container |
| Composer | install packages | ✓ | 2.7.1 | — |
| Docker | dev/test stack | ✓ | 29.9.2 | — |
| PHP ext openssl, mbstring, sodium, intl | web-push, i18n | ✓ | host has all four | — |
| PHP ext curl, gmp | web-push (curl required; gmp optional speed-up) | curl not listed in host probe; gmp ✗ | — | Verify in the app image (`php -m`); gmp optional |
| Stalwart SMTP | email reminders | server-side (not probed) | — | `MAIL_MAILER=log` in dev/tests (`Mail::fake`) |
| slopcheck | package audit | ✗ | — | human-verify checkpoint |

**Missing with no fallback:** none. Confirm `ext-curl` in the `docker/app/Dockerfile` prod image before adding web-push (web-push requires it); add `docker-php-ext-install curl` equivalent only if missing `[ASSUMED present in php:8.4-fpm-alpine]`.

## Validation Architecture

### Test Framework
| Property | Value |
|----------|-------|
| Framework | Pest ^4.7 (PHPUnit 12) + Vitest ^5.1 with jsdom and axe-core |
| Config file | `phpunit.xml`, `tests/Pest.php`, `vitest.config.js` |
| Quick run command | `php artisan test --filter=Todo` (in app container) / `npx vitest run tests/js/todo` |
| Full suite command | `php artisan test` and `npx vitest run` and `node scripts/check-sw-precache.mjs` after build |

### Phase Requirements → Test Map
| Req ID | Behavior | Test Type | Automated Command | File Exists? |
|--------|----------|-----------|-------------------|-------------|
| TODO-01 | Create/update/delete task, validation, status transitions | feature | `php artisan test tests/Feature/Todo/TaskCrudTest.php` | ❌ Wave 0 |
| TODO-02 | Assignee must have todo access; defaults to creator | feature | `php artisan test tests/Feature/Todo/AssignmentTest.php` | ❌ Wave 0 |
| TODO-03 | Personal/Family/Selected visibility, admin no bypass, 404 | feature | `php artisan test tests/Feature/Todo/VisibilityTest.php` | ❌ Wave 0 |
| TODO-03 | Matrix rows for all todo routes | feature | `php artisan test tests/Feature/Access/EndpointMatrixTest.php` | ✅ (add `EndpointMatrix/todo.php`) |
| TODO-04 | Daily/weekly/monthly/custom, from schedule vs completion, skip, no double-complete | unit + feature | `php artisan test tests/Unit/Recurrence tests/Feature/Todo/CompletionTest.php` | ❌ Wave 0 |
| TODO-05 | Month-end (28/29/30/31/last, leap year), DST 2027-03-28 and 2027-10-31, fixed clock | unit | `php artisan test tests/Unit/Recurrence/MonthEndTest.php tests/Unit/Recurrence/DstTest.php` | ❌ Wave 0 |
| TODO-06 | `reminders:enqueue` idempotent, due-time computation (08:00 date-only, offsets), prefs, visibility re-check | feature | `php artisan test tests/Feature/Todo/ReminderEnqueueTest.php` | ❌ Wave 0 |
| TODO-06 | `SendOutboxItem` mail (localized, `Mail::fake`), push (fake WebPush), 410 deletes subscription, retry/backoff, double-run sends once | feature | `php artisan test tests/Feature/Notifications/OutboxTest.php` | ❌ Wave 0 |
| TODO-06 | SW push handlers present and precache guard still passes | js | `npx vitest run tests/js/sw-precache.test.js` | ✅ (extend) |
| TODO-01..06 UI | Todo page, quick add, detail panel, recurrence field, notification settings; axe in sk/pl/en light/dark | js | `npx vitest run tests/js/todo tests/js/a11y-sweep.test.js` | ❌ Wave 0 |

### Sampling Rate
- **Per task commit:** the relevant Pest file or Vitest folder (< 30 s)
- **Per wave merge:** `php artisan test` + `npx vitest run`
- **Phase gate:** full suites green plus `npm run build && node scripts/check-sw-precache.mjs` before `/gsd-verify-work`

### Wave 0 Gaps
- [ ] `tests/Unit/Recurrence/*` including a spike test that pins php-rrule behaviour for A1 (clamp via BYSETPOS, DST wall-clock, leap year)
- [ ] `tests/Feature/Todo/*`, `tests/Feature/Notifications/OutboxTest.php`, `tests/Support/EndpointMatrix/todo.php` and `notifications.php`
- [ ] Task factory with states (personal/family/selected, recurring)
- [ ] Fake push sender binding (interface `PushSender` so tests never hit the network)
- [ ] `tests/js/todo/*` page tests and a11y sweep entries; `lang` keys in sk/pl/en
- [ ] Framework install: `composer require rlanvin/php-rrule minishlink/web-push` (after human verify)
- Manual-only (justified): real device push on iPhone installed PWA and real Stalwart delivery, to be run on staging.

## Security Domain

### Applicable ASVS Categories (Level 1)

| ASVS Category | Applies | Standard Control |
|---------------|---------|-----------------|
| V2 Authentication | no (Phase 2) | — |
| V3 Session Management | partly | Push subscribe/unsubscribe endpoints use session + CSRF; no tokens in URLs |
| V4 Access Control | yes | `access:module,todo,*` middleware + `Task::visibleTo` scope; 404 on invisible; matrix + record tests; no admin bypass |
| V5 Input Validation | yes | FormRequests: enum validation for status/visibility, RRULE allowlist via RuleBuilder (reject raw client RRULE), length caps, `Rule::exists` constrained to members with todo access, explicit `$fillable`, never mass-assign `creator_id` |
| V6 Cryptography | yes | web-push library for VAPID/ECDH; `encrypted` cast for `p256dh`/`auth`; VAPID private key only in server env, never logged or in git |
| V7 Error handling/logging | yes | Log ids only, never titles/notes; `last_error` class name only |
| V8 Data protection | yes | Push payload title-only; no-store headers (existing middleware); SW never caches responses |
| V13 API | yes | Push subscription endpoint validation (must be https URL, max length), per-user cap (e.g. 10), throttle |

### Known Threat Patterns for Laravel/Inertia/Web Push

| Pattern | STRIDE | Standard Mitigation |
|---------|--------|---------------------|
| IDOR on task id (read/modify others' Personal task) | Information Disclosure / Tampering | Fetch only through `visibleTo`, 404; edit check requires grant + visibility |
| Mass assignment of `creator_id`, `status`, `completed_at` | Tampering | FormRequest `validated()` allowlist, guarded model |
| Malicious RRULE (huge COUNT/INTERVAL, infinite loops, DoS) | Denial of Service | Server builds rule from structured fields; engine iteration cap |
| SSRF via push `endpoint` stored from client | Spoofing / SSRF | Server sends to attacker-chosen URL: require `https`, reject private/loopback hosts and non-default ports, optionally allowlist known push hosts (fcm.googleapis.com, updates.push.services.mozilla.com, *.push.apple.com, *.notify.windows.com `[ASSUMED list]`) |
| Subscription hijack/cross-user (same endpoint registered by two users) | Spoofing | Unique `endpoint_hash`; re-registering re-binds to the current user |
| Lock-screen leakage | Information Disclosure | Title-only push; no notes; user can disable push |
| Reminder to revoked user | Information Disclosure | Re-check access and visibility at send time |
| XSS via task title/notes | Tampering | Vue text interpolation only, no `v-html`; mail via Blade-escaped MailMessage lines |
| Open redirect via notification `url` | Tampering | Server sets relative url; SW accepts only paths starting with `/` |
| VAPID key leak | Information Disclosure | Server `app.env`, generated by command, separate per environment |
| Duplicate/replayed outbox sends | Repudiation / annoyance | Unique dedupe key + atomic claim |

## Sources

### Primary (HIGH confidence)
- Local repository: `app/Access/AccessGate.php`, `app/Http/Middleware/RequireAccess.php`, `tests/Support/EndpointMatrix*`, `routes/web.php`, `routes/console.php`, `resources/js/sw.js`, `scripts/check-sw-precache.mjs`, `vite.config.js`, `compose.yaml`, `compose.server.yaml`, `Dashboard/QuickAdd.vue`, `Shell/modules.js`, sketch findings `tasks-and-wishlist.md`, 02-RESEARCH.md
- repo.packagist.org p2 API: minishlink/web-push v11.0.0 (php >=8.2, ext curl/json/mbstring/openssl, repo web-push-libs/web-push-php), rlanvin/php-rrule v3.0.0 (php >=7.3), simshaun/recurr v6.0.0 (php >=8.4)
- https://github.com/web-push-libs/web-push-php/releases (v11 PSR-18 change, `flushPooled` note)
- https://webkit.org/blog/13878/web-push-for-web-apps-on-ios-and-ipados/ (Home Screen requirement, user gesture, 16.4, APNs hosts)

### Secondary (MEDIUM confidence)
- https://github.com/rlanvin/php-rrule README (maintenance signals; DST/BYSETPOS details not documented on the fetched page)

### Tertiary (LOW confidence)
- Training knowledge for BYSETPOS clamp semantics, Web Push payload size, iOS "no silent push", Laravel `skipLocked`/`insertOrIgnore` (confirm against vendor source/tests)

## Metadata

**Confidence breakdown:**
- Standard stack: MEDIUM - versions verified; behaviour of php-rrule for clamp/DST unverified until spike
- Architecture: HIGH - follows existing, inspected codebase patterns
- Pitfalls: MEDIUM-HIGH - standard for recurrence/outbox/push; test strategy covers the uncertain ones

**Research date:** 2026-11-13
**Valid until:** 2026-12-13 (web-push and rrule had major releases in July 2026)
