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

### Milestone v1.1 (started 2026-11-18)

- [x] **Phase 14: Rebranding (issue #13)** - FamilyHUB logo, favicons, PWA icons and brand colour tokens from the supplied brand kit
- [x] **Phase 15: Bulk medical document upload (issue #14)** - Resumable server-side batches with sequential AI processing, per-finding review, re-scan and AI-copy image adjustments

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
**Plans:** 20 plans
**UI hint**: yes

Plans:
- [ ] 07-01-PLAN.md — All Health copy (EN/SK/PL, 30 specialties, notifications) and the six medical audit events
- [ ] 07-02-PLAN.md — Medical disk and volume, scoped nginx upload limit, /medical.tar backups, freshness and restore drill
- [ ] 07-03-PLAN.md — Person slice: subjects and members, two-branch Visibility scope, create/open person, identical 403
- [ ] 07-04-PLAN.md — Health nav link, static Home card, medical audit rows, clearHistory on idle lock
- [ ] 07-05-PLAN.md — Doctor visits with the 30-key taxonomy, specialty folders and timeline
- [ ] 07-06-PLAN.md — Medical leak net: harness, differential/canary/query-log, write parity, arch and mutation scans
- [ ] 07-07-PLAN.md — Per-person access admin on Settings > Members (grant, link, own-record switch, access:recent)
- [ ] 07-08-PLAN.md — Allergies side card and panel
- [ ] 07-09-PLAN.md — AI-consent flag with consent dialog, transactional audit and AiConsent::allows
- [ ] 07-10-PLAN.md — Document upload pipeline: byte sniffing, PDF/HEIC rules, sanitized images, medical disk
- [ ] 07-11-PLAN.md — Medications side card and panel
- [ ] 07-12-PLAN.md — Document delivery and management, cache split, orphan sweep, transfer helpers
- [ ] 07-13-PLAN.md — Vaccinations with due status, mark as given, alert and timeline entries
- [ ] 07-14-PLAN.md — Documents on the person page: uploader, panel, previews, attach to visits
- [ ] 07-15-PLAN.md — Vaccination due reminders through the outbox (title-only push, vaccine-free mail)
- [ ] 07-16-PLAN.md — Exports: print view, JSON and AI Markdown with unverified labeling, audit first, never stored
- [ ] 07-17-PLAN.md — Person edit/delete with emergency contacts and header actions
- [ ] 07-18-PLAN.md — Emergency card (view level, audited, printable, no public link)
- [ ] 07-19-PLAN.md — Leak-suite completeness, cache/audit/staging/SW/a11y sweeps, validation sign-off
- [ ] 07-20-PLAN.md — Live release, server and device checks, owner decisions (deferred, with owner)

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
**Plans:** 23 plans
**UI hint**: yes

Plans:
- [ ] 08-01-PLAN.md — All Phase 8 copy (EN/SK/PL, plurals, checker wording) and three content-free AI audit events
- [ ] 08-02-PLAN.md — Owner approval of @openai/codex 0.169.0 and poppler-utils; poppler in base, Codex only in the ai-worker image
- [ ] 08-03-PLAN.md — Extraction schema v1, server-side validator and normaliser, entry keys, failure codes, static untrusted-data prompt
- [ ] 08-04-PLAN.md — Proposal, decision and call-log tables (lifecycle guard, definer prune), agent status, notice acks, leak-net registration
- [ ] 08-05-PLAN.md — CodexRunner seam (env -i, setsid, group kill, caps), login-status classification, fake CLI, AI arch rules
- [ ] 08-06-PLAN.md — Record queries accept source 'ai', scoped verify transition, one shared rule set per record type
- [ ] 08-07-PLAN.md — ai-worker service, env split, codex-home isolation checks, deploy/smoke/CI, runbook
- [ ] 08-08-PLAN.md — PdfRasterizer, tmpfs work dir, ai:preflight (subscription-only startup)
- [ ] 08-09-PLAN.md — Verify routes for AI records and provenance on person-page record rows
- [ ] 08-10-PLAN.md — Agent registry with the Codex agent, outcome classification, login health and check job
- [ ] 08-11-PLAN.md — PDF page-image route for side-by-side review (no-store, identical 403)
- [ ] 08-12-PLAN.md — Extraction job: claim, run-time consent/access recheck, abort, supersede on ready, cleanup, leak tests
- [ ] 08-13-PLAN.md — Run, cancel and status routes with consent, notice and availability checks; person-page AI props
- [ ] 08-14-PLAN.md — Admin AI calls backend (names only), check-again, schedules for check/reap/prune
- [ ] 08-15-PLAN.md — Review page props and atomic, idempotent accept / edit-accept / reject / undo
- [ ] 08-16-PLAN.md — Run AI control, one-time OpenAI notice, status states and polling in the document panel
- [ ] 08-17-PLAN.md — Settings > AI calls page with login health card, filters and table
- [ ] 08-18-PLAN.md — Side-by-side review screen with PDF pager and per-entry accept / reject / undo
- [ ] 08-19-PLAN.md — Edit-in-place suggestion forms with server error flagging and discard rules
- [ ] 08-20-PLAN.md — AI-extracted / Unverified / Verified tags on records and "Mark as verified"
- [ ] 08-21-PLAN.md — Leak-suite completeness, privacy differentials, cache/SW/staging/a11y sweeps, validation sign-off
- [ ] 08-22-PLAN.md — Live: owner decisions and data-use check, real Codex login per stack, one real run, release (deferred, with owner)
- [ ] 08-23-PLAN.md — AI status tags on document rows and removal of the obsolete Phase 7 consent line

### Phase 9: Multi-Agent, Quota and Bulk Re-generation
**Goal**: Admin controls which AI agents are used and in what order, extraction survives exhausted usage limits, and past documents can be re-processed safely in bulk.
**Mode:** mvp
**Depends on**: Phase 8
**Requirements**: AI-05, AI-06, AI-07
**Success Criteria** (what must be TRUE):
  1. Admin can set the agent order (Codex, then GitHub Copilot) and the model per agent in settings, and sees each agent's login health
  2. When an agent's usage limit is exhausted, the job falls back to the next agent or waits until the limit resets, and the user sees the job's status
  3. Admin can start bulk re-generation for past documents with a dry run first and a daily cap, and it never overwrites data a human already accepted
**Plans:** 20 plans
**UI hint**: yes

Plans:
- [ ] 09-01-PLAN.md — All Phase 9 copy (EN/SK/PL, plurals, checker wording) and two content-free audit events
- [ ] 09-02-PLAN.md — @github/copilot 1.0.112 pin with legitimacy evidence, ai-image-only stage, copilot-home, queue order, check-compose rules
- [ ] 09-03-PLAN.md — ai_agent_settings, waiting status, per-attempt call rows, bulk tables, notice provider set (three migrations)
- [ ] 09-04-PLAN.md — Phase 9 config with PROVISIONAL defaults, model-id rule, failure codes, quota signature matcher
- [ ] 09-05-PLAN.md — Strict JSON reply parsing, content-free validator issue codes, inline-schema prompt with repair block
- [ ] 09-06-PLAN.md — Codex quota detection with reset time, admin model override, deadline-bound calls
- [ ] 09-07-PLAN.md — CopilotRunner (per-run home, layered tool denial), CopilotAgent with one repair, FakeCopilot, arch rules
- [ ] 09-08-PLAN.md — Preflight refuses GitHub/Copilot tokens and checks the Copilot install; work-dir root guard
- [ ] 09-09-PLAN.md — Agent settings query, ordered registry with candidates, cooldown ladder, Copilot ceiling, login checks
- [ ] 09-10-PLAN.md — Fallback loop, waiting state and resumer, 24 h cap, bulk run-time rule and accepted-data guard
- [ ] 09-11-PLAN.md — Waiting, quota timeout, provenance and provider-aware notice in start, cancel, status and review
- [ ] 09-12-PLAN.md — Admin AI agents backend (enable, order, model), eighth admin section, allowlists
- [ ] 09-13-PLAN.md — Bulk dry run with single-use token, start, pause, resume, cancel, counts-only panel
- [ ] 09-14-PLAN.md — Bulk scheduler tick: off-peak, daily cap, spacing, single flight, ai-bulk queue
- [ ] 09-15-PLAN.md — AI calls: attempt rows with "Usage limit reached" tag and filter
- [ ] 09-16-PLAN.md — Settings > AI agents screen with agent cards and per-agent login health
- [ ] 09-17-PLAN.md — Waiting for AI quota, quota timeout, "Read by" provenance, provider line and notice in the UI
- [ ] 09-18-PLAN.md — Bulk re-generation panel: dry run, guarded Start, progress, pause/cancel, history
- [ ] 09-19-PLAN.md — End-to-end and privacy proofs, cache/SW/a11y checks, full gate alone, validation sign-off
- [ ] 09-20-PLAN.md — Live: owner decisions, Copilot login per stack, L1-L8 incl. tool-denial gate, tiny bulk run, release (deferred, with owner)

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

### Phase 10: Profile settings and child accounts (issues #7, #6)

**Goal:** Members can edit their own profile (name, photo shown in all avatars, read-only e-mail) and an admin can create child members directly, without an invitation and with direct sign-in disabled, so a child who cannot sign in (a 2-year-old) still appears in Wishlist and has a list managed by a parent.
**Requirements**: GitHub #7 and #6 (see description)
**Depends on:** Phase 9
**Success Criteria** (what must be TRUE):
1. #7: the account menu has a new item "Profile settings" above "Settings".
2. #7: a member can change their display name; the e-mail address is shown but cannot be changed yet.
3. #7: a member can add or replace a profile photo (reuse the existing private image pipeline: sanitized re-encode, no metadata) and it replaces the initials in every avatar across the app.
4. #6: an admin can create a child member by direct account creation (no invitation); such an account can be marked "no direct sign-in" and then cannot sign in by any route (password, reset, passkey, invitation).
5. #6: the child appears as a Wishlist owner and a parent/admin can add and manage wishes on the child's behalf; finance is out of scope for now and health stays separated from sign-in accounts as already decided.
6. #6: owner asked Claude to propose the best design (discuss-phase decides it: a normal member row with a nullable credential and a login_disabled flag versus a separate non-account person type; permissions default to the Child preset, with managing parent link).
7. Privacy tests prove a sign-in-disabled child account can never authenticate and never receives mail; profile photo files are not publicly reachable.

Open decisions for discuss-phase: child-account data model and who manages it (guardian link); what happens when the child grows up and gets sign-in; whether profile photos use the same cache rules as wish images.
**Commit rule:** every commit that resolves part of #7 and #6 carries `Fixes #N` for the issue it resolves (one trailer per issue). Source text: the issue bodies and comments on GitHub (owner-written). User documentation rule from CLAUDE.md applies (manual + help tips in sk/pl/en).

**Plans:** 8/8 plans complete

Plans:
- [x] 10-01-PLAN.md — Data model: nullable e-mail, sign_in_disabled, photo columns, child CHECK invariant, canSignIn(), NULL-safe staging audit/seeder (wave 1)
- [x] 10-02-PLAN.md — Sign-in lockout on every session path, BlockDisabledSignIn middleware, no mail or reminders for children (wave 2)
- [x] 10-03-PLAN.md — Profile photo backend: private store under wish-images/profile-photos, access rules, ETag route, sweeper, nginx, avatars prop (wave 2)
- [x] 10-04-PLAN.md — Profile settings page, name edit, photo editor, UiAvatar photo, account menu item, help and manual (wave 3)
- [x] 10-05-PLAN.md — Admin creates a child, parents manage it, Members child UI, /profile/people/{user}, Wishlist proof (wave 4)
- [x] 10-07-PLAN.md — Avatars everywhere: remaining call sites, health photoUserId, SW guard; closes #7 (wave 4)
- [x] 10-06-PLAN.md — Convert a child to a member by invitation on the same users row; closes #6 (wave 5)
- [x] 10-08-PLAN.md — Staging synthetic child seed, ops docs, locale parity, full gate, staging browser verification and DB invariant (wave 6)

### Phase 11: Shopping lists section Nákupy (issue #8)

**Goal:** A new Shopping module where members create shared shopping lists from an item catalog (with images and units), assign a store chain, a responsible person and per-list contributor/viewer access, lock the list for shopping, mark items bought or unavailable, finish the shop into the archive and carry unavailable items into a new list, with e-mail sharing and push reminders.
**Requirements**: GitHub #8 (see description)
**Depends on:** Phase 10
**Success Criteria** (what must be TRUE):
1. Lists: create a list; add items through a pop-up with the item catalog (with images); a free-text name + quantity is always possible without adding to the catalog; quantities carry a unit (owner answer: yes, including measure units such as kg, l, pcs).
2. Stores: a list can be assigned to a store chain; chains (name + logo) and the item catalog (with images) are managed in the section's own settings page.
3. Access: each list has one responsible person (changeable any time), members who may contribute (add/edit items) and members who may only view; only authorised members can open a list; module permissions enforced server-side.
4. Locking: only the responsible person can lock a list and (owner answer: yes) unlock it again; while locked, items cannot be added, removed or edited, and only the responsible person (owner answer) marks items Bought or Unavailable.
5. Finishing: when every item is Bought or Unavailable, "Finish shopping" appears; finishing marks the list done and moves it to the archive.
6. Unavailable carry-over: if unavailable items exist, ask "Some items could not be bought. Create a new shopping list from them?"; on yes a new OPEN list keeps store, responsible person and access rights and receives only the unbought items with quantities; the original stays archived.
7. Copy: an existing or archived list can be copied into a new list (owner answer: yes).
8. E-mail: a list can be sent by e-mail to share it outside the app.
9. Reminders: a list can have a reminder date and time that sends a push notification, for now only to the responsible person (owner answer).
10. Claude proposes further features and flags conflicts for owner decision before implementation (issue section 8): e.g. rights for archived lists, behaviour when a responsible person is removed, catalog deletion while used, e-mail content and privacy.

Open decisions for discuss-phase: new module permission area, data model for catalog/units/stores, archive retention, e-mail content, notification preference integration, interaction with Phase 10 children as list members.
**Commit rule:** every commit that resolves part of #8 carries `Fixes #N` for the issue it resolves (one trailer per issue). Source text: the issue bodies and comments on GitHub (owner-written). User documentation rule from CLAUDE.md applies (manual + help tips in sk/pl/en).

**Plans:** 14/14 plans complete

Plans:
- [x] 11-01-PLAN.md — Module `shopping` in the permission layer: keyed presets, CHECK re-add and backfill from todo, five-module permission save, admin manual (wave 1)
- [x] 11-02-PLAN.md — Schema and models: units (nine built-ins), stores, catalog, lists, members, items with CHECKs and the one-carry index; config; factories (wave 1)
- [x] 11-03-PLAN.md — Domain: ShoppingAccess, effective responsible fallback, member sync, list and item services, lock/mark/finish/carry-over/copy state machine (wave 2)
- [x] 11-04-PLAN.md — Catalog, stores, units backend and private images under wish-images/shopping/, sweeper, cache allowlist, nginx (wave 2)
- [x] 11-05-PLAN.md — List HTTP layer: routes, requests (decimal comma, reminder in family time zone), Inertia payloads, endpoint matrix, privacy differential (wave 3)
- [x] 11-06-PLAN.md — Settings > Shopping page (catalog, stores, units), Settings section, manual topic with settings coverage, help tips (wave 3)
- [x] 11-07-PLAN.md — E-mail a list: plain-text queued mail per address, max 5, per-user and per-address limits, audit without addresses (wave 3)
- [x] 11-08-PLAN.md — Reminder push to the effective responsible person through the outbox, scheduler and handler (wave 3)
- [x] 11-09-PLAN.md — Staging synthetic Shopping demo through production services, purge, staging:audit rules, ops docs (wave 3)
- [x] 11-10-PLAN.md — Navigation entry, overview with Open and Archive tabs, create and copy dialogs, module manual coverage (wave 4)
- [x] 11-11-PLAN.md — List detail in plan mode: header, items, catalog picker with pictures and free text, item edit, delete (wave 5)
- [x] 11-12-PLAN.md — List settings panel (people, reminder), e-mail dialog, audit label, notifications wording (wave 6)
- [x] 11-13-PLAN.md — Shopping mode: lock/unlock, Bought/Unavailable marks, finish with carry-over, archived state (wave 7)
- [x] 11-14-PLAN.md — Locale parity, a11y sweep, full gate (`Fixes #8`), staging browser verification as orchestrator checkpoint (wave 8)

### Phase 12: Recipe book section Receptár (issue #9)

**Goal:** A new Recipes module where every member creates and manages own recipes (manually or from a URL read by AI), with photos, structured ingredients with units, step-by-step progress tracking, private/shared visibility and a link to Shopping to add a recipe's ingredients to a list.
**Requirements**: GitHub #9 (see description)
**Depends on:** Phase 11 (Shopping catalog and lists), Phase 8 (AI platform)
**Success Criteria** (what must be TRUE):
1. Create: manual recipe form, or paste a URL and the AI extracts the fields; the source URL is kept on the recipe; all auto-filled values are reviewable and editable before saving.
2. Photos: one or more photos per recipe, one marked as main, shown in the overview.
3. Overview: responsive grid of cards (photo + title), at most 3 per row, card opens the detail.
4. Detail: photos, structured ingredients (name, quantity, unit such as g, kg, ml, l, pcs, tbsp, tsp) with per-item "prepared" ticks, steps each markable as done; ticks and step progress are stored per member so members never overwrite each other's cooking state.
5. Shopping link: "Add to shopping list" creates a new list or adds to an existing open list, carries names, quantities and units, lets the user review/edit before confirming, and matches catalog items automatically when possible (otherwise adds a manual item).
6. Sharing: a recipe is private or shared with other members; only the author edits it.
7. Claude evaluates and recommends the suggested extras (servings with recalculation, prep time, categories, favourites, search/filter, notes, cooking history, meal planning) and asks the owner which to include.
8. Risks analysed and answered in the plan: reliability of AI recipe extraction from URLs (and SSRF/untrusted page content handling in the worker), ingredient and unit recognition, catalog matching, servings recalculation, private versus shared access rules.

Open decisions for discuss-phase: AI-from-URL needs the worker to fetch a web page (a new outbound path; Codex has web search disabled by design) and the provider data-use consent for recipes (non-medical); which suggested extras are in v1.
**Commit rule:** every commit that resolves part of #9 carries `Fixes #N` for the issue it resolves (one trailer per issue). Source text: the issue bodies and comments on GitHub (owner-written). User documentation rule from CLAUDE.md applies (manual + help tips in sk/pl/en).

**Plans:** 17/17 plans complete

Plans:
- [x] 12-01-PLAN.md — Module `recipes` in the permission layer: enum, keyed presets, CHECK re-add and backfill from todo, six-module permission save, admin manual (wave 1)
- [x] 12-02-PLAN.md — Schema and models: recipes, categories, photos, ingredients (Shopping units), steps, per-member state tables; config with every limit; search key; fixtures (wave 1)
- [x] 12-03-PLAN.md — Domain: RecipeAccess, RecipeService with update-by-diff, RecipeStateService (absolute marks), RecipeQuery (search, filters, paging) (wave 2)
- [x] 12-04-PLAN.md — Photos backend: private store under wish-images/recipes/, JSON endpoints, image route, sweeper prefix, cache allowlist, nginx limit (wave 3)
- [x] 12-05-PLAN.md — HTTP layer: overview, detail, form props, CRUD, visibility and per-member state routes, endpoint matrix, privacy differential (wave 3)
- [x] 12-06-PLAN.md — Shopping bridge backend: catalog matching, eligible open lists, all-or-nothing commit through ListService and ItemService (wave 3)
- [x] 12-07-PLAN.md — Navigation entry with dense seven-item bottom bar, overview grid (max 3 per row) with search and filters, manual topic skeleton (wave 4)
- [x] 12-08-PLAN.md — Recipe form for create and edit with photos, categories, ingredients, steps, sharing; quantity and paste helpers; toast actions (wave 5)
- [x] 12-09-PLAN.md — Detail with per-member ticks and done steps, servings recalculation, notes, favourites, gallery, author actions, cooking mode with Wake Lock (wave 6)
- [x] 12-10-PLAN.md — Add to shopping list review dialog (new or open list, matches, merge hints) (wave 7)
- [x] 12-11-PLAN.md — AI foundation: AiTask seam on Codex and Copilot, recipe schema, prompts and validator, ai_calls kind, failure codes, arch rule (wave 4)
- [x] 12-12-PLAN.md — Page reducer: JSON-LD first, readable-text fallback, hidden-text stripping, charset and gzip guards, fetcher user agent (wave 4)
- [x] 12-13-PLAN.md — Import pipeline: recipe_imports, recipe notice column, start service with limits, fetch job in the worker container, ai-worker job with fail-fast (wave 5)
- [x] 12-14-PLAN.md — Import HTTP routes, overview flags and drafts, prefilled review form and used-on-save, reap and prune, leak canary (wave 6)
- [x] 12-15-PLAN.md — Import UI: new-recipe choice, URL dialog with one-time notice, progress and failures, draft rows, review mode, admin calls label, docs (wave 8)
- [x] 12-16-PLAN.md — Staging synthetic Recipes demo through production services, purge, staging:audit rules, ops docs (wave 6)
- [x] 12-17-PLAN.md — Locale parity, pseudo-long, a11y sweep, full gate (`Fixes #9`), staging browser verification with the real-AI URL check list as orchestrator checkpoint (wave 9)

### Phase 13: AI full-text OCR and document search (issue #11)

**Goal:** Evaluate and, if it holds up, change AI document reading so the AI first transcribes all text of the document, stores the complete recognised text with the document for full-text search, then extracts the structured JSON from that text; also evaluate image pre-processing and measure whether it improves accuracy.
**Requirements**: GitHub #11 (see description)
**Depends on:** Phase 9 (AI platform and bulk re-generation)
**Success Criteria** (what must be TRUE):
1. Evaluate first, implement after the owner decides: how OCR works today, whether Codex adjusts contrast/brightness itself, and what pre-processing (contrast/brightness, denoise, deskew/perspective, sharpen, grayscale/binarise) actually helps.
2. Measure OCR with and without image pre-processing on a labelled synthetic set and report accuracy, time and subscription-quota cost; recommend the best trade-off.
3. Two-step reading: transcribe all text, store the complete recognised text with the document, then extract the structured JSON entries from that text (schema stays validated).
4. Full-text search: users search documents by words in their content as well as by name/metadata, limited by the same per-person access rules as the document itself (no leakage across hidden persons, archived or unauthorised documents).
5. Privacy decision recorded by the owner BEFORE implementation: the full recognised medical text is stored in the database (today only the extracted structured entries are stored). Decide access control, retention and deletion with the document, archive behaviour, exclusion from logs/exports, backup implications and the data-use notice; update the AI notice and the manual.
6. Bulk re-generation can backfill text for existing documents within quota limits; running OCR is still an explicit per-document action with consent.

Conflict to resolve with the owner: issue #11 reverses the current design rule that the full text is not kept (see the AI data-flow explanation given on 2026-11-17 and the extraction prompt's rule against free-form transcription); this needs an explicit decision, a prompt/schema version bump and a migration plan.
**Commit rule:** every commit that resolves part of #11 carries `Fixes #N` for the issue it resolves (one trailer per issue). Source text: the issue bodies and comments on GitHub (owner-written). User documentation rule from CLAUDE.md applies (manual + help tips in sk/pl/en).

**Plans:** 10/10 plans complete

Plans:
- [x] 13-01-PLAN.md — Evaluation tooling: metrics (CER, WER, diacritics, numbers, search-term recall, entry F1), decision rule as code, GD pre-processing arms, Codex token counters, transcription schema and prompt, synthetic corpus ground truth and generator, guarded `ai:ocr-eval` harness (wave 1)
- [x] 13-03-PLAN.md — Text storage foundation: `unaccent` extension, `fh_unaccent` wrapper, `medical_document_texts` with generated tsvector and GIN index, store with consent and archive guards, purge on consent withdrawal, privacy net, leak canary, arch pins, staging audit rule (wave 1)
- [x] 13-02-PLAN.md — Live evaluation run on staging as orchestrator checkpoint (real Codex, synthetic only, max 150 calls) and `13-EVALUATION.md` with the decisions block: the gate (wave 2)
- [x] 13-04-PLAN.md — Search backend: POST `/health/search` in one access-scoped statement, prefix and suffix-trim matching, `{text, hit}` snippets, recognised-text view and remove endpoints with audit event, person page flags, privacy differential and matrix (wave 2)
- [x] 13-05-PLAN.md — Search UI on the person page and the Recognised text section in the document panel, copy in en/sk/pl, help tips, manual sections `search` and `recognised-text` (wave 3)
- [x] 13-06-PLAN.md — Transcription step: run `mode` (entries, full, text) and status `text_saved` through every PHP status site, transcription task and strict batch validator, step 1 in the run job (no pre-processing, PDF 1800 px, 4 pages per call, at most 20 pages, text saved after each batch with a complete flag, resume after a quota park, text-only finish), timeouts unchanged, content-free failures (wave 4)
- [x] 13-07-PLAN.md — Extraction from the stored text: `ExtractFromTextTask` with a nonce-marked data block, prompt v2 (entries schema unchanged, no evidence field), reuse of a complete text without re-transcribing, resume order, leak checks (wave 5)
- [x] 13-08-PLAN.md — Bulk backfill scope `no_text`: text-only runs under every existing bulk guard, refused while `ai.ocr.enabled` is off, settings panel option, "Text only" history tag, copy and admin manual (wave 5)
- [x] 13-09-PLAN.md — Notice re-acknowledgment (`text_notice_acked_at`) for runs and bulk Start, three notice variants, run states and `text_saved` tag, consent turn-off purge sentence, manual `ai`/`search`/`recognised-text`/admin `backups` and the `health.ai_run`/`settings.ai_bulk` tips (wave 6)
- [x] 13-10-PLAN.md — Staging seed of recognised texts, ops notes and restore-drill line, locale parity, accessibility sweep, pseudo-long check, full gate (`Fixes #11`), staging verification with a real two-step run as orchestrator checkpoint (wave 7)

### Phase 14: Rebranding (issue #13)

**Goal:** Replace the product identity with the FamilyHUB brand kit (GitHub #13): logo, favicons, PWA icons and colour tokens. Scope confirmed by the owner: logo + icons + colours, typography unchanged.
**Requirements**: BRAND-01, BRAND-02, BRAND-03, BRAND-04
**Depends on:** Phase 13
**Success Criteria** (what must be TRUE):
1. The new logo shows in the app shell, sign-in, invite acceptance and error/offline pages in light and dark theme (the brand kit has light, dark and mono variants).
2. Favicons, apple-touch icon, PWA icons including maskable, and manifest use the kit assets; the service worker precache still builds and the build guard passes.
3. Colour tokens follow the brand guide palette with WCAG AA contrast verified in both themes; the living style page and accessibility sweep pass.
4. Name and wording are consistent in README, manual, help tips and mail templates in SK/PL/EN; user documentation rule from CLAUDE.md applies.
**Open point:** the brand guide PDF could not be rendered on this machine during milestone setup (no poppler). Read it (palette, minimum sizes, clear space) at the start of discuss/plan, and confirm whether the product name changes from "Family Hub" to "FamilyHUB".
**Source:** brand kit attached to GitHub #13 (`FamilyHUB-brand-kit.zip`).
**Commit rule:** commits that resolve part of #13 carry `Fixes #13`.
**Plans:** 6/6 plans complete

Plans:
- [x] 14-01-PLAN.md — Brand guide reading and reconciliation, brand colour tokens with WCAG AA in light and dark (wave 1)
- [x] 14-02-PLAN.md — Kit favicon, apple-touch, PWA and maskable icons with cache-busting names, manifest, head and push icon (wave 1)
- [x] 14-03-PLAN.md — Theme-aware BrandLogo in top bar, auth pages, offline page and style guide (wave 2)
- [x] 14-04-PLAN.md — FamilyHUB name in SK/PL/EN strings, mail templates, app-name defaults and README (wave 1)
- [x] 14-05-PLAN.md — Manual rename and new-icon FAQ in SK/PL/EN, help coverage confirmed (wave 1)
- [x] 14-06-PLAN.md — Old-name guard test, production APP_NAME release note, full gate, staging verification checkpoint (wave 3)

### Phase 15: Bulk medical document upload (issue #14)

**Goal:** Let the owner upload many medical documents at once and review the AI results one by one, resumably (GitHub #14).
**Requirements**: BULK-01, BULK-02, BULK-03, BULK-04, BULK-05, BULK-06, BULK-07, BULK-08, BULK-09
**Depends on:** Phase 13 (AI platform, full-text OCR, bulk guards)
**Success Criteria** (what must be TRUE):
1. User uploads 20+ files as one batch for one person; the worker processes them one at a time with visible per-document status.
2. After each document the user approves or rejects each finding; "Continue" saves approved findings and shows the next document; a re-scan can be requested.
3. Brightness, contrast and black-and-white adjustments apply only to the copy sent to the AI; the stored original is unchanged; settings are remembered.
4. Reloading the page or opening it on another device resumes the batch exactly where it stopped (processing state, adjustments, decisions).
5. Quota exhaustion pauses the batch and it resumes by itself; unfinished batches and files expire after 30 days; access follows the person's record rules, with consent, audit and no content in logs.
**Owner decisions (2026-11-18):** one person per batch; batch stored server-side; "Continue" saves approved findings per document; originals are never changed by adjustments; 30-day auto-expiry.
**Commit rule:** commits that resolve part of #14 carry `Fixes #14`. Reuse the Phase 9 bulk guards and the Phase 13 pre-processing evaluation results.
**Plans:** 9/9 plans complete (+ gap closure 15-10)

Plans:
- [x] 15-01-PLAN.md — Batch and item tables, extraction columns, limits; unfinished batch documents hidden from person page, search, export and bulk re-read
- [x] 15-02-PLAN.md — AI-copy adjustments (brightness, contrast, black and white via GD, original untouched) and fresh re-read in the run job
- [x] 15-03-PLAN.md — One-document-at-a-time batch tick, quota park and self-resume, consent-withdrawal hook, 30-day expiry with file deletion
- [x] 15-04-PLAN.md — Member-side batch domain: create with consent and notice, upload, server-saved choices and adjustments, re-scan, Continue via the accept path
- [x] 15-05-PLAN.md — Batch routes and controller (uniform 403, throttles), upload into a batch through the document endpoint, person-page open-batch prop
- [x] 15-06-PLAN.md — Strings in en/sk/pl and audit labels, start control and banner on the person, batch uploader, live status list and resumable batch page
- [x] 15-07-PLAN.md — Review with persistent approve/reject, Continue, adjustment panel with live preview, re-scan and failed-document actions
- [x] 15-08-PLAN.md — Manual section bulk-upload and who-sees-what in SK/PL/EN, four help tips with registry and placement
- [x] 15-09-PLAN.md — Staging seed and audit, content-in-logs proof, a11y and pseudo-long, full gate (Fixes #14), staging verification with the real AI

### Phase 16: Group wishlists (issue #25)

**Goal:** A group wishlist: one wishlist shared by several members (for example the owner and the spouse), with a per-list mode switch (hidden reservations for gifts from others, or open reservations for shared purchases), created and managed by its creator and invited members (GitHub #25).
**Requirements**: WLG-01, WLG-02, WLG-03, WLG-04, WLG-05, WLG-06
**Depends on:** Phase 15
**Success Criteria** (what must be TRUE):
1. A member with wishlist edit access creates a group wishlist and invites other members; every member of the group can add, edit and remove its wishes and manage the group members.
2. Mode "gifts from others" (hidden reservations): non-members reserve and buy for the group like for a normal list; no group member can see who reserved what, nor whether a wish was reserved by someone else beyond what a normal recipient sees today.
3. Mode "shared purchases" (open reservations): every member of the group sees reservations and the bought state with the name of who did it.
4. Server-side enforcement with a role-by-endpoint matrix test: a non-member cannot read or change a group list, a member cannot see hidden reservations in "gifts from others" mode, existing personal wishlists, children lists and managers behave exactly as before.
5. Manual and contextual help in SK/PL/EN describe group wishlists and both modes; staging verification with synthetic data.
**Owner decisions (2026-11-20):** purpose is both modes via a per-list switch; creator and invited members manage the group (any member with wishlist edit access can create one); in hidden mode nobody in the group sees who reserved what.
**Commit rule:** commits that resolve part of #25 carry `Fixes #25` on the final one. User documentation rule from CLAUDE.md applies.
**Plans:** 9 plans

Plans:
- [ ] 16-01-PLAN.md — Data model (groups, members, wish owner XOR group) and group access rules
- [ ] 16-02-PLAN.md — Group management routes (create, rename, mode switch, members, leave, delete) with reservation purges
- [ ] 16-03-PLAN.md — Group wishes on the owner side (CRUD, received with name, images) and reservation-blind group props
- [ ] 16-04-PLAN.md — Giving side per mode, ?group= page branch, shopping summary
- [ ] 16-05-PLAN.md — Privacy proofs for group lists and no-regression proof for personal, children and managed lists
- [ ] 16-06-PLAN.md — Group chips and panel UI, texts and help tips in SK/PL/EN
- [ ] 16-07-PLAN.md — Group list views per mode and group entries in Your shopping
- [ ] 16-08-PLAN.md — Manual (wishlist, who sees what, FAQ) in SK/PL/EN
- [ ] 16-09-PLAN.md — Staging seed and audit, a11y and full gate (Fixes #25), staging verification checkpoint
