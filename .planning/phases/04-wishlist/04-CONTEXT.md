# Phase 4: Wishlist - Context

**Gathered:** 2026-11-13
**Status:** Ready for planning

<domain>
## Phase Boundary

Each member keeps a gift wishlist that relatives can reserve from and chip in on, without the recipient ever seeing reservations. Includes wishes with multiple URLs, automatic product image loading, manual image upload, reservations and co-buying, occasions (birthdays, SK/PL name days, Christmas, custom) and giver-only reminders via the Phase 3 notification outbox.
</domain>

<decisions>
## Implementation Decisions

### Wishes and sharing
- Wish fields: title, notes, price (amount + currency, default EUR), size/variant notes, priority, multiple URLs (optional label), image.
- Everyone with wishlist view access sees every member's list. Owners edit their own list; a member with an edit grant may add wishes on a child's behalf if the admin allows it.
- Card gallery per member (sketch 005), the viewer's own list first.
- Product image: fetched in the worker from og:image/twitter:image with SSRF guard, timeout and size cap, never in the web request; manual upload fallback (stored outside web root, served via an authenticated route, EXIF stripped, MIME sniffed from bytes).

### Reservations and chip-in (privacy core)
- Any member except the recipient can mark "I'm buying this"; visible with name to other givers, NEVER to the recipient in any view, count, badge, notification, export, search or API response.
- A parent/manager who manages a child's list is treated like the recipient and never sees reservations on it.
- A reserved gift can be opened for co-buying: other givers join with an optional amount; the organizer closes it.
- Enforcement: reservation data in separate tables; owner/manager endpoints never select reservation columns; the endpoint matrix and dedicated leak tests prove no leak via list, detail, counts, search, export, notifications and shared Inertia props.

### Occasions and reminders
- Occasions: birthdays (member birth date), Slovak and Polish name days (static calendar tables per locale; member picks which applies), Christmas, custom occasions with a date.
- Givers get reminders 14 days and 3 days ahead (configurable per member) via the Phase 3 notification outbox (email and push); the recipient never gets one. Reminder text names the person and occasion and links to the list; it never mentions reservations.
- After an occasion, reserved wishes stay until the owner marks them received, then they archive.

### Claude's Discretion
Table and class names, name-day dataset source (verify license; ship as versioned static data), UI details following the Phase 01.1 design system and sketch 005, test organisation.
</decisions>

<code_context>
## Existing Code Insights

### Reusable Assets
- Phase 2 policy layer (AccessGate, access middleware, endpoint matrix harness, audit); Phase 3 notification outbox (generic kinds, dedupe_key, handlers), recurrence engine (annual occasions), push + mail channels, notification preferences; Phase 01.1 Ui* components and AppShell; Phase 1 worker/scheduler containers.
- SSRF-safe endpoint validation pattern from push subscriptions (Phase 3).

### Established Patterns
- Inertia pages, per-feature route files and EndpointMatrix files, lang keys added up front in one plan, Vitest + axe sweeps in SK/PL/EN and light/dark, no-store dynamic responses, precache-only service worker (never cache private data).

### Integration Points
- modules.js (wishlist nav entry), Home dashboard, settings notifications, outbox handler registry, scheduler.
</code_context>

<specifics>
## Specific Ideas

Sketch 005: wishlist card gallery; own list hides reservations.
</specifics>

<deferred>
## Deferred Ideas

Price tracking, shop integrations, gift ideas suggestions, per-list sharing rules.
</deferred>
