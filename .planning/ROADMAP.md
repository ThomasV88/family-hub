# Roadmap: Family Hub

## Overview

Family Hub is built as a vertical-slice MVP: each phase delivers a usable capability end to end. It starts with infrastructure and cross-cutting basics (deploy, i18n, PWA, backups), then the identity and permission core that every data module depends on. The owner's first module, the shared to-do list, comes next, and it also brings the recurrence engine and the notification outbox that later modules reuse. Wishlist, Finance and manual Medical records follow, with step-up security before the most sensitive data goes live. The AI platform comes last because it is the riskiest dependency and builds on the Medical documents: first a single-agent extraction flow with human review, then multi-agent routing, quota handling and bulk re-generation.

## Phases

**Phase Numbering:**
- Integer phases (1, 2, 3): Planned milestone work
- Decimal phases (2.1, 2.2): Urgent insertions (marked with INSERTED)

- [ ] **Phase 1: Foundation and Infrastructure** - Deployed staging/prod stack with i18n, PWA shell, encrypted tested backups and synthetic staging data
- [x] **Phase 01.1: Design System and App Shell (INSERTED)** - Minimalist design tokens, core Vue components, responsive app shell and chosen sketches for all modules (completed 2026-11-13)
- [ ] **Phase 2: Identity and Authorization Core** - Invite-only accounts, Google sign-in, per-module permissions with presets, deny-by-default policy layer and audit log
- [ ] **Phase 3: To-Do** - Shared and personal tasks with assignment, recurrence and email/push reminders
- [ ] **Phase 4: Wishlist** - Gift wishlists with link images, hidden reservations, occasions, co-buying and giver-only reminders
- [ ] **Phase 5: Finance** - Ledger, recurring expenses with expected-vs-actual, budgets, charts and CSV bank import
- [ ] **Phase 6: Sensitive-Data Account Hardening** - Second factor and idle re-authentication for medical and finance data
- [ ] **Phase 7: Medical Records** - Per-person records, documents, per-subject access, exports, emergency card and AI-consent flag, fully usable without AI
- [ ] **Phase 8: AI Extraction Platform** - Codex-powered document extraction in a locked-down worker, saved as reviewable proposals with a call log
- [ ] **Phase 9: Multi-Agent, Quota and Bulk Re-generation** - Agent order and model settings, Copilot fallback, quota handling and safe bulk re-generation

## Phase Details

### Phase 1: Foundation and Infrastructure
**Goal**: The app runs on the owner's VPS in separate staging and production environments, in three languages, installable as a PWA, with encrypted backups that are proven restorable.
**Mode:** mvp
**Depends on**: Nothing (first phase)
**Requirements**: FOUND-01, FOUND-02, FOUND-03, FOUND-04, FOUND-05
**Success Criteria** (what must be TRUE):
  1. Pushing to `develop` deploys to the staging URL and merging a PR to `main` deploys to production, both reachable through the existing edge proxy
  2. User can switch the UI between Slovak, Polish and English and sees correct plurals, dates and numbers for each locale
  3. User can install the app to the home screen on phone and iPad and use it on desktop, and the service worker caches only the app shell and never medical or finance data
  4. An encrypted database backup runs on a schedule and a restore from it has been performed successfully
  5. Staging holds only synthetic data, with no real medical or finance records
**Plans:** 6/12 plans executed
**UI hint**: yes

Plans:
- [x] 01-01-PLAN.md — Package approval, Laravel/Inertia/Vue scaffold and five-service dev Docker stack
- [x] 01-02-PLAN.md — Owner ops decisions (hostnames, RAM, retention, off-site, repo visibility) and DNS records
- [x] 01-03-PLAN.md — Heartbeat slice: scheduler -> database queue -> worker -> Postgres -> home page, "Run check now"
- [x] 01-04-PLAN.md — Server compose, TLS/PROXY-protocol nginx, cert, deploy.sh with guards and rollback, smoke test
- [x] 01-05-PLAN.md — Synthetic-only staging: staging:seed and staging:audit with production guards
- [x] 01-06-PLAN.md — Encrypted restic backups, restore drill, freshness check, cron schedule (proven locally)
- [ ] 01-07-PLAN.md — SK/PL/EN: locale resolution, switcher, Intl plurals, dates and numbers
- [ ] 01-08-PLAN.md — CI/CD workflow, secret scan, provisioning script, GitHub environments and secrets
- [ ] 01-09-PLAN.md — PWA: manifest, icons, precache-only service worker with build guard, no-store, update prompt
- [ ] 01-10-PLAN.md — Live server provisioning (both stacks, restic, cron) and edge map lines
- [ ] 01-11-PLAN.md — Staging go-live via develop, end-to-end verification, first restore drill
- [ ] 01-12-PLAN.md — Production release via PR to main, production restore drill, real-device checks

### Phase 01.1: Design System and App Shell (INSERTED)
**Goal**: The whole app shares one minimalist, clean visual language and a responsive shell, so every later module is built from the same components and navigation instead of inventing its own UI.
**Mode:** mvp
**Depends on**: Phase 1
**Requirements**: None new (supports FOUND-02 and FOUND-03 through UX; informed by `/gsd-sketch` findings)
**Success Criteria** (what must be TRUE):
  1. A documented set of design tokens (colors, typography, spacing, radius) exists and the UI switches between light and dark theme
  2. A core Vue component library (buttons, inputs, forms, lists, dialogs, tables, empty and error states) is available and shown on a living style page
  3. The app shell with navigation works on phone, desktop and iPad, with the module list adapting to each layout and to the Slovak, Polish and English text lengths
  4. Keyboard navigation, visible focus, contrast and touch target sizes meet the accessibility baseline on the style page and the shell
  5. A sketch for every module (to-do, wishlist, finance, medical, settings) has been chosen and recorded as the design direction for later phases
**UI hint**: yes
**Plans:** 7/7 plans complete

Plans:
- [x] 01.1-01-PLAN.md — Package approval (@lucide/vue, axe-core), AA-verified tokens, System/Light/Dark theme without inline script
- [x] 01.1-02-PLAN.md — Non-production /_style living style page, tokens with contrast, UiButton, all phase copy in SK/PL/EN
- [x] 01.1-03-PLAN.md — Form controls (field, input, textarea, select, checkbox, radio, switch) and per-module design notes
- [x] 01.1-04-PLAN.md — Tags, avatars, chips, cards, empty/error states, alerts, skeleton, progress, lists and responsive tables
- [x] 01.1-05-PLAN.md — Dialogs, destructive confirm, detail panel (sheet/overlay/docked), menu, tabs, toasts
- [x] 01.1-06-PLAN.md — Responsive app shell: bottom bar + FAB, toggleable sidebar, account menu, skip link, coming-soon panel
- [x] 01.1-07-PLAN.md — Overview dashboard with quick add, restyled PWA banner/switcher, accessibility sweep, end-of-phase verification

### Phase 2: Identity and Authorization Core
**Goal**: Family members can join by invitation and sign in, and every piece of data is guarded server-side by per-member, per-module permissions that the admin controls.
**Mode:** mvp
**Depends on**: Phase 01.1
**Requirements**: AUTH-01, AUTH-02, AUTH-03, AUTH-04, AUTH-05, AUTH-06, AUTH-07, AUTH-08
**Success Criteria** (what must be TRUE):
  1. Admin invites a member by email, and the invitee can register only through that invitation
  2. Member can sign in with email and password or with Google, stays signed in across browser refresh, and an admin can revoke that member's sessions
  3. Admin can set no access, view or edit per member per module, start from a preset (Parent, Child, Grandparent, Guest) and adjust it
  4. A member without a grant cannot read or change a module's data through any endpoint, proven by an automated role-by-endpoint test matrix
  5. Admin can view an audit log of access to and changes of sensitive data
**Plans:** 9/10 plans executed
**UI hint**: yes

Plans:
- [x] 02-01-PLAN.md — Package approval (Fortify, Socialite), headless Fortify with no registration, full SK/PL/EN Phase 2 copy
- [x] 02-02-PLAN.md — First admin command, email+password sign-in with lockout, 30-day remember, logout, Login page and account menu
- [x] 02-03-PLAN.md — Deny-by-default access layer (grants, presets, requireAccess, fail-closed guard), role-by-endpoint matrix, 403 page, access-aware nav
- [x] 02-04-PLAN.md — Enumeration-safe password reset that signs out other devices, SessionRevoker, Settings shell and Appearance page
- [x] 02-05-PLAN.md — Append-only audit log (health/finance auto-audit incl. denied, auth events, 2-year prune) and the admin audit page
- [x] 02-06-PLAN.md — Members and access: member panel with presets and per-module none/view/edit, audited atomic save, permission matrix
- [x] 02-07-PLAN.md — Invites: hashed single-use 7-day tokens, queued mail with failure flag, resend/copy (rotating)/revoke, invite dialog
- [x] 02-08-PLAN.md — Member sessions: list devices, revoke one or all (rows deleted + remember token rotated), audited
- [x] 02-09-PLAN.md — Invite acceptance (password, race-safe) and Google sign-in linked by verified identity, no self-signup
- [ ] 02-10-PLAN.md — DEFERRED with owner: Stalwart mailbox, Google OAuth clients, staging/production rollout, admin creation, audit cron

### Phase 3: To-Do
**Goal**: The family can track shared and personal tasks together, including recurring ones, and gets reminded when tasks are due.
**Mode:** mvp
**Depends on**: Phase 2
**Requirements**: TODO-01, TODO-02, TODO-03, TODO-04, TODO-05, TODO-06
**Success Criteria** (what must be TRUE):
  1. User can create a task with title, notes, due date and status, and assign it to a family member
  2. User can mark a task personal (only they see it) or shared with the family or chosen members, and others see only what they are permitted to
  3. User can create daily, weekly, monthly or custom recurring tasks, recurring from the schedule or from completion, and month-end and daylight-saving changes produce correct due dates
  4. User receives due-task reminders by email and by push, with push working on iOS for the installed PWA
**Plans:** 12 plans
**UI hint**: yes

Plans:
- [ ] 03-01-PLAN.md — All Phase 3 copy up front: todo/notifications in SK/PL/EN (pipe plurals) and server reminder strings
- [ ] 03-02-PLAN.md — Package approval (php-rrule, web-push), install, ext-curl check, spike tests pinning clamp/DST and web-push API
- [ ] 03-03-PLAN.md — Board slice: tasks schema, visibleTo scope, person columns, filter chips, quick add on board and Overview, nav link
- [ ] 03-04-PLAN.md — Reusable RecurrenceEngine (month-end clamp, DST wall-clock, schedule vs completion) and ReminderTimeCalculator
- [ ] 03-05-PLAN.md — Task detail panel: create/edit/assign/status/delete with server-side edit rights, card checkbox
- [ ] 03-06-PLAN.md — Overview Due-soon card and shell add entry points (FAB/topbar to /todo?task=new), edit-gated
- [ ] 03-07-PLAN.md — Email reminders: remind_at observer, notification outbox, reminders:enqueue, SendOutboxItem with retry/dedupe
- [ ] 03-08-PLAN.md — Visibility: Personal/Family/Selected in the panel, creator-only changes, admin no-bypass privacy tests
- [ ] 03-09-PLAN.md — Recurring tasks in the panel: repeat fields, complete/skip with next occurrence, history, reminder choice
- [ ] 03-10-PLAN.md — Push delivery: subscriptions with SSRF-safe endpoints, WebPushSender, push:vapid, outbox push channel, SW handlers
- [ ] 03-11-PLAN.md — Settings > Notifications: channel switches, per-device push, iOS install hint
- [ ] 03-12-PLAN.md — DEFERRED with owner: VAPID keys per environment, staging/production release, real iPhone push and email checks

### Phase 4: Wishlist
**Goal**: Each member keeps a gift wishlist that relatives can reserve from and chip in on, without the recipient ever seeing reservations.
**Mode:** mvp
**Depends on**: Phase 3 (notification outbox)
**Requirements**: WISH-01, WISH-02, WISH-03, WISH-04, WISH-05, WISH-06, WISH-07
**Success Criteria** (what must be TRUE):
  1. Member can add wishes with title, notes, price and size/variant notes, each with multiple URLs
  2. A wish shows a product image loaded automatically from its link in the background, and the member can upload an image manually if none is found
  3. Another member can reserve a gift and chip in with others, and the reservation is visible to other givers but never to the recipient in any view, count, badge, notification, export or search
  4. Birthdays, Slovak and Polish name days, Christmas and custom occasions are tracked per member
  5. Gift-givers receive reminders ahead of an occasion and the recipient never does
**Plans:** 13 plans
**UI hint**: yes

Plans:
- [ ] 04-01-PLAN.md — All Phase 4 copy up front: wishlist/occasions/gift reminders/managers in SK/PL/EN (pipe plurals) and server reminder strings
- [ ] 04-02-PLAN.md — Infrastructure: gd + exif in the image, shared uploads volume (dev and server), upload limits on the upload route, uploads as a separately tagged snapshot in backup, off-site copy, restore drill and freshness
- [ ] 04-03-PLAN.md — Name-day data: SK vendored (MIT), PL compiled from Polish Wikipedia with provenance, validation and a stop-and-return gate (checkpoint on failure); AnnualDate and NameDayCalendar
- [ ] 04-04-PLAN.md — SafeFetcher (explicit denylist, resolve-once DNS pinning, manual redirects, byte caps, real-socket test) and MetaImageExtractor
- [ ] 04-05-PLAN.md — Wish slice backend: schema, Catalog, ViewerContext/WishAccess, list and wish endpoints, wishlist managers, matrix, privacy arch test
- [ ] 04-06-PLAN.md — Wish slice UI: Wishlist page, person chips, own and giver galleries, owner panel with links, giver panel, nav, managers tab, owner DOM privacy test
- [ ] 04-07-PLAN.md — Product images: worker fetch from links, hardened upload pipeline, authenticated serving with ETag, panel controls and polling
- [ ] 04-08-PLAN.md — Giving backend: reservations and co-buying in separate tables, giver list branch, shopping endpoint, manager purge, differential/canary/query-log leak tests
- [ ] 04-09-PLAN.md — Occasions: birth dates and name days per member, custom occasions, Christmas, OccasionCalendar, Occasions screen and panel
- [ ] 04-10-PLAN.md — Giver UI: status tags, ReservationBox with co-buy and share form, conflict alert, Your shopping summary
- [ ] 04-11-PLAN.md — Giver-only occasion reminders on the outbox (mail, push), lead-day settings card, wishlist:prune retention
- [ ] 04-12-PLAN.md — Occasion strip on the wishlist page and Upcoming occasions card on the Overview
- [ ] 04-13-PLAN.md — DEFERRED with owner: staging/production release, server image/volume/backup checks, real shop images, device privacy and reminder checks

### Phase 5: Finance
**Goal**: The family can see where money goes, with exact amounts, recurring expenses tracked against reality, budgets, charts and bank statement import.
**Mode:** mvp
**Depends on**: Phase 3 (recurrence engine), Phase 2
**Requirements**: FIN-01, FIN-02, FIN-03, FIN-04, FIN-05, FIN-06, FIN-07, FIN-08
**Success Criteria** (what must be TRUE):
  1. User can record income and expenses with amount, currency, date, account and category, and amounts are stored and displayed exactly with no rounding errors
  2. Admin can choose which members see which accounts, and others never see those accounts or their transactions
  3. User can define recurring expenses (rent, savings, kindergarten, energy and so on), see an upcoming-bills view, and see each one matched as expected versus actual against recorded or imported transactions
  4. User can set monthly category budgets, see progress, and view charts of spending by category and balance over months
  5. User can import a Slovak or Polish bank CSV (Windows-1250, semicolons, decimal comma) with a saved per-bank mapping, review step and duplicate detection, and can undo the import
**Plans:** 20/21 plans executed
**UI hint**: yes

Plans:
- [x] 05-01-PLAN.md — All Phase 5 copy up front (finance.*, settings section, audit details) in SK/PL/EN with pipe plurals and decision deltas (2 MB, encodings, indicator mode, immutable-field error, budget toasts)
- [x] 05-02-PLAN.md — Exact money core (TDD): Currency exponents, strict MoneyParser, TextNormalizer, money.js + MoneyAmount via Intl, no-float scan
- [x] 05-03-PLAN.md — RecurrenceEngine::between and pure Matcher/StatusResolver (TDD)
- [x] 05-04-PLAN.md — Finance data core: accounts/members/categories/transactions schema, Viewer + Visibility, AccountQuery, arch/file-scan/mutation-scope rules, leak net (differential, canary, query-log, write parity, scoped-mutation check)
- [x] 05-05-PLAN.md — CSV decode/parse (TDD): config/finance.php, iconv decoding, fgetcsv, strict dates, synthetic fixtures
- [x] 05-06-PLAN.md — CSV map/hash (TDD): generic profiles, single/split/indicator amounts, dedupe hashes, 20+ variant pipeline test
- [x] 05-07-PLAN.md — Admin account visibility: Settings > Finance accounts (metadata only), membership audit event
- [x] 05-08-PLAN.md — Transactions backend: FinancePage props, scoped list/filters (one page per request), two-leg transfers editing only visible legs, immutable account/kind, hidden-counterpart read and write parity, Accounts page backend
- [x] 05-09-PLAN.md — Finance shell and Transactions UI: chips, month switcher, tabs, list, panel/form, Accounts page
- [x] 05-10-PLAN.md — Recurring backend: schema with same-account composite FKs, CRUD with immutable account, derived statuses, calendar/list/still-to-pay/Home counts
- [x] 05-11-PLAN.md — Import staging backend: preview JSON, staged draft batches, duplicates and probable duplicates, profiles, nginx limit
- [x] 05-12-PLAN.md — Budgets backend: versioned category budgets, DisplayKind helper, viewer-scoped progress, budget write routes
- [x] 05-13-PLAN.md — Matching: auto-match on save/backfill, record payment, manual match/unmatch with rejections
- [x] 05-14-PLAN.md — Recurring UI: still-to-pay card, payment calendar, list, day/bill/recurring panels
- [x] 05-15-PLAN.md — Overview and Home backend: per-currency aggregates in SQL, Overview route, Home card counts
- [x] 05-16-PLAN.md — Import wizard UI: file, columns (live preview), review, done, history
- [x] 05-17-PLAN.md — Overview UI: summary cards, budgets, SVG charts with table alternatives, Home card, nav entry
- [x] 05-18-PLAN.md — Import commit/undo: idempotent commit, reconcile, match, keep-and-detach undo, daily prune
- [x] 05-19-PLAN.md — Categories: family-wide list, rename, delete-with-move (no usage counts, no edited_at change)
- [x] 05-20-PLAN.md — Phase-wide proofs: leak-suite completeness and mutation sanity, admin privacy, no-store, SW, staging audit, axe sweep, validation sign-off
- [ ] 05-21-PLAN.md — DEFERRED with owner: image rebuild (bcmath), staging/production release, device privacy/display checks, owner bank CSVs, SK/PL wording review

### Phase 6: Sensitive-Data Account Hardening
**Goal**: Medical and finance data are protected by a second factor and idle re-authentication before the most sensitive module goes live.
**Mode:** mvp
**Depends on**: Phase 2
**Requirements**: AUTH-09, AUTH-10
**Success Criteria** (what must be TRUE):
  1. User can enable a TOTP app or passkey as a second factor and must use it at sign-in
  2. After a period of inactivity, user is asked to re-authenticate before opening medical or finance data, and other modules are not interrupted
**Plans:** 12/13 plans executed
**UI hint**: yes

Plans:
- [x] 06-01-PLAN.md — Factor storage (2FA columns, passkeys, app_settings, password_set), User contracts, security audit events and listener, second-factor policy
- [x] 06-02-PLAN.md — Session windows (idle 15 min, security confirm 10 min), SafeIntended, access:recent rule, per-member limiters, test seam
- [x] 06-03-PLAN.md — All Phase 6 copy in SK/PL/EN with plural tests; OtpInput, RecoveryCodeInput, PasskeyButton, WebAuthn and securityFetch helpers
- [x] 06-04-PLAN.md — Idle gate inside RequireAccess for finance/health and the /confirm-access page (password, authenticator code)
- [x] 06-05-PLAN.md — Fortify two-factor headless: sign-in challenge for any factor, hardened 2FA endpoints, sign-in counts as confirmation
- [x] 06-06-PLAN.md — Settings > Security: authenticator setup dialog, recovery codes, turn off and new codes with in-dialog confirmation
- [x] 06-07-PLAN.md — Admin requirement switch, member factor status and audited admin reset with session revocation
- [x] 06-08-PLAN.md — Passkey backend: registration/management, challenge and re-auth ceremonies behind one adapter
- [x] 06-09-PLAN.md — Passkey UI: Passkeys card, name/rename/remove, "Use a passkey" on challenge and confirm form
- [x] 06-10-PLAN.md — Google sign-in through the challenge, flag-gated Google re-auth fallback (no-password, no-factor members only), password_set maintenance
- [x] 06-11-PLAN.md — Matrix-driven idle-gate proof with naming safety net, sign-in end-to-end checks, import wizard 423 handling
- [x] 06-12-PLAN.md — Audit log security rows, canary/arch/no-store/SW proofs, axe sweep, ops notes, validation sign-off
- [ ] 06-13-PLAN.md — DEFERRED with owner: staging/production release, real-device passkey and authenticator UAT, Google re-auth check, owner decisions

### Phase 7: Medical Records
**Goal**: The family can keep each person's medical history, documents and exports in one private place, fully usable by hand without any AI.
**Mode:** mvp
**Depends on**: Phase 6, Phase 2
**Requirements**: MED-01, MED-02, MED-03, MED-04, MED-05, MED-06, MED-07, MED-08, MED-09, MED-10, MED-11
**Success Criteria** (what must be TRUE):
  1. User can create a person profile for someone who is not an app user, and admin can grant access per person so a member sees only the people they are permitted to
  2. User can record doctor visits (date, doctor, specialty from a taxonomy, diagnosis, notes), medications, allergies and vaccinations, and gets a reminder when the next vaccination is due
  3. User can upload PDFs and photos including HEIC, attach them to a person and optionally a visit, and only authorized users can open the files
  4. User can export records by specialty, export everything for one person, and export in an AI-friendly format in which unverified AI-extracted data is labeled as such
  5. Each person has a one-screen emergency card with allergies, medications, blood type and contacts, and an AI-consent flag that is off by default
**Plans**: TBD
**UI hint**: yes

### Phase 8: AI Extraction Platform
**Goal**: A user can have a medical document read by AI into structured data and review it side by side with the document before anything is saved to the record.
**Mode:** mvp
**Depends on**: Phase 7
**Requirements**: AI-01, AI-02, AI-03, AI-04
**Success Criteria** (what must be TRUE):
  1. User can start AI extraction on a document on demand, and only if that person's AI-consent flag is on, and the job runs in the background in a locked-down worker using Codex CLI through its subscription login with no per-token API
  2. Extraction returns data in the exact JSON structure, validated against a versioned schema, and appears as a proposal that is not applied to the record
  3. User reviews the proposal side by side with the document and can accept, edit or reject it, and only then does the data enter the record
  4. Every call to an external AI is recorded with who, document, agent, model and time, and admins can see it
**Plans**: TBD
**UI hint**: yes

### Phase 9: Multi-Agent, Quota and Bulk Re-generation
**Goal**: Admin controls which AI agents are used and in what order, extraction survives exhausted usage limits, and past documents can be re-processed safely in bulk.
**Mode:** mvp
**Depends on**: Phase 8
**Requirements**: AI-05, AI-06, AI-07
**Success Criteria** (what must be TRUE):
  1. Admin can set the agent order (Codex, then GitHub Copilot) and the model per agent in settings, and sees each agent's login health
  2. When an agent's usage limit is exhausted, the job falls back to the next agent or waits until the limit resets, and the user sees the job's status
  3. Admin can start bulk re-generation for past documents with a dry run first and a daily cap, and it never overwrites data a human already accepted
**Plans**: TBD
**UI hint**: yes

## Progress

**Execution Order:**
Phases execute in numeric order: 1 → 2 → 3 → 4 → 5 → 6 → 7 → 8 → 9

| Phase | Plans Complete | Status | Completed |
|-------|----------------|--------|-----------|
| 1. Foundation and Infrastructure | 6/12 | In Progress|  |
| 2. Identity and Authorization Core | 9/10 | In Progress|  |
| 3. To-Do | 0/TBD | Not started | - |
| 4. Wishlist | 0/TBD | Not started | - |
| 5. Finance | 20/21 | In Progress|  |
| 6. Sensitive-Data Account Hardening | 12/13 | In Progress|  |
| 7. Medical Records | 0/TBD | Not started | - |
| 8. AI Extraction Platform | 0/TBD | Not started | - |
| 9. Multi-Agent, Quota and Bulk Re-generation | 0/TBD | Not started | - |
