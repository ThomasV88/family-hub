# Phase 4: Wishlist - Research

**Researched:** 2026-11-13
**Domain:** Laravel 13 / Inertia 3 + Vue / Postgres: privacy-critical gift wishlist (hidden reservations), SSRF-safe link-image fetching, image upload hardening, name-day data, giver-only reminders on the Phase 3 outbox
**Confidence:** MEDIUM-HIGH (codebase facts and PHP/curl behaviour verified in this session; Polish name-day licensing and a few curl/GD behaviours are flagged)

<user_constraints>
## User Constraints (from CONTEXT.md)

### Locked Decisions

#### Wishes and sharing
- Wish fields: title, notes, price (amount + currency, default EUR), size/variant notes, priority, multiple URLs (optional label), image.
- Everyone with wishlist view access sees every member's list. Owners edit their own list; a member with an edit grant may add wishes on a child's behalf if the admin allows it.
- Card gallery per member (sketch 005), the viewer's own list first.
- Product image: fetched in the worker from og:image/twitter:image with SSRF guard, timeout and size cap, never in the web request; manual upload fallback (stored outside web root, served via an authenticated route, EXIF stripped, MIME sniffed from bytes).

#### Reservations and chip-in (privacy core)
- Any member except the recipient can mark "I'm buying this"; visible with name to other givers, NEVER to the recipient in any view, count, badge, notification, export, search or API response.
- A parent/manager who manages a child's list is treated like the recipient and never sees reservations on it.
- A reserved gift can be opened for co-buying: other givers join with an optional amount; the organizer closes it.
- Enforcement: reservation data in separate tables; owner/manager endpoints never select reservation columns; the endpoint matrix and dedicated leak tests prove no leak via list, detail, counts, search, export, notifications and shared Inertia props.

#### Occasions and reminders
- Occasions: birthdays (member birth date), Slovak and Polish name days (static calendar tables per locale; member picks which applies), Christmas, custom occasions with a date.
- Givers get reminders 14 days and 3 days ahead (configurable per member) via the Phase 3 notification outbox (email and push); the recipient never gets one. Reminder text names the person and occasion and links to the list; it never mentions reservations.
- After an occasion, reserved wishes stay until the owner marks them received, then they archive.

### Claude's Discretion
Table and class names, name-day dataset source (verify license; ship as versioned static data), UI details following the Phase 01.1 design system and sketch 005, test organisation.

### Deferred Ideas (OUT OF SCOPE)
Price tracking, shop integrations, gift ideas suggestions, per-list sharing rules.
</user_constraints>

<phase_requirements>
## Phase Requirements

| ID | Description | Research Support |
|----|-------------|------------------|
| WISH-01 | Member can add wishes to own list with title, notes, price, size/variant notes | Data model `wishes` (no reservation columns), money as minor units, FormRequest rules, `WishAccess` edit rules |
| WISH-02 | A wish can have multiple URLs | `wish_urls` table (max 10, http/https only, optional label), safe rendering |
| WISH-03 | Product image loaded from og:image in background, manual upload fallback | `FetchWishImage` job + `SafeFetcher` (DNS-pinned), DOMDocument parsing, GD re-encode, private disk + authenticated route, upload hardening |
| WISH-04 | Reservation visible to others, never to recipient (no counts, badges, notifications, exports, search) | Separate tables, `Catalog` vs `Giving` namespaces, Pest arch test, differential + canary + query-log leak tests, ViewerContext, manager handling |
| WISH-05 | Occasions tracked per member (birthday, SK/PL name days, Christmas, custom) | `users.birth_date`, versioned name-day JSON, `wish_occasions` table, `AnnualDate` helper |
| WISH-06 | Givers get reminders ahead of an occasion, never the recipient | `occasions:enqueue` scan, new outbox kind `wish_occasion`, `WishOccasionHandler`, per-member lead days |
| WISH-07 | Several members can chip in on one gift | `wish_reservations` (organizer) + `wish_contributions`, open/close state machine, race safety |
</phase_requirements>

## Summary

Phase 4 is mostly assembly on Phase 2 and 3 pieces (AccessGate, `access:` middleware, EndpointMatrix harness, notification outbox with handler registry, `Ui*` components), but it adds three genuinely new engineering problems. (1) **Reservation secrecy** must hold structurally, not by UI hiding. The design that makes it provable is: the `Wish` model and everything in a `Catalog` namespace know nothing about reservations (no relation, no `withCount`, no `$with`, no `$appends`); reservations live in `wish_reservations` / `wish_contributions`, are queried only from a `Giving` namespace, and only after a `ViewerContext` says the viewer is not recipient-side (owner or manager). Owner-facing requests then issue **zero SQL against the reservation tables**, which is directly testable with a query log. The test suite proves it three ways: a Pest arch test (static), a differential test (owner responses byte-identical with and without reservations), and a canary test (distinctive giver name and amount never appear). (2) **Link image fetching** is an SSRF surface. PHP's `FILTER_FLAG_NO_PRIV_RANGE | NO_RES_RANGE` was empirically tested here and misses CGNAT 100.64/10, 198.18/15, 192.0.0.0/24, multicast, IPv4-mapped IPv6, NAT64 and 6to4, so an explicit CIDR denylist is required. Pinning is done with `CURLOPT_RESOLVE` (supported by the installed Guzzle through the `curl` option), with redirects handled manually and every hop re-validated and re-pinned. (3) **Images and storage**: the prod image has no `gd` or `exif` extension and the compose stacks have no uploads volume and the backup script covers only Postgres, so the phase needs Dockerfile, compose, nginx (`client_max_body_size`), php.ini and backup changes before any image code works.

Other decisions that are not obvious: no new Composer packages are needed (ext-dom is present in the image, Guzzle is already installed, Symfony `IpUtils` ships with Laravel). The Phase 3 `RecurrenceEngine` does **not** fit annual occasions (its `RuleBuilder` allowlist rejects `FREQ=YEARLY`, and it is built around task due datetimes), so use a 15-line pure `AnnualDate` helper with a Feb 29 rule instead. Name-day data: Slovak has a clean MIT-licensed copy of the official Ministry of Culture calendar; **Polish has no clean machine-readable licensed source** (needs an owner decision, see Open Questions). Reminders reuse the outbox unchanged (new `kind`, new handler registered in `OutboxHandlers::HANDLERS`), scanned hourly with dedupe keys, computed on the fly (no `remind_at` column).

**Primary recommendation:** Build `App\Wishlist\Catalog` (reservation-blind) and `App\Wishlist\Giving` (reservation-aware, giver-only) as separate namespaces enforced by a Pest arch test, put reservations in separate tables with a unique-per-wish row, and make three leak tests (query-log zero-touch, differential, canary) part of Wave 0 before any UI exists.

## Project Constraints (from CLAUDE.md)

- Stack is Laravel (PHP 8.3+/8.4 image), Inertia + Vue, PostgreSQL, database queue with separate `worker` and `scheduler` containers, Pest + Vitest, Laravel localization + vue-i18n. TypeScript/Next.js recommendations in CLAUDE.md are superseded; do not use Node packages from PART 2.
- Per-module permissions enforced **server-side**; hiding UI is never enough. Health and finance default restricted (not this phase, but the same discipline applies to reservations).
- Languages SK, PL, EN from the start (SK/PL need three plural forms); devices phone, desktop, iPad (PWA).
- Deployment: Docker Compose services `app`, `nginx`, `worker`, `scheduler`, `postgres`; no Redis. Staging (`develop`) and production (`main`).
- Subscription-only AI; not relevant to this phase.
- GSD workflow: all file changes go through GSD commands (planner/executor), not ad-hoc edits.
- Project skill: `sketch-findings-family-hub` (sketch 005 winner D: shopping summary card, person chips, occasion strip, own-list banner, card gallery 2/3/4 columns). Follow the Phase 01.1 design system (`Ui*` components, `UiCard`, `UiTag`, `UiChip`, `UiDetailPanel`, `UiAlert`).
- Established project patterns: Inertia pages, per-feature `routes/web/*.php` auto-globbed, one `tests/Support/EndpointMatrix/*.php` per feature, lang keys added up front in one plan, Vitest + axe sweeps in sk/pl/en and light/dark, `NoStoreDynamicResponses` on all dynamic responses, precache-only service worker, no admin bypass for record-level privacy.

## Architectural Responsibility Map

| Capability | Primary Tier | Secondary Tier | Rationale |
|------------|-------------|----------------|-----------|
| Reservation visibility rules | API / Backend | Database (separate tables) | Must hold for any client; UI hiding is cosmetic. Backend decides which presenter runs |
| Wish CRUD, URLs, price | API / Backend | Browser (form) | FormRequests validate; Inertia pages render |
| Link image fetch + parse | API / Backend (worker container) | Database (status) | Outbound network from the worker only, never in a web request |
| Image re-encode, storage, serving | API / Backend | Database/Storage (private volume) | Authenticated route streams from a non-public disk |
| Manual image upload | Browser (optional downscale) | API / Backend | Server is the authority (bytes sniffing, pixel cap, re-encode) |
| Occasion calculation (birthday, name day, Christmas, custom) | API / Backend | Database | Pure date logic in the family timezone; computed on the fly |
| Name-day data | Static files (in image) | API / Backend (search endpoint) | Versioned JSON, no DB seeding step needed |
| Giver reminders | API / Backend (scheduler + worker) | Database (outbox) | Phase 3 outbox: idempotent enqueue, localized send |
| Gallery, panel, shopping summary UI | Browser / Client | Frontend Server (Inertia) | Presentation only; props already filtered per viewer |
| Per-member lead-day settings | API / Backend | Browser (Settings > Notifications) | Own-row writes only |

## Standard Stack

### Core (all already installed; no new Composer or npm packages)
| Library | Version | Purpose | Why Standard |
|---------|---------|---------|--------------|
| laravel/framework | ^13.17 [VERIFIED: composer.json] | Routing, Eloquent, queue, scheduler, Http client | Existing stack |
| inertiajs/inertia-laravel | ^3 [VERIFIED: composer.json] | Server-driven pages | Existing |
| guzzlehttp/guzzle | 8.4.0 [VERIFIED: composer.lock] | HTTP client under `Http::`; `curl` option with `CURLOPT_RESOLVE` for DNS pinning | Already a dependency; `CURLOPT_RESOLVE` is in Guzzle's supported curl-options list [VERIFIED: vendor/guzzlehttp/guzzle/src/Handler/CurlFactory.php] |
| ext-dom + libxml (PHP) | built into `php:8.4-fpm-alpine` [VERIFIED: `php -m` in family-hub-app:dev] | Parse og:image / twitter:image / JSON-LD with `DOMDocument` + `DOMXPath` | No `symfony/dom-crawler` needed; host PHP 8.3 lacks dom, so run tests in the container |
| ext-gd (PHP) | NOT present, must be added [VERIFIED: `php -m` in image] | Decode, orient, resize, re-encode images (strips all metadata) | Simplest correct re-encoder; Imagick needs PECL and ImageMagick policy files |
| ext-exif (PHP) | NOT present, must be added [VERIFIED] | Read JPEG orientation before re-encode | Needed so phone photos are not rotated wrong after metadata is stripped |
| ext-fileinfo, ext-curl, ext-intl | present [VERIFIED] | MIME from bytes, HTTP, `idn_to_ascii` for host normalisation | |
| symfony/http-foundation `IpUtils` | ships with Laravel [ASSUMED standard Laravel dependency] | `IpUtils::checkIp($ip, $cidrs)` for the SSRF denylist | Avoids hand-written CIDR maths |
| rlanvin/php-rrule | ^3.0 | NOT used for occasions (see Pattern 6) | |
| Pest ^4.7 (arch plugin) | [VERIFIED: vendor, `toUse` in OppositeExpectation] | Arch test that forbids reservation classes outside `Giving` | |

### Supporting
| Library | Version | Purpose | When to Use |
|---------|---------|---------|-------------|
| Phase 3 `OutboxWriter`, `OutboxHandlers`, `SendOutboxItem`, `NotificationPreferences`, `PushSender` | in repo | Reminder delivery | Add kind `wish_occasion` and handler `WishOccasionHandler` |
| `App\Access\AccessGate`, `access:module,wishlist,{view,edit}` | in repo | Module-level gate | Every wishlist route |
| `tests/Support/EndpointMatrix` | in repo | Role by endpoint matrix | Add `wishlist.php` |
| Name-day data: `jozefgrencik/slovak-name-days` `data/names.json` (MIT; official culture.gov.sk calendar approved June 2017) | copy file, do NOT `composer require` | Slovak name days | Vendor the JSON into `database/data/namedays/sk.json` with its MIT notice [CITED: github.com/jozefgrencik/slovak-name-days] |

### Alternatives Considered
| Instead of | Could Use | Tradeoff |
|------------|-----------|----------|
| GD | Imagick | Better colour handling and `jpeg:size` decode hints, but PECL build, ImageMagick policy risk, more image weight. GD is enough for 1280px JPEG derivatives |
| Own `SafeFetcher` on Guzzle | `symfony/http-client` + `NoPrivateNetworkHttpClient` | Extra dependency; as far as I know it checks the connected IP after connecting rather than pinning before [ASSUMED], so a request may already have been sent. Own pinning is ~150 lines and fully unit-testable |
| `DOMDocument::loadHTML` | `Dom\HTMLDocument::createFromString` (PHP 8.4 HTML5 parser) | Better HTML5 handling but `composer.json` allows PHP ^8.3 and host tests run 8.3. Use `DOMDocument` so both versions work |
| Files on an uploads volume | `bytea` in Postgres | bytea would need no volume or backup change, but Phase 7 needs real file storage anyway, so establish the volume and backup now |
| `RecurrenceEngine` for annual dates | `AnnualDate` helper | Engine's `RuleBuilder` allowlist rejects YEARLY and it iterates; a pure date function is simpler and deterministic |
| Static JSON name days | DB table seeded by migration | A table adds a seeding/deploy step (migrations run as owner role only); JSON in the image is versioned with git |

**Installation:** none for Composer/npm. Dockerfile `base` stage change (runtime libs must be listed explicitly, because `apk del .build-deps` removes only the `-dev` packages; this is the same pattern already used for `icu-libs libpq libzip`):
```dockerfile
apk add --no-cache icu-libs libpq libzip libjpeg-turbo libpng libwebp; \
apk add --no-cache --virtual .build-deps $PHPIZE_DEPS icu-dev libzip-dev postgresql-dev libjpeg-turbo-dev libpng-dev libwebp-dev; \
docker-php-ext-configure gd --with-jpeg --with-webp; \
docker-php-ext-install -j"$(nproc)" pdo_pgsql intl opcache pcntl zip bcmath gd exif; \
```
`[ASSUMED: alpine package names and configure flags are the standard docker-library/php recipe for PHP 8.4; the build must be run once to prove it]`

**Version verification:** no new package versions to verify. Existing versions read from `composer.json` / `composer.lock` / `vendor` in this session. Container probe: PHP 8.4.27, libcurl 8.23.0 (OpenSSL 3.5.9), extensions listed above.

## Package Legitimacy Audit

No external packages are added in this phase (no `composer require`, no `npm install`). PHP extensions `gd` and `exif` are compiled from the official `php` source tree via `docker-php-ext-install`, not downloaded from a registry. The Slovak name-day data is vendored as a data file (not a dependency), so there is no install-time code execution.

| Package | Registry | Age | Downloads | Source Repo | slopcheck | Disposition |
|---------|----------|-----|-----------|-------------|-----------|-------------|
| (none) | - | - | - | - | not needed (slopcheck not run; nothing to install) | n/a |

**Packages removed due to slopcheck [SLOP] verdict:** none
**Packages flagged as suspicious [SUS]:** none

## Architecture Patterns

### System Architecture Diagram

```
Browser (Wishlist gallery / panel / occasions panel / Settings > Notifications)
  | Inertia visits, multipart upload           | Add to Home Screen PWA (push)
  v                                             v
nginx (client_max_body_size raised for upload route) -> php-fpm
  web group -> access:module,wishlist,{view|edit}  (matrix-enforced)
     |
     |  ViewerContext::for($user)   (user + owner ids the user MANAGES)
     |
     +-- list/detail/mutations for OWN or MANAGED list ----> App\Wishlist\Catalog   (reservation-blind: Wish, WishUrl, images, occasions)
     |                                                        -> Inertia props with NO reservation keys at all
     |                                                        -> zero SQL on wish_reservations / wish_contributions
     |
     +-- list/detail for SOMEONE ELSE's list --------------> Catalog (wish data)  +  App\Wishlist\Giving (reservation data, giver only)
     |        Giving refuses (LogicException, fail closed) if viewer is recipient-side of that owner
     |
     +-- reserve / cancel / open co-buy / join / close ----> Giving only; 403 if recipient-side, 409 on race (unique wish_id)
     |
     +-- POST upload ---------------> ImageProcessor (finfo+getimagesize, pixel cap, GD orient + re-encode) -> private disk
     +-- POST/auto "load image" ----> dispatch FetchWishImage(wish)  [queue: database]
     v
Postgres: users(+birth_date), wishes, wish_urls, wish_occasions, wish_reservations, wish_contributions,
          wishlist_managers, wish_reminder_settings, notification_outbox (kind=wish_occasion)

Worker container
   FetchWishImage: for each wish_url -> SafeFetcher (validate, resolve DNS, deny private, PIN IP via CURLOPT_RESOLVE,
      no auto-redirect, <=3 manual hops each re-validated, 3 s connect / 10 s total, HTML <=512 KiB)
      -> DOMDocument/XPath: og:image(:secure_url), twitter:image(:src), link rel=image_src, JSON-LD Product.image
      -> each candidate image URL again through SafeFetcher (<=5 MiB, abort mid-stream)
      -> ImageProcessor (same as upload) -> private disk -> wishes.image_status=ready | failed
   SendOutboxItem -> WishOccasionHandler (stillRelevant re-check, localized mail / push)

Scheduler container
   occasions:enqueue (hourly, acts once local hour >= 8 in family tz):
      AnnualDate::next() per occasion -> lead days per giver -> givers = wishlist>=edit minus owner minus owner's managers
      -> OutboxWriter::enqueue('wish_occasion', ..., dedupe_key)   (insertOrIgnore)
   outbox:dispatch (existing, every minute)
   wishlist:prune (daily): purge reservations of archived wishes older than N days; purge manager-conflicting reservations; orphan image files

Giver gets push/mail: "<Person>: birthday in 14 days" -> link /wishlist/members/{id}   (no reservation info anywhere)
```

### Recommended Project Structure
```
app/Wishlist/
├── Catalog/        # reservation-BLIND: WishService, WishPresenter(forOwner), ImageProcessor, FetchWishImage job lives in app/Jobs, WishImageStore
├── Giving/         # reservation-AWARE: ReservationService, ContributionService, GiverPresenter, ShoppingSummary, GiverRecipients (reminders)
├── Occasions/      # AnnualDate, OccasionCalendar (derive occurrences), NameDayCalendar, OccasionOccurrence VO
├── Fetch/          # SafeFetcher, IpPolicy, DnsResolver (interface + system impl), MetaImageExtractor
├── ViewerContext.php   # viewer + managed owner ids; isRecipientSideOf($ownerId)
└── WishAccess.php      # canEditList, canReserve, canManageOccasions (mirrors TaskAccess)
app/Models/  Wish, WishUrl, WishOccasion, WishlistManager, WishReservation, WishContribution, WishReminderSetting
app/Http/Controllers/Wishlist/  ListController, WishController, WishImageController, ReceivedController, OccasionController, NameDayController (owner side)
app/Http/Controllers/Wishlist/Giving/  ReservationController, CoBuyController, ContributionController
app/Notifications/Outbox/WishOccasionHandler.php ; app/Notifications/WishOccasionNotification.php
app/Console/Commands/EnqueueOccasionReminders.php (occasions:enqueue), PruneWishlist.php (wishlist:prune)
database/data/namedays/{sk.json, pl.json, SOURCES.md, LICENSE-sk.txt}
routes/web/wishlist.php ; tests/Support/EndpointMatrix/wishlist.php
resources/js/Pages/Wishlist/{Index.vue, Member.vue} ; resources/js/Wishlist/{WishCard,WishPanel,ReservationBox,CoBuyBox,ShoppingSummary,OccasionStrip,OccasionsPanel,ImageField}.vue
lang/{en,sk,pl}.json (+ lang/*/mail.php, notifications.php keys for reminders)
```

### Data model (Postgres)

Reservation data is in separate tables and `wishes` has **no** reservation-related column, no counter, no `reserved_at`, no flag.

- `users`: add `birth_date date null`; add to `$hidden` on the model and never include it in Inertia `auth.user` or member lists (only month/day is ever sent to others).
- `wishes`: `id`, `owner_id` FK users (restrict), `created_by_id` FK users, `title varchar(200)`, `notes text null (max 2000)`, `price_minor bigint null`, `price_currency char(3) default 'EUR'`, `size_notes varchar(200) null`, `priority smallint default 1` (0 nice-to-have, 1 normal, 2 top), `image_path varchar null`, `thumb_path varchar null`, `image_source varchar null` (`fetched|uploaded`), `image_status varchar default 'none'` (`none|pending|ready|failed`), `received_at timestamptz null`, timestamps. Indexes `(owner_id, received_at)`. CHECKs on enums and `price_minor >= 0`.
- `wish_urls`: `id`, `wish_id` FK cascade, `url varchar(2048)`, `label varchar(100) null`, `position smallint`. Max 10 per wish (validated).
- `wishlist_managers`: `owner_id`, `manager_id`, `created_by_id`, unique `(owner_id, manager_id)`, CHECK `owner_id <> manager_id`. This is the "admin allows" mechanism; it is also the single source for "treated like the recipient".
- `wish_occasions`: `id`, `owner_id` FK cascade, `kind` (`nameday|custom`), `title varchar(100) null` (custom), `month smallint`, `day smallint`, `year smallint null` (custom one-off; null = annual), `calendar char(2) null` and `name varchar(60) null` (name day snapshot: the chosen name and calendar so later data updates never silently move a date), `created_by_id`. CHECKs on month 1..12 / day 1..31. Birthday comes from `users.birth_date`; Christmas from `config('wishlist.christmas')` (month 12, day 24).
- `wish_reservations`: `id`, `wish_id` FK cascade **unique** (one reservation per wish), `user_id` FK (the buyer / co-buy organizer), `cobuy_state` (`none|open|closed`) default `none`, timestamps. Never touched by owner paths.
- `wish_contributions`: `id`, `reservation_id` FK cascade, `user_id`, `amount_minor bigint null`, unique `(reservation_id, user_id)`, timestamps. The organizer also has a row (uniform handling; amount optional).
- `wish_reminder_settings`: `user_id` PK, `enabled boolean default true`, `lead_days jsonb default '[14,3]'` (validated: 1 to 3 integers in 1..60).
- Outbox: reuse `notification_outbox`; `kind='wish_occasion'`, `subject_type` in (`user_birthday`, `wish_occasion`, `christmas`), `payload` = `{type, subject_id, date:'YYYY-MM-DD', lead:int}`; ids and dates only.

Why one reservation row per wish with a unique constraint: it makes "two people click Buy" race-safe (second insert fails with a unique violation, return 409 naming the first buyer to the second giver) without row locks. Co-buy state changes use `lockForUpdate` on the reservation row.

### Pattern 1: Reservation-blind `Catalog` vs reservation-aware `Giving`

**What:** `Wish` has no `reservation()` relation. The only relation is `WishReservation::wish()`. Reservation data is fetched from the reservation side, in `Giving`, for a given set of wish ids: `WishReservation::whereIn('wish_id', $ids)->with('user:id,name', 'contributions.user:id,name')`. A `ViewerContext` is built once per request/job and answers `isRecipientSideOf($ownerId)` (viewer is owner, or exists in `wishlist_managers` for that owner).

**When to use:** every wishlist controller. Owner-facing paths (own list, a managed child's list, wish CRUD, image upload, received, occasions) call only `Catalog`. Other-people paths call `Catalog` + `Giving`.

**Example:**
```php
// Source: project design (Phase 4); Pest arch API verified in vendor/pestphp/pest (OppositeExpectation::toUse)
// tests/Arch/WishlistPrivacyTest.php
arch('Catalog and owner-side controllers never touch reservations')
    ->expect([
        'App\Wishlist\Catalog', 'App\Wishlist\Occasions', 'App\Wishlist\Fetch', 'App\Models\Wish', 'App\Models\WishUrl',
        'App\Http\Controllers\Wishlist\WishController', 'App\Http\Controllers\Wishlist\WishImageController',
        'App\Http\Controllers\Wishlist\ReceivedController', 'App\Http\Controllers\Wishlist\OccasionController',
    ])
    ->not->toUse(['App\Models\WishReservation', 'App\Models\WishContribution', 'App\Wishlist\Giving']);

arch('Only Giving, its controllers and the member-list controller may use reservation models')
    ->expect(['App\Models\WishReservation', 'App\Models\WishContribution'])
    ->toOnlyBeUsedIn(['App\Wishlist\Giving', 'App\Http\Controllers\Wishlist\Giving', 'App\Console\Commands\PruneWishlist', 'App\Models']);
```
`[ASSUMED: toOnlyBeUsedIn exists in Pest 4 arch; confirm in Wave 0, otherwise use only the first block]`

The gallery controller (`ListController@show`) is the single branch point:
```php
public function show(Request $request, User $owner, ViewerContext $viewer, WishListPage $own, GiverListPage $giver)
{
    return $viewer->isRecipientSideOf($owner->id)
        ? $own->render($owner, $request)      // class in Catalog: cannot reference Giving (arch-enforced)
        : $giver->render($owner, $request);   // class in Giving
}
```
Keep `ListController` out of the arch "never uses" list but cover it with the differential test. Request parameters that reference reservation state (for example a giver-only filter `?available=1`) are **ignored entirely** on the recipient branch, never rejected differently (a different response would be an oracle for the owner).

### Pattern 2: Fail-closed guard inside `Giving`
```php
final class GiverListPage {
    public function render(User $owner, Request $r): Response {
        $viewer = app(ViewerContext::class);
        throw_if($viewer->isRecipientSideOf($owner->id), LogicException::class, 'Giving data requested for a recipient-side viewer.');
        // ...
    }
}
```
Same guard at the start of every `ReservationService` / `ContributionService` public method. Defense in depth against a future routing mistake. The shopping summary (viewer's own reservations on OTHER people's wishes) filters `wishes.owner_id NOT IN (viewer, managed owner ids)` as well, and excludes archived wishes.

### Pattern 3: Differential, canary and query-log leak tests (Wave 0)

```php
// tests/Feature/Wishlist/ReservationPrivacyTest.php (sketch)
dataset('recipientSide', ['owner', 'manager']);

it('owner-side responses are identical with and without reservations and touch no reservation table', function (string $side) {
    [$owner, $manager, $giver, $other] = users();
    $wish = Wish::factory()->for($owner, 'owner')->create();
    WishlistManager::create(['owner_id' => $owner->id, 'manager_id' => $manager->id, 'created_by_id' => $owner->id]);
    $viewer = $side === 'owner' ? $owner : $manager;

    $surfaces = fn () => collect(ownerSideRequests($viewer, $owner, $wish))      // list, detail, panel, search, occasions, image, home, settings
        ->map(fn ($call) => $call());                                           // each returns normalised body+headers (strip csrf, timestamps)

    DB::enableQueryLog();
    $before = $surfaces();
    $sqlBefore = collect(DB::getQueryLog())->pluck('query');

    seedReservationWithCoBuy($wish, $giver, name: 'ZxCanaryGiver', amountMinor: 777700);   // distinctive values
    DB::flushQueryLog();
    $after = $surfaces();
    $sqlAfter = collect(DB::getQueryLog())->pluck('query');

    expect($after)->toEqual($before);                                            // differential: no count, flag, ordering or key change
    expect(json_encode($after))->not->toContain('ZxCanaryGiver')->not->toContain('7777');   // canary
    expect($sqlAfter->filter(fn ($q) => str_contains($q, 'wish_reservations') || str_contains($q, 'wish_contributions')))->toBeEmpty();
})->with('recipientSide');
```
Rules for this test file:
- Enumerate surfaces from `Route::getRoutes()` for the `wishlist` name prefix (so a new route is covered automatically), call GET routes as Inertia (`X-Inertia`) and as HTML, and call each mutating route as the owner and as the manager (reserve routes must return 403 for them, and that 403 must be identical whether or not a reservation exists).
- Assert on data values (giver name, amount), not on the word "reserved": the shared Inertia `messages` prop ships the whole lang JSON, which legitimately contains reservation UI strings.
- Also assert: `Wish::toArray()`, `Wish::query()->with(...)` on every defined relation, and `json_encode(Wish::all())` never include reservation keys (they cannot, by construction).
- Outbox test: after reservations exist, run `occasions:enqueue`; assert no outbox row for the owner or the owner's managers for that owner's occasion, and that no row payload or rendered mail/push contains a giver name.
- Audit test: reservation actions write nothing to `audit_entries` (the admin audit page is admin-viewable, and an admin may be the recipient). `Module::Wishlist` must stay non-audited.

### Pattern 4: Co-buy state machine (WISH-07)
- `wish_reservations.cobuy_state`: `none` (a plain "I'm buying this") then `open` (organizer proposes chip-in) then `closed` (organizer closes). Only the organizer (`reservation.user_id`) transitions; reopening is allowed (closed to open) until archived.
- Join: allowed only when `open`, user is a giver (wishlist >= edit), not recipient-side, not already contributing; `amount_minor` optional (null = "in, amount undecided"); update own amount while `open`. Leave: delete own row while `open`; organizer cannot leave (must cancel the reservation, which cascades).
- Cancel reservation: organizer only; cascades contributions. Admin cannot cancel (no bypass) unless the owner decides otherwise (Open Question).
- Totals shown to givers only: sum of non-null amounts versus `price_minor` (show remaining when both known). Never persisted on `wishes`.
- All writes run in a transaction; state changes `lockForUpdate` the reservation row; unique violations map to 409 and Inertia errors.
- Reservation writes must not touch `wishes` (no `touches`, no `updated_at` bump) so that nothing observable on the wish row changes, including ETags and ordering by `updated_at`.

### Pattern 5: SafeFetcher (DNS-pinned, redirect-safe, size-capped)

```php
// Source: design; Guzzle curl option support verified in vendor CurlFactory (CURLOPT_RESOLVE, CURLOPT_PREREQFUNCTION supported)
final class SafeFetcher {
    private const DENY = [ // IPv4 only; IPv6-only hosts are rejected (see below)
        '0.0.0.0/8','10.0.0.0/8','100.64.0.0/10','127.0.0.0/8','169.254.0.0/16','172.16.0.0/12','192.0.0.0/24','192.0.2.0/24',
        '192.88.99.0/24','192.168.0.0/16','198.18.0.0/15','198.51.100.0/24','203.0.113.0/24','224.0.0.0/4','240.0.0.0/4','255.255.255.255/32',
    ];
    public function __construct(private DnsResolver $dns, private int $maxHops = 3) {}

    public function get(string $url, int $maxBytes, array $acceptTypes): FetchResult {
        for ($hop = 0; $hop <= $this->maxHops; $hop++) {
            [$host, $port, $path] = $this->validate($url);               // http/https only, ports 80/443 only, no userinfo, idn_to_ascii,
                                                                          // FQDN regex with letter TLD (rejects 2130706433, 0x7f.1, localhost, trailing dots)
            $ip = $this->pickPublicIpv4($host);                          // resolve ONCE; every A record must be public, else reject whole host
            $res = Http::withOptions([
                    'allow_redirects' => false, 'proxy' => '', 'timeout' => 10, 'connect_timeout' => 3,
                    'curl' => [CURLOPT_RESOLVE => ["{$host}:{$port}:{$ip}"]],    // curl connects to the pinned IP; TLS SNI/cert still use the hostname
                    'on_headers' => fn ($r) => $this->rejectIfTooBig($r, $maxBytes),
                    'progress' => fn ($dlTotal, $dlNow) => $dlNow > $maxBytes ? throw new FetchAborted : null,
                ])->withHeaders(['User-Agent' => '...', 'Accept' => '...'])->get($url);
            if (in_array($res->status(), [301,302,303,307,308], true)) { $url = $this->absolute($res->header('Location'), $url); continue; }
            // 200 only; then check bytes, never trust Content-Type
            return new FetchResult($res->body(), $url);
        }
        throw new TooManyRedirects;
    }
}
```
Requirements the planner must turn into tasks and tests:
- **Resolve once, pin, never re-resolve**; the pin string must use the same host and port as the URL.
- Every hop (redirects, and each image URL found in the HTML) goes through the same method; relative URLs resolved against the final page URL; `Location` with a scheme other than http/https is refused.
- IPv4-only pinning: Docker bridge networks are normally IPv4 only; an IPv6-only host fails the job (status `failed`). This avoids IPv6 literal formatting in `CURLOPT_RESOLVE` `[ASSUMED: bracket syntax; avoided on purpose]`.
- Optional second layer: `CURLOPT_PREREQFUNCTION` (libcurl >= 7.80; container has 8.23.0) receives the actual connected IP before the request is sent; abort if it is not the pinned IP. Supported by Guzzle 8 [VERIFIED: supportedCurlOptions list]. Add if it can be unit-tested.
- Disable env proxies (`'proxy' => ''`) so `HTTP_PROXY` can never bypass pinning, and assert no proxy env in the containers.
- Abort semantics of `on_headers` (Content-Length) and `progress` (chunked bodies with no length) must be proven by a **real-socket integration test** against a local `php -S` server, which needs an injectable `IpPolicy` that allows 127.0.0.1 in tests only (never in production config).
- Injectable `DnsResolver` interface (system implementation uses `dns_get_record($host, DNS_A)`), so unit tests cover: private A record, one private among several A records, IPv6-only, CNAME to private, redirect to `http://169.254.169.254/`, redirect to a private host, redirect loop, 4th hop, port 8080, `user:pass@`, decimal/hex IP hosts, punycode, oversize with and without Content-Length, slow-drip body, wrong Content-Type with valid image bytes, valid Content-Type with HTML bytes.

### Pattern 6: Annual occasions with a pure helper (Phase 3 engine is not reusable here)

`RRuleRecurrenceEngine::next()` calls `RuleBuilder::fromRrule()`, whose allowlist covers daily/weekly/monthly/custom task patterns, and it is built around a due datetime and complete/skip modes [VERIFIED: app/Recurrence/RRuleRecurrenceEngine.php]. Extending it to YEARLY would add risk for no gain. Use:
```php
final class AnnualDate {
    /** Next date >= $from. Feb 29 is observed on Feb 28 in non-leap years. */
    public static function next(int $month, int $day, CarbonImmutable $from): CarbonImmutable {
        foreach ([$from->year, $from->year + 1] as $year) {
            $d = ($month === 2 && $day === 29 && ! checkdate(2, 29, $year)) ? 28 : $day;
            $candidate = CarbonImmutable::create($year, $month, $d, 0, 0, 0, $from->timezone);
            if ($candidate->gte($from->startOfDay())) return $candidate;
        }
    }
}
```
One-off custom occasions (year set) are compared directly and expire after their date. All "today" values come from `CarbonImmutable::now(config('family.timezone'))` so date arithmetic is in the family zone (config already exists, Phase 3).

### Pattern 7: Reminder scan (`occasions:enqueue`)
- Scheduled hourly (`Schedule::command('occasions:enqueue')->hourly()->name('occasions-enqueue')->withoutOverlapping()`), acts only when the family-zone local hour >= 8, safe to run repeatedly because of the unique `dedupe_key`.
- Occurrence sources: birthdays (`users.birth_date` not null), name days and custom (`wish_occasions`), Christmas (config). For each occurrence date `D` and each distinct giver lead `L` (from `wish_reminder_settings`, default `[14,3]`), fire when `D - L <= today <= D - L + 1` (one day of grace after a scheduler outage, but never a stale 14-day reminder at 5 days before).
- Givers = users with wishlist access >= edit, excluding the occasion's owner and the owner's managers (`wishlist_managers`), re-reading levels with `AccessGate::forget($user)` as `ReminderRecipients` does (long-lived process). Christmas is family-wide: all givers, link to `/wishlist`.
- `dedupe_key = "wish_occasion:{type}:{subjectId}:{date}:{lead}:{userId}:{channel}"`; channels from `NotificationPreferences` (mail on by default, push only if a subscription exists), mirroring `EnqueueReminders`.
- `WishOccasionHandler implements OutboxHandler` (register in `OutboxHandlers::HANDLERS`): `stillRelevant` recomputes that the occurrence on `payload.date` still exists (birth date or occasion changed or deleted means skip), the user is still a giver, and is not recipient-side of the owner. `sendMail` uses a Notification localized with `$user->preferredLocale()`, text from lang with `trans_choice` for the day count (SK/PL need three forms). `pushMessage` returns `['title' => ..., 'url' => '/wishlist/members/{id}']` (relative URL; the service worker already only accepts `/` paths).
- Payload and logs carry ids and dates only; names are resolved at send time.

### Pattern 8: Image pipeline (shared for fetched and uploaded)
`ImageProcessor::process(string $bytes): ProcessedImage`:
1. Size check (max 8 MiB upload / 5 MiB fetched) before decode.
2. `getimagesizefromstring` for type and dimensions; allow only JPEG, PNG, WebP, GIF; reject `width*height > 16_000_000` **before** `imagecreatefromstring` (decompression-bomb guard; 16 MP is about 64 MB of GD memory under the 128M `memory_limit`, and the app container limit is 192m, worker 160m). `finfo_buffer` as a second opinion; the client MIME is never used.
3. Decode with GD; for JPEG apply EXIF orientation (`exif_read_data` on the bytes, then `imagerotate`) so phone photos are upright.
4. Flatten transparency onto white, resize with `imagescale`/copy-resample to max 1280 px (full) and 480 px (thumb), encode **JPEG q82**. Re-encoding drops all metadata (GPS, EXIF, embedded payloads) and defuses polyglot files. JPEG output avoids GD WebP/palette edge cases.
5. Write to a private disk with random names (`wish-images/{wishId}/{ulid}-full.jpg`, `-thumb.jpg`); delete previous files on replace and on wish delete (model `deleted` event) and sweep orphans in `wishlist:prune`.
SVG, HEIC and animated GIF motion are not supported (GIF becomes a still frame). iOS Safari normally converts HEIC to JPEG for `accept="image/*"` uploads `[ASSUMED]`; optionally downscale to 2000 px client-side with a canvas before upload to cut upload size and avoid the 16 MP rejection for 48 MP phone photos.

Serving: `GET /wishlist/wishes/{wish}/image/{variant}` (`variant` in `full|thumb`), `access:module,wishlist,view`, streams from the private disk with `Content-Type: image/jpeg`, `X-Content-Type-Options: nosniff`, `Cache-Control: private, no-cache` plus `ETag`/304 and `Vary: Cookie`, so shared caches never store it and a browser revalidates (and therefore re-authorizes) every time. `NoStoreDynamicResponses` currently overwrites `Cache-Control` after the controller, so the image route needs an explicit exemption, tested in `CacheHeadersTest`. The URL has no file extension, so the nginx static-asset rules do not intercept it. The file path is never exposed and the disk config keeps `'serve' => false`.

### Pattern 9: Owner-side editing rights
- `WishAccess::canEditList(User $actor, int $ownerId)` = wishlist level >= edit AND (actor is owner OR actor exists in `wishlist_managers` for owner). Admin status grants nothing.
- Wishes are readable by anyone with wishlist >= view (a single module-level gate; no per-wish visibility, per-list sharing is deferred).
- Owner actions never depend on reservation state: delete, edit, mark received, upload image all succeed or fail identically with or without a reservation. **Never block delete or edit because a wish is reserved** (the refusal would reveal the reservation). Deleting a reserved wish silently cascades.
- Received/archive: `POST /wishlist/wishes/{wish}/received` sets `received_at`; `DELETE` clears it. Archived wishes are hidden from the default gallery (owner can open "Received"). Reservation rows of archived wishes are **left in place** by owner paths (so owner requests still issue zero reservation SQL) and purged by `wishlist:prune` after a retention window (suggest 30 days). Giver views, summary and reminders ignore archived wishes.
- When an admin adds a manager for an owner, reservations by that manager on that owner's wishes must disappear. Do it in a queued job (`PurgeManagerReservations`) so the admin request path is identical regardless, and additionally make every giver query exclude reservations whose owner is recipient-side for the viewer.

### Anti-Patterns to Avoid
- **A `reservation()`/`reserved` relation, accessor, `$with`, `$appends`, `withCount`, or any counter column on `Wish`.** It makes accidental serialization possible; keep the relation only on `WishReservation`.
- **Returning `$wish->toArray()` or a Resource that defaults to all attributes to Inertia.** Use an explicit presenter with an allowlist of keys per viewer type.
- **Different behaviour for owners who pass reservation-related request params, or who delete/edit a reserved wish.** That is an oracle. Ignore params, never block actions.
- **Auditing reservation actions, logging giver names, or writing reservation events to the admin audit log.**
- **`Wish::touch()` or a `$touches` relation from reservation writes** (changes `updated_at`, ETags and ordering).
- **A global Inertia shared prop or nav badge derived from reservations.** The shopping summary is page-level, viewer-own, and giver-only.
- **Following redirects automatically, resolving DNS twice, or trusting `FILTER_FLAG_NO_PRIV_RANGE`** (empirically incomplete here).
- **Public disk, `Storage::url`, or `public/storage` symlink for wish images.**
- **Fetching or parsing HTML in the web request.** Always the queued job.
- **Using the Phase 3 `RecurrenceEngine` for annual dates** (allowlist rejects YEARLY).

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| CIDR membership checks | Bit-twiddling IP comparisons | `Symfony\Component\HttpFoundation\IpUtils::checkIp` with the explicit denylist | Correct for IPv4 and IPv6, tested upstream |
| Image decode/resize/orientation | Byte-level EXIF/JPEG parsing | GD (`imagecreatefromstring`, `imagescale`, `imagejpeg`) + `exif_read_data` | Re-encode is both the resize and the sanitizer |
| MIME detection | Trusting client MIME or file extension | `getimagesizefromstring` + `finfo_buffer`, and the GD decode itself as the final validation | Extension and header are attacker-controlled |
| HTML meta extraction | Regex over HTML | `DOMDocument::loadHTML` + `DOMXPath` (flags `LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING`, input capped at 512 KiB, charset handled via `mb_convert_encoding`) | Regex fails on attribute order, quotes, comments, entities |
| HTTP client with timeouts | Raw sockets/`file_get_contents` | Laravel `Http` / Guzzle with the options above | Redirect control, timeouts, `on_headers`, `progress` |
| Outbox, retries, backoff, push | Another notification path | Phase 3 `OutboxWriter` + `SendOutboxItem` + handler registry | Atomic claim, dedupe, send-time re-check already built |
| Role-by-endpoint testing | A second harness | `EndpointMatrix` + a `wishlist.php` file | Phase 2 "every route registered and declares access" tests are the gate |
| Plural forms for SK/PL day counts | String concatenation | `trans_choice` / vue-i18n pipe plurals with three forms (existing `TransChoiceTest` pattern) | Slovak and Polish plural rules |
| Race-safe "first buyer wins" | App-level check then insert | Unique constraint on `wish_reservations.wish_id` + catch `UniqueConstraintViolationException` | Check-then-insert races |
| Annual date maths | Month/day arithmetic scattered in controllers | One `AnnualDate::next()` with tests (Feb 29) | Single place for the leap rule |

**Key insight:** the only genuinely custom code is the privacy architecture (what may read which table) and the SSRF fetcher. Everything else should be existing Laravel/Phase 3 machinery; the risk is in subtle side channels, not in features.

## Common Pitfalls

### Pitfall 1: Side-channel leaks that no canary string catches
**What goes wrong:** the owner learns a wish is reserved through a count, a boolean, ordering, a changed `updated_at`/ETag, a different status code (blocked delete or edit), a request parameter that changes the result (`?available=1`), a timing-visible cascade, or a validation message.
**Why it happens:** reservation state influences non-reservation fields or behaviours.
**How to avoid:** separate tables, reservation-blind `Wish`, no `touches`, owner paths never branch on reservation state, params ignored on recipient branch, differential test (byte-identical responses before/after), query-log test (no reservation SQL on owner paths).
**Warning signs:** any owner-side code importing `WishReservation`; a `where`/`orderBy` on a reservation column in a shared query; a 409/422 on an owner mutation.

### Pitfall 2: Managers are recipients too
**What goes wrong:** a parent who manages a child's list sees reservations, or reserved before being made manager and still sees "reserved by you".
**How to avoid:** `ViewerContext` includes managed owner ids; every giver query excludes recipient-side owners; the manager-added event triggers `PurgeManagerReservations`; reminders exclude managers; the manager gets owner-style wish editing only where `wishlist_managers` allows it. Test: manager canary run (dataset `recipientSide` covers both).

### Pitfall 3: Shared Inertia `messages` and `flash` props
**What goes wrong:** a naive test asserting the word "reserved" never appears fails, or a developer "fixes" it by stripping lang keys. Conversely, flash data after a giver action must never be written to a session shared with another user (it is not, but do not put giver names in global flash for others).
**How to avoid:** canary on values (name, amount), not words.

### Pitfall 4: SSRF bypasses
**What goes wrong:** private ranges beyond RFC1918 reachable (100.64/10, 198.18/15, mapped IPv6), decimal/hex hosts, redirect to metadata (`169.254.169.254`), DNS rebinding between check and connect, env proxy, port scanning via 8080/22, `http://user@host`.
**How to avoid:** Pattern 5 (explicit denylist, regex FQDN, ports 80/443, resolve once and pin, manual redirects, no proxy). PHP's own flags were tested here: `FILTER_FLAG_NO_PRIV_RANGE|NO_RES_RANGE` returned "allowed" for 100.64.0.1, 192.0.0.1, 198.18.0.1, 224.0.0.1, 192.0.2.1, `::ffff:127.0.0.1`, `64:ff9b::7f00:1`, `2002:7f00:1::`. [VERIFIED: php -r run in this session]
**Warning signs:** use of `filter_var` as the only guard; `allow_redirects` enabled; `gethostbyname` called twice.

### Pitfall 5: Image decompression bomb and memory
**What goes wrong:** a small PNG declaring 30000x30000 px kills the worker or php-fpm (`memory_limit=128M`, app container 192m).
**How to avoid:** check dimensions from the header before decoding, 16 MP cap, file size cap, `ini_set('memory_limit')` is not a fix. A failed fetch sets `image_status=failed`, never retries the same URL immediately.

### Pitfall 6: Upload path not wired through nginx and PHP
**What goes wrong:** uploads over 1 MB fail with 413 (nginx default `client_max_body_size` 1m; no setting exists in `docker/nginx`), or over 2 MB with a PHP error (`php.ini-production` `upload_max_filesize` 2M; `php-prod.ini` sets neither value).
**How to avoid:** set `client_max_body_size 9m` only on the upload location, and `upload_max_filesize=8M`, `post_max_size=10M` in `docker/app/php-prod.ini` (and the dev nginx conf). Test the real stack in the staging smoke checks.

### Pitfall 7: No shared storage between web and worker, and no backup
**What goes wrong:** the worker writes fetched images into its own container filesystem, so the web container cannot serve them; images vanish on redeploy; backups omit them (`scripts/backup.sh` only streams `pg_dump` to restic; compose defines only the `pgdata` volume [VERIFIED]).
**How to avoid:** add an `uploads` named volume mounted at `storage/app/private/wish-images` for `app`, `worker` and `scheduler` (dev and server compose), correct ownership for `www-data` (prod) and uid 1000 `app` (dev), extend `backup.sh` and `restore-drill.sh` to include the volume (restic path), and mention staging has a separate volume.

### Pitfall 8: Scheduler outage and reminder timing
**What goes wrong:** reminders lost on a down day or sent twice or at 03:00.
**How to avoid:** hourly scan gated to local hour >= 8, one-day grace window, unique dedupe key, family timezone dates, fixed-clock tests (`Carbon::setTestNow`) including a DST change week.

### Pitfall 9: Birthday data privacy and Feb 29
**What goes wrong:** `birth_date` (with year) leaks via `auth.user`, member lists or `toArray`; Feb 29 birthdays vanish three years out of four.
**How to avoid:** `$hidden`, presenters send month/day only to other members, `AnnualDate` clamp; test the presenter shape and a 2028-02-29 / 2027-02-28 case.

### Pitfall 10: Name-day data mismatch
**What goes wrong:** the dataset changes (the Slovak calendar is reviewed yearly) and stored dates silently move, or a name is missing so the member cannot pick it.
**How to avoid:** occasion row stores a snapshot (calendar, name, month, day); picker is search-by-name with a fallback "custom occasion" so a missing name is never a blocker; data files carry `version` and `source`.

### Pitfall 11: Owner-facing validation messages and 404s
**What goes wrong:** `Rule::exists` or 404-vs-403 differences reveal reservations when owners post to giver endpoints.
**How to avoid:** the reserve/cancel/join endpoints return the same 403 for every recipient-side caller regardless of state; only giver-side callers can receive 409 conflict details.

## Code Examples

### Reservation insert with first-buyer-wins (Giving)
```php
// Source: design; Laravel UniqueConstraintViolationException (already used in Phase 3 TaskCompletionService)
public function reserve(User $giver, Wish $wish): WishReservation
{
    $this->guardGiver($giver, $wish->owner_id);            // LogicException if recipient-side; abort(403) for HTTP
    abort_if($wish->received_at !== null, 409);
    try {
        return DB::transaction(function () use ($giver, $wish) {
            $r = WishReservation::create(['wish_id' => $wish->id, 'user_id' => $giver->id, 'cobuy_state' => 'none']);
            $r->contributions()->create(['user_id' => $giver->id, 'amount_minor' => null]);
            return $r;                                     // never touch $wish
        });
    } catch (UniqueConstraintViolationException) {
        abort(409, 'already_reserved');                    // body names the existing buyer, only reachable by givers
    }
}
```

### Outbox enqueue for an occasion
```php
// Source: app/Notifications/Outbox/OutboxWriter.php (existing) used as in app/Console/Commands/EnqueueReminders.php
$outbox->enqueue(
    'wish_occasion', $giver, $channel, 'user_birthday', $owner->id,
    "wish_occasion:birthday:{$owner->id}:{$date}:{$lead}:{$giver->id}:{$channel}",
    ['type' => 'birthday', 'subject_id' => $owner->id, 'date' => $date, 'lead' => $lead],
);
```

### Meta image extraction (candidate order)
```php
// Source: design; DOM/XPath are stock PHP
$doc = new DOMDocument();
@$doc->loadHTML('<?xml encoding="UTF-8">'.mb_substr($html, 0, 524288), LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
$xp = new DOMXPath($doc);
$meta = fn (string $attr, string $name) => array_map(fn ($n) => trim($n->getAttribute('content')),
    iterator_to_array($xp->query("//meta[@$attr='$name']")));
$candidates = [...$meta('property','og:image:secure_url'), ...$meta('property','og:image'),
               ...$meta('name','twitter:image'), ...$meta('name','twitter:image:src'), ...$this->jsonLdProductImages($xp)];
// jsonLdProductImages: json_decode each <script type="application/ld+json">, walk @graph/arrays, @type Product (or in array),
// accept image as string | list<string> | ImageObject{url}. Resolve each against the final page URL, then fetch via SafeFetcher.
```

### Differential endpoint-matrix row (shape)
```php
// tests/Support/EndpointMatrix/wishlist.php  (literal expectations, never derived from middleware)
'wishlist.index' => ['method' => 'GET', 'uri' => '/wishlist',
   'expect' => ['guest' => 302, 'admin' => 403, 'parent' => 200, 'child' => 200, 'grandparent' => 200, 'guestPreset' => 403, 'noGrants' => 403]],
'wishlist.wishes.store' => ['method' => 'POST', 'uri' => '/wishlist/wishes', 'data' => ['title' => 'Matrix wish'],
   'expect' => ['guest' => 302, 'admin' => 403, 'parent' => 303, 'child' => 303, 'grandparent' => 303, 'guestPreset' => 403, 'noGrants' => 403]],
'wishlist.reservation.store' => [ 'method' => 'POST', 'uri' => '/wishlist/wishes/{wish}/reservation',
   'params' => fn ($u) => ['wish' => Wish::factory()->create(['owner_id' => $u['child']->id])->id], // parent reserves child's wish
   'expect' => [...]],
```
Note the guest preset has wishlist none (Preset::Guest), so wishlist routes are 403 for `guestPreset`, and `admin`/`noGrants` are 403 (no admin bypass). Matrix is route-level only; record-level rules (owner cannot reserve own wish, manager treated as recipient, reserver-only cancel) go in `tests/Feature/Wishlist/*`.

### Fixed-clock reminder test
```php
Carbon::setTestNow(CarbonImmutable::parse('2027-03-01 08:30', 'Europe/Bratislava'));   // birthday 2027-03-15 => lead 14 fires today
$this->artisan('occasions:enqueue'); $this->artisan('occasions:enqueue');               // idempotent
expect(OutboxItem::where('kind','wish_occasion')->count())->toBe($expectedGiverChannelPairs);
expect(OutboxItem::where('user_id', $owner->id)->where('kind','wish_occasion')->where('subject_id',$owner->id)->exists())->toBeFalse();
```

## State of the Art

| Old Approach | Current Approach | When Changed | Impact |
|--------------|------------------|--------------|--------|
| `filter_var(..., NO_PRIV_RANGE|NO_RES_RANGE)` as SSRF guard | Explicit CIDR denylist + resolve-once DNS pinning (`CURLOPT_RESOLVE`) + manual redirects | Long-standing guidance; confirmed insufficient flags in this session | Do not rely on PHP flags |
| `DOMDocument::loadHTML` only | `Dom\HTMLDocument` HTML5 parser (PHP 8.4) | PHP 8.4 | Not used: project targets PHP ^8.3 |
| Imagick-by-default image libs | GD re-encode is sufficient for thumbnails | n/a | Fewer moving parts in Alpine |
| Per-recipient "hidden" flags on the gift row | Separate reservation tables, reservation-blind model | design | Structural secrecy, testable by query log |

**Deprecated/outdated:** nothing in the chosen stack is deprecated. The Slovak official name-day calendar is reviewed yearly (next review noted for June 2026 in the data repo README), so the shipped file needs a `version` and a refresh task `[CITED: github.com/jozefgrencik/slovak-name-days]`.

## Assumptions Log

| # | Claim | Section | Risk if Wrong |
|---|-------|---------|---------------|
| A1 | Alpine packages `libjpeg-turbo(-dev) libpng(-dev) libwebp(-dev)` and `docker-php-ext-configure gd --with-jpeg --with-webp` build GD on `php:8.4-fpm-alpine` | Standard Stack | Image build fails; adjust packages. Prove in the first plan |
| A2 | Pest 4 arch has `toOnlyBeUsedIn` | Pattern 1 | Use only the `not->toUse` block (verified to exist) |
| A3 | `symfony/http-foundation` `IpUtils` is present (Laravel dependency) | Standard Stack | Trivial: add explicit CIDR helper |
| A4 | `NoPrivateNetworkHttpClient` checks the connected IP after connecting | Alternatives | Only affects why we reject it; not load-bearing |
| A5 | `CURLOPT_RESOLVE` bracket syntax for IPv6 | Pattern 5 | Avoided: IPv6-only hosts rejected |
| A6 | Guzzle `progress` callback throwing aborts a chunked download under the curl handler | Pattern 5 | Oversize bodies might not abort; fall back to a size-limited sink or `CURLOPT_MAXFILESIZE`-style check; the real-socket integration test decides |
| A7 | iOS Safari converts HEIC to JPEG for `accept="image/*"` uploads | Pattern 8 | iPhone users get "unsupported image"; add client canvas conversion |
| A8 | Shops return `og:image` to a non-browser User-Agent | Pattern 5 | Some links will fail; manual upload is the designed fallback |
| A9 | Feb 29 birthdays are observed on Feb 28 in non-leap years | Pattern 6 | Owner may prefer Mar 1; one constant |
| A10 | Givers (reservers, reminder recipients) are members with wishlist level >= edit; view-only members see reservations but cannot act | Architecture | Different giver definition changes recipient set and matrix |
| A11 | Contribution amounts are visible to all givers (not only participants) | Pattern 4 | Privacy preference; a presenter flag |
| A12 | Reservation retention after archive: 30 days, then purged | Pattern 9 | Owner may want history; table-only change |
| A13 | Reminder time 08:00 family-zone, hourly scan with one-day grace | Pattern 7 | Different send time; constant |
| A14 | Polish name days are best sourced from Polish Wikipedia monthly pages ("Imieniny - miesiąc") under CC BY-SA | Open Questions | Licensing or accuracy objection; owner decision needed |
| A15 | Inertia 3 supports polling a prop with `router.reload({ only })` on a timer for `image_status=pending` | UI | Minor; fall back to manual refresh |

## Open Questions

1. **Polish name-day source and licence (needs owner decision).**
   - What we know: Slovak has a clean source: `jozefgrencik/slovak-name-days` is MIT-licensed, 366 entries, derived from the official Ministry of Culture calendar approved June 2017 (files `data/names.json`, `names_by_month.json`, among others) [CITED: github.com/jozefgrencik/slovak-name-days]. For Poland there is no official calendar and no clean, machine-readable, permissively licensed dataset was found: `imieniny.sourceforge.io` is GPLv2, `DanRaiss/Nameday` is MIT-licensed code but states no data source, and Polish Wikipedia holds monthly pages (CC BY-SA, not fully read).
   - What's unclear: whether the owner accepts CC BY-SA attribution for a compiled Polish list (EU database-right and share-alike questions are low risk for a private family app but not zero), or prefers to supply their own list.
   - Recommendation: ship Slovak from the MIT file (include its LICENSE text and source note); for Polish, compile from Polish Wikipedia monthly pages with attribution recorded in `database/data/namedays/SOURCES.md`, treat it as best-effort, and keep the "custom occasion" fallback so a wrong or missing name is never blocking. Put this behind a `checkpoint:human-verify` task in the plan. Do not generate the Polish list from model memory.

2. **Where does the admin configure "may add wishes on a child's behalf" and manager status?**
   - What we know: no concept of guardian/manager exists in the codebase (verified: no matches in app/ or database/); Phase 2 permission levels are only none/view/edit per module.
   - Recommendation: `wishlist_managers` table, edited by admin only in Settings > Members (one multi-select "Can manage this member's wishlist" on the member row); the manager relationship doubles as the "treated like the recipient" rule. Add matrix rows (admin-only mutation, 403 for others).

3. **Does a view-only member count as a giver?** Recommendation (A10): no, giver = wishlist edit; view-only sees reservations read-only.

4. **"Bought" state** (sketch open question): not in requirements; skip in v1 (reservation means "I'm buying this"). Revisit with the owner.

5. **Where does a member enter their birth date?** Recommendation: in the Wishlist "My occasions" panel (own list, or a managed child's), plus admin via the member row; stored in `users.birth_date`, never shown with year to others.

6. **Admin cancelling someone else's reservation** (stuck reservation by a departed member): recommend no admin override in v1; the reserver's removal cascades via user deletion rules. Confirm with owner.

7. **Christmas reminders**: modelled as family-wide (everyone is a giver, link to `/wishlist`). Confirm this matches "the recipient never gets one" (for Christmas there is no single recipient).

## Environment Availability

| Dependency | Required By | Available | Version | Fallback |
|------------|------------|-----------|---------|----------|
| Docker | Test and dev stack | yes | 29.9.2 | - |
| PHP in app image | everything | yes | 8.4.27 (host 8.3.6) | run tests in container |
| ext-dom, libxml, fileinfo, curl, intl | HTML parse, MIME, HTTP, IDN | yes (image) | built in | - |
| ext-gd | image pipeline | NO (image and host) | - | none: add to Dockerfile (blocking, first task) |
| ext-exif | JPEG orientation | NO | - | skip orientation (bad UX) or add to Dockerfile (preferred) |
| libcurl | pinning, prereq callback | yes | 8.23.0, OpenSSL 3.5.9 | - |
| Uploads volume in compose (dev and server) | image storage shared by app and worker | NO | - | add (blocking) |
| Backup of uploads | data safety | NO (backup.sh is pg_dump only) | - | extend backup and restore drill |
| nginx `client_max_body_size`, php `upload_max_filesize` | uploads > 1 MB | NO (defaults 1m / 2M) | - | set (blocking for upload) |
| Stalwart SMTP | reminder mail | server-side (not probed) | - | `MAIL_MAILER=log` and `Mail::fake` in tests |
| Outbound internet from the worker container | fetching product pages | server-side (not probed) | - | job marks `failed`; manual upload |
| slopcheck | package audit | not needed | - | no new packages |

**Missing dependencies with no fallback:** gd/exif in the image, shared uploads volume, nginx/PHP upload limits. These are the first-wave infrastructure tasks and must land (and be rebuilt) before any image code or test that needs GD.
**Missing dependencies with fallback:** outbound internet and SMTP are verified on staging only (deferred human check, as in Phase 3 plan 12).

## Validation Architecture

### Test Framework
| Property | Value |
|----------|-------|
| Framework | Pest ^4.7 (PHPUnit 12.5) + Vitest with jsdom and axe-core |
| Config file | `phpunit.xml`, `tests/Pest.php`, `vitest.config.js` |
| Quick run command | `php artisan test --filter=Wishlist` (in the app container, GD required) / `npx vitest run tests/js/wishlist` |
| Full suite command | `php artisan test` and `npx vitest run` and `node scripts/check-sw-precache.mjs` after `npm run build` |

### Phase Requirements to Test Map
| Req ID | Behavior | Test Type | Automated Command | File Exists? |
|--------|----------|-----------|-------------------|-------------|
| WISH-01 | Create/update/delete wish, money parsing (comma decimals), validation, mass-assignment (`owner_id`, `image_*`, `received_at` rejected), manager/owner edit rules | feature | `php artisan test tests/Feature/Wishlist/WishCrudTest.php` | Wave 0 |
| WISH-01 | Endpoint matrix rows for every wishlist route | feature | `php artisan test tests/Feature/Access/EndpointMatrixTest.php` | exists; add `EndpointMatrix/wishlist.php` |
| WISH-02 | Up to 10 URLs, http/https only (no `javascript:`), label, reorder; rendered with `rel` attributes | feature + js | `php artisan test tests/Feature/Wishlist/WishUrlTest.php` | Wave 0 |
| WISH-03 | SafeFetcher unit matrix (all cases in Pattern 5), real-socket oversize/abort integration, meta/JSON-LD extraction fixtures, job status transitions, never in web request (`Queue::fake`) | unit + integration | `php artisan test tests/Unit/Wishlist/Fetch tests/Feature/Wishlist/FetchWishImageTest.php` | Wave 0 |
| WISH-03 | ImageProcessor: EXIF GPS stripped, orientation applied, 16 MP bomb rejected pre-decode, non-image/SVG/polyglot rejected, MIME from bytes, transparent PNG flattened | unit | `php artisan test tests/Unit/Wishlist/ImageProcessorTest.php` | Wave 0 |
| WISH-03 | Upload endpoint + authenticated serving: 403 without module access, headers `private, no-cache`, `nosniff`, ETag/304, no public URL | feature | `php artisan test tests/Feature/Wishlist/WishImageTest.php` | Wave 0 |
| WISH-04 | Arch test: Catalog/owner controllers never use reservation classes | arch | `php artisan test tests/Arch/WishlistPrivacyTest.php` | Wave 0 |
| WISH-04 | Differential + canary + zero-reservation-SQL for owner and manager over all surfaces; reserve routes 403 identical with/without reservation; no audit rows; no flash/props leak | feature | `php artisan test tests/Feature/Wishlist/ReservationPrivacyTest.php` | Wave 0 |
| WISH-04 | Giver view: sees buyer name, "reserved by you", summary excludes managed owners and archived; first-buyer-wins 409; manager purge job | feature | `php artisan test tests/Feature/Wishlist/ReservationTest.php` | Wave 0 |
| WISH-04 | Owner delete/edit/received succeed identically when reserved | feature | `php artisan test tests/Feature/Wishlist/OwnerActionsTest.php` | Wave 0 |
| WISH-05 | AnnualDate (leap year, Feb 29 to Feb 28, year rollover), occasion CRUD scoped to owner/manager, name-day search (diacritics-insensitive), birth_date hidden and month/day only to others | unit + feature | `php artisan test tests/Unit/Wishlist/Occasions tests/Feature/Wishlist/OccasionTest.php` | Wave 0 |
| WISH-05 | Name-day data files validate: 366 days, month/day valid, names non-empty, `version` and `source` present | unit | `php artisan test tests/Unit/Wishlist/NameDayDataTest.php` | Wave 0 |
| WISH-06 | `occasions:enqueue`: idempotent, lead days per member, grace window, recipient and managers excluded, prefs, push only with subscription, family-zone/fixed clock incl. DST week | feature | `php artisan test tests/Feature/Wishlist/OccasionReminderTest.php` | Wave 0 |
| WISH-06 | `WishOccasionHandler`: stillRelevant re-check, localized mail (sk/pl/en, plural forms), push title and relative url, no reservation text; `outbox:dispatch` double-run sends once | feature | `php artisan test tests/Feature/Notifications/WishOccasionOutboxTest.php` | Wave 0 |
| WISH-07 | Co-buy state machine, join/leave/amount, organizer-only transitions, closed blocks joins, cancel cascades, race (`UniqueConstraintViolation`), recipient-side 403 | feature | `php artisan test tests/Feature/Wishlist/CoBuyTest.php` | Wave 0 |
| UI | Gallery, panel, reservation box, co-buy box, occasion strip, own-list banner, settings lead days; axe in sk/pl/en light/dark; i18n parity incl. SK/PL plurals; own list DOM contains no status tags | js | `npx vitest run tests/js/wishlist tests/js/a11y-sweep.test.js tests/js/i18n-phase4.test.js` | Wave 0 |
| Infra | Image has gd/exif; compose has uploads volume in app/worker/scheduler; nginx and php upload limits; backup includes uploads | script | `scripts/check-compose.sh`, `scripts/check-nginx-template.sh`, extend backup tests | exists; extend |

### Sampling Rate
- **Per task commit:** the relevant Pest file or Vitest folder (< 30 s)
- **Per wave merge:** `php artisan test` + `npx vitest run`
- **Phase gate:** full suites green, `npm run build && node scripts/check-sw-precache.mjs`, and the privacy suite (`tests/Arch/WishlistPrivacyTest.php`, `ReservationPrivacyTest.php`) green before `/gsd-verify-work`

### Wave 0 Gaps
- [ ] Dockerfile `gd` + `exif`, rebuild dev image (blocks all image tests)
- [ ] `tests/Arch/WishlistPrivacyTest.php`, `tests/Feature/Wishlist/ReservationPrivacyTest.php` (written against factories before UI exists)
- [ ] Factories: `WishFactory` (+ states received, with image), `WishReservationFactory`, `WishlistManagerFactory`; a `FakeDnsResolver` and `AllowLoopbackIpPolicy` in `tests/Support`; tiny JPEG/PNG/GPS-EXIF/bomb fixtures in `tests/fixtures/images` generated by a script (not binary blobs from the internet)
- [ ] `tests/Support/EndpointMatrix/wishlist.php`
- [ ] Lang keys for all strings up front (sk/pl/en, plural forms), `tests/js/i18n-phase4.test.js`
- [ ] Manual-only (justified): real product-page fetch from the staging worker, real iPhone/iPad install + push for an occasion reminder, real Stalwart mail, upload of a real iPhone photo through nginx, visual check of the own-list view against sketch 005

## Security Domain

### Applicable ASVS Categories (Level 1)

| ASVS Category | Applies | Standard Control |
|---------------|---------|-----------------|
| V2 Authentication | no (Phase 2) | - |
| V3 Session Management | partly | Existing session/CSRF; image route is session-authenticated; no tokens in URLs |
| V4 Access Control | yes | `access:module,wishlist,*` on every route + `ViewerContext`/`WishAccess` record rules; recipient-side (owner and managers) never reach `Giving`; no admin bypass; matrix + record tests; arch test |
| V5 Input Validation | yes | FormRequests: enum priority, currency allowlist, money to minor units, lengths, URL scheme allowlist (http/https), max 10 URLs, occasion month/day via `checkdate`, `lead_days` 1..60 max 3 values; explicit `$fillable`, never mass-assign `owner_id`, `image_*`, `received_at`, `cobuy_state` |
| V6 Cryptography | no new use | none added |
| V7 Error handling/logging | yes | Log ids only; never log giver names, URLs with query strings, fetched HTML or reservation events; `last_error` class name only |
| V8 Data protection | yes | Private disk, authenticated route, `private, no-cache`, `nosniff`; birth year hidden; reservation data confined to Giving; no service-worker caching of images or props (precache-only) |
| V12 Files and resources | yes | Size and pixel caps, bytes-based type check, GD re-encode, random file names, no execution path (outside web root), orphan cleanup |
| V13 API / outbound | yes | SSRF guard, per-user throttle on upload and "load image from link", per-wish job dedupe |

### Known Threat Patterns for this stack

| Pattern | STRIDE | Standard Mitigation |
|---------|--------|---------------------|
| Recipient learns of reservation via count/flag/ordering/status code/param | Information Disclosure | Separate tables, reservation-blind model, no owner-path behaviour depends on reservations, differential + query-log tests |
| Manager (parent) of a child's list sees reservations | Information Disclosure | `wishlist_managers` treated as recipient-side; purge job; reminders exclude managers |
| Admin (who may be the recipient) sees reservations via audit or settings | Information Disclosure | Wishlist not audited; no admin reservation views; no admin bypass |
| SSRF through wish URL (metadata service, internal hosts, DNS rebinding, redirect) | Spoofing / Elevation | Pattern 5: denylist, pin, manual redirects, ports 80/443, no proxy, worker-only network access |
| SSRF response used to exfiltrate | Information Disclosure | Only bytes that decode as an image are stored; HTML never echoed; error text is a class name |
| Malicious image (bomb, polyglot, EXIF with GPS, SVG script) | DoS / Tampering / Info Disclosure | Pre-decode pixel cap, GD re-encode to JPEG, reject SVG, serve with `nosniff` |
| IDOR on wish id, image id, reservation id | Information Disclosure / Tampering | Module gate on every route; reservation actions check owner/giver rules server-side; cancel/transition only by organizer |
| Mass assignment | Tampering | FormRequest `validated()`, guarded models |
| Stored XSS via title, notes, URL label, javascript: URLs | Tampering | Vue text interpolation only (no `v-html`); URL scheme allowlist; `rel="noopener noreferrer nofollow"` and `target="_blank"` |
| Reservation race (double buy, double complete of co-buy) | Tampering | Unique `wish_id`, unique contribution pair, transactions with row locks |
| Reminder to revoked/unauthorized user, duplicate sends | Info Disclosure / Repudiation | Send-time `stillRelevant` re-check; dedupe key; Phase 3 atomic claim |
| Push/mail lock-screen leakage | Information Disclosure | Title names person and occasion only; no wish titles, no reservation info |
| Upload abuse (storage fill, CPU) | DoS | 8 MiB cap, throttle, per-wish image count of 1, GD pixel cap |

## Sources

### Primary (HIGH confidence)
- Codebase read in this session: `app/Access/*`, `app/Notifications/Outbox/*`, `app/Jobs/SendOutboxItem.php`, `app/Push/PushEndpointRule.php`, `app/Recurrence/RRuleRecurrenceEngine.php`, `app/Http/Middleware/{HandleInertiaRequests,NoStoreDynamicResponses}.php`, `app/Models/Task.php`, `config/{family,filesystems}.php`, `routes/console.php`, `routes/web/todo.php`, `tests/Support/{EndpointMatrix.php,Roles.php,EndpointMatrix/todo.php}`, `docker/app/{Dockerfile,php-prod.ini}`, `compose.yaml`, `compose.server.yaml`, `scripts/backup.sh`, Phase 3 RESEARCH and SUMMARY files, `01.1-MODULE-DESIGN-NOTES.md`, sketch skill `tasks-and-wishlist.md`
- `vendor/guzzlehttp/guzzle/src/Handler/CurlFactory.php` (supported curl options: `CURLOPT_RESOLVE`, `CURLOPT_CONNECT_TO`, `CURLOPT_PREREQFUNCTION`; protocols and redirects handled through request options)
- Container probe of `family-hub-app:dev` (`php -m`, PHP 8.4.27, libcurl 8.23.0)
- Local `php -r` test of `FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE` against 23 addresses
- Guzzle request-options docs (`on_headers`, `progress`, `allow_redirects`, `sink`, `timeout`): https://docs.guzzlephp.org/en/stable/request-options.html
- Slovak name days: https://github.com/jozefgrencik/slovak-name-days (MIT; official Ministry of Culture calendar approved June 2017; formats CSV/TSV/JSON/MD/XML; 366 entries)

### Secondary (MEDIUM confidence)
- https://github.com/DanRaiss/Nameday (MIT code, 20 countries incl. PL and SK, data source not stated)
- https://pl.wikipedia.org/wiki/Imieniny (monthly sub-pages exist; licence footer not captured, expected CC BY-SA)

### Tertiary (LOW confidence)
- imieniny.sourceforge.io listed as GPLv2 via web search (not opened); general knowledge of Docker GD recipe, iOS HEIC behaviour, Symfony `NoPrivateNetworkHttpClient` behaviour (all in the Assumptions Log)

## Metadata

**Confidence breakdown:**
- Standard stack: HIGH, no new packages; extension gaps verified directly in the image
- Architecture (privacy design): HIGH on soundness, MEDIUM on arch-test API details (A2)
- SSRF and image pipeline: MEDIUM-HIGH; pinning options verified in Guzzle source, abort semantics need the integration test (A6)
- Name-day data: HIGH for Slovak, LOW for Polish (open decision)
- Reminders/outbox: HIGH, directly follows Phase 3 code

**Research date:** 2026-11-13
**Valid until:** 2026-12-13 (stable stack); re-check the Slovak calendar version after its June review
