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
**Plans**: TBD
**UI hint**: yes

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
**Plans**: TBD
**UI hint**: yes

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
**Plans**: TBD
**UI hint**: yes

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
**Plans**: TBD
**UI hint**: yes

### Phase 6: Sensitive-Data Account Hardening
**Goal**: Medical and finance data are protected by a second factor and idle re-authentication before the most sensitive module goes live.
**Mode:** mvp
**Depends on**: Phase 2
**Requirements**: AUTH-09, AUTH-10
**Success Criteria** (what must be TRUE):
  1. User can enable a TOTP app or passkey as a second factor and must use it at sign-in
  2. After a period of inactivity, user is asked to re-authenticate before opening medical or finance data, and other modules are not interrupted
**Plans**: TBD
**UI hint**: yes

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
| 2. Identity and Authorization Core | 0/TBD | Not started | - |
| 3. To-Do | 0/TBD | Not started | - |
| 4. Wishlist | 0/TBD | Not started | - |
| 5. Finance | 0/TBD | Not started | - |
| 6. Sensitive-Data Account Hardening | 0/TBD | Not started | - |
| 7. Medical Records | 0/TBD | Not started | - |
| 8. AI Extraction Platform | 0/TBD | Not started | - |
| 9. Multi-Agent, Quota and Bulk Re-generation | 0/TBD | Not started | - |
