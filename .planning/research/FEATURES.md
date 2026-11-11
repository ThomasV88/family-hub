# Feature Research

**Domain:** Family organization app (shared to-do, gift wishlist, family budgeting, family medical records, extended-family permissions)
**Researched:** 2026-11-11
**Confidence:** MEDIUM. The feature landscape comes from knowledge of established products (Cozi, OurHome, Todoist/TickTick family sharing, Giftster, Elfster, Amazon Wishlist, YNAB, Monarch, Firefly III, Actual Budget, Spendee, Wallet, Apple Health/Medisafe/MyChart, Hospital/Mediktor-style record apps). No live competitor verification was done in this session, so treat details as unverified. Items marked (LOW) rest on judgement only.

## Feature Landscape

### Cross-Cutting: Accounts, Permissions, i18n (foundation)

| Feature | Tier | Complexity | Notes |
|---------|------|------------|-------|
| Email+password login, Google sign-in | Table stakes | MEDIUM | Password reset, email verification. |
| Admin invites by link/email | Table stakes | MEDIUM | Invite token carries pre-assigned role/permission preset. |
| Family (household) entity with members and roles | Table stakes | MEDIUM | Roles: admin, adult, child, extended. |
| Per-module, per-member access level (none/view/edit) | Differentiator (core value) | HIGH | Most family apps only have admin/member. Enforce server-side in a single policy layer, never only in UI. |
| Permission presets (Parent, Teen, Grandparent, Guest) | Table stakes for usability | LOW | Presets prefill the matrix; admin can override. Mixed-age/technical users need defaults. |
| Default-restricted health and finance | Table stakes (project constraint) | LOW | New members start with none on those modules. |
| Per-record visibility (private / shared / selected members) | Differentiator | MEDIUM | Needed for personal tasks and for medical records per person (see below). |
| Child accounts without email (managed by parent) | Differentiator | MEDIUM | Defer to v1.x. |
| SK/PL/EN UI with language switcher, per-user language | Table stakes (project constraint) | MEDIUM | Locale-aware dates/currency. Name-day calendars differ by country (SK vs PL). |
| PWA install, responsive, offline-tolerant shell | Table stakes | MEDIUM | iOS PWA push needs Add-to-Home-Screen and iOS 16.4+ (MEDIUM). |
| Audit log of who changed what (sensitive modules) | Differentiator | MEDIUM | Valuable for health/finance. v1.x. |

### Module 1: Family To-Do

#### Table Stakes

| Feature | Why Expected | Complexity | Notes |
|---------|--------------|------------|-------|
| Create/edit/complete/delete tasks | Basic | LOW | |
| Assign to one member | Core of "family" to-do | LOW | Multi-assignee deferred; one owner avoids "everyone thinks someone else does it". |
| Due date (optional time) | Expected | LOW | Store date vs datetime distinctly; family timezone. |
| Personal vs shared tasks | Project requirement | LOW | Personal = visible only to owner. |
| Recurring tasks (daily/weekly/monthly/yearly, every N, weekdays) | Project requirement; Cozi/Todoist have it | MEDIUM | Decide model: generate next instance on completion vs RRULE. Recommend "next instance on completion" plus fixed-schedule option. Watch month-end and DST. |
| Lists/categories (e.g. Groceries, Home) | Cozi's main draw is shared shopping list | LOW | Named lists with sharing level. |
| Filters: mine, overdue, today, upcoming | Expected | LOW | |
| Reminders/notifications (due soon, assigned to you) | Expected | MEDIUM | Web push + optional email. Requires notification infra shared with wishlist occasions. |
| Notes/description, simple subtasks/checklist | Expected | LOW | |

#### Differentiators

| Feature | Value | Complexity | Notes |
|---------|-------|------------|-------|
| Rotating chore assignment (round-robin on recurrence) | Kids' chores without nagging | MEDIUM | v1.x |
| Task comments | Light coordination | LOW | v1.x |
| Calendar view of tasks | Visual planning | MEDIUM | v1.x |
| Link task from other modules (e.g. "book vaccination" from medical, "pay bill" from recurring expense) | Cross-module value, unique to an integrated hub | MEDIUM | v2; keep a generic entity-link table in mind. |
| Points/rewards for kids | Engagement | MEDIUM | Defer; (LOW) |

### Module 2: Gift Wishlist

#### Table Stakes

| Feature | Why Expected | Complexity | Notes |
|---------|--------------|------------|-------|
| Per-member wishlist, add/edit/delete wish | Core | LOW | |
| Wish fields: title, price (or range), notes, priority, quantity | Giftster/Amazon standard | LOW | |
| Multiple URLs per wish | Project requirement; lets givers compare shops | LOW | Child table `wish_links`; each with optional label/price. |
| Reservation ("I'm buying this") visible to others, hidden from recipient | THE defining feature of Giftster/Elfster | MEDIUM | Hiding must be enforced in the API serializer/query layer, including notifications, activity feeds, counters, exports, and search. Also hide from the owner via "reserved count" leaks. Admin viewing own list must be treated as recipient too. |
| Un-reserve and "purchased" state | Prevents duplicates and dead reservations | LOW | Reserved-by shown to other givers, with ability to release. |
| Browse others' lists | Core | LOW | Subject to module permissions. |
| Image per wish: auto-fetch og:image from URL | Project requirement | MEDIUM | Server-side fetch with SSRF protection (block private IPs, redirect limits, size/time caps), parse og:image/twitter:image/title/price meta. Many shops block bots; always allow manual upload and fall back gracefully. Store a local copy. |
| Occasions: birthdays, name days, Christmas, custom | Project requirement | MEDIUM | Per-member birthday and name day (name-day by SK/PL calendar, or manual date). Occasion date drives reminders. |
| Reminders ahead of occasion (e.g. 30/14/3 days) | Project requirement | MEDIUM | Reuses notification infra; only notify givers, never leak to recipient. |

#### Differentiators

| Feature | Value | Complexity | Notes |
|---------|-------|------------|-------|
| Name-day calendar prefilled (SK and PL) | Regionally specific; global apps lack it | LOW-MEDIUM | Static dataset by name; user picks name day. Verify data source license. |
| Co-buying / split a gift ("I'll chip in") | Common for expensive gifts | MEDIUM | v1.x. Hidden from recipient also. |
| Price auto-extract from page (JSON-LD/og:price) | Saves typing | MEDIUM | Best effort only. |
| Gift ideas for people without accounts (kids, partner's relatives) | Real families need it | LOW | "Managed" wishlist owner without login. |
| Per-occasion lists / "received" history to avoid repeats | Nice | MEDIUM | v2. |
| Secret-santa/draw for Christmas | Elfster core | MEDIUM | Defer; (LOW) |

### Module 3: Family Accounting

#### Table Stakes

| Feature | Why Expected | Complexity | Notes |
|---------|--------------|------------|-------|
| Manual income/expense entry (amount, date, category, note, account) | Core | LOW | Store amounts as integer minor units + currency. |
| Categories (editable, hierarchical optional) | Core | LOW | Sensible SK/PL/EN default set. |
| Multiple accounts (cash, bank, savings) | Expected | LOW-MEDIUM | Needed for transfers and CSV import mapping. |
| Transfers between accounts (not counted as expense) | Avoids double counting in reports | MEDIUM | Commonly mishandled. |
| Monthly overview: income vs expense, per-category totals | Core | MEDIUM | |
| Budgets per category per month with progress/over-budget indicator | Project requirement | MEDIUM | Start with simple monthly category limits (not envelope/zero-based). |
| Charts (category pie/bar, monthly trend) | Project requirement | MEDIUM | |
| CSV bank-statement import with column mapping | Project requirement | HIGH | No open-banking. Per-bank format profiles (Slovak: Tatra banka, SLSP, VÚB, ČSOB, Revolut, etc.; Polish: PKO, mBank, ING) differ in delimiter, encoding (Windows-1250), decimal comma, date formats. Saved mapping profiles, preview, duplicate detection (hash of date+amount+description), user review before commit. |
| Auto-categorization rules from imports (description contains X → category) | Expected once import exists | MEDIUM | Simple rule list; learns from user fixes (differentiator-lite). |
| Search/filter/edit/delete transactions | Core | LOW | |
| Privacy: module-level permission plus ideally per-account visibility | Core value | MEDIUM | Per-account visibility (e.g. parents' joint vs kid's pocket money) is better than module-only. |

#### Differentiators (the stated focus: recurring expenses)

| Feature | Value | Complexity | Notes |
|---------|-------|------------|-------|
| Recurring expense definitions (rent, kindergarten, energy, services, savings) with amount, frequency, due day, category, account, owner | Project's strongest accounting focus | MEDIUM-HIGH | Separate "template" entity generating expected instances. |
| Expected vs actual matching: scheduled occurrence marked paid when imported/entered transaction matches | Turns recurring tracking into real value, rare in free tools | HIGH | Match on amount tolerance, date window, description pattern. Manual confirm UI. |
| Upcoming bills calendar/list (next 30 days), overdue/unpaid flag | Cash-flow awareness | MEDIUM | |
| Fixed-vs-variable costs summary, "monthly committed total" | Answers "what do we pay every month" | MEDIUM | |
| Variable amounts (energy estimates, annual adjustments) and price-change history | Real-life bills change | MEDIUM | Effective-dated versions of recurrence amount. |
| Non-monthly cycles (quarterly, yearly insurance, school year) | Common in SK/PL (insurance, vehicle tax) | MEDIUM | |
| Detect likely recurring from imported history (suggest subscriptions) | Discovery | HIGH | v2. |
| Savings goals | Family motivation | MEDIUM | v1.x/v2. Can be modelled as recurring "savings" expenses first. |
| Reminders for upcoming due bills | Useful | LOW (given notification infra) | |
| Multi-currency (EUR/PLN) | Cross-border family (SK+PL) | HIGH | Per-account currency with no conversion at first; reports per currency. Full conversion deferred. |

### Module 4: Family Medical Records

#### Table Stakes

| Feature | Why Expected | Complexity | Notes |
|---------|--------------|------------|-------|
| Person profiles (per family member, including children, pets excluded) | Records are per person | LOW-MEDIUM | Subjects need not be app users (kids, elderly relatives). Subject != user. |
| Doctor visits: date, doctor/clinic, specialty, reason, findings, recommendations, next appointment | Core | MEDIUM | Specialty is an enumerated/curated list (needed for export by category). |
| Medications: name, dose, schedule, start/end, prescriber, active flag | Core | MEDIUM | Reminders optional; Medisafe-style adherence tracking is out of scope. |
| Allergies/intolerances with severity and reaction | Core, safety-critical | LOW | Prominently visible on person profile. |
| Vaccinations: vaccine, date, dose number, lot, next due | Core (SK/PL mandatory schedules) | MEDIUM | |
| Document/photo upload (PDF, JPG/PNG, HEIC from iPhone) linked to visit/person | Core | MEDIUM | Encrypted storage, size limits, HEIC conversion, virus-scan optional, thumbnails. |
| Per-person access control (who can see whose records) | Core value | HIGH | Module permission is too coarse: grandparent should see own records, parent sees kids'. Recommend permission = module level AND per-subject grant. |
| Timeline view per person | Expected | MEDIUM | |
| Search across records | Expected | MEDIUM | |

#### Differentiators

| Feature | Value | Complexity | Notes |
|---------|-------|------------|-------|
| AI OCR/extraction from uploaded document into structured JSON (visit, meds, diagnoses, lab values) | Project's headline differentiator | HIGH | Strict JSON schema validated server-side; store raw output plus parsed; mark as "AI-suggested, unreviewed". |
| Review-and-confirm UI before AI data becomes authoritative | Safety/trust; required given sensitivity | MEDIUM | Side-by-side document and fields, field-level edit, status (pending/confirmed). |
| Explicit per-document "send to AI" consent, shown provider | Privacy constraint in PROJECT.md | LOW-MEDIUM | |
| Bulk re-generation of past documents | Project requirement | MEDIUM-HIGH | Job queue, resumable, throttled, versioned results (do not overwrite confirmed edits). |
| Quota/limit handling: detect exhaustion, retry later, fallback to another agent | Project requirement | HIGH | Queue with per-agent state, backoff, job status UI. |
| Multi-agent selection (Codex, Claude, etc.) with per-job model choice | Project requirement | HIGH | Provider terms for headless subscription use are an unresolved risk (see Anti-Features and Research Flags). |
| Export by specialty/category, or everything, as PDF/printable | Project requirement; practical for doctor visits | MEDIUM | Date range, person, specialties selected; includes allergies/meds summary header. |
| AI-friendly export (structured Markdown/JSON bundle with schema) | Project requirement; feeds user's own LLM sessions | MEDIUM | Include a README/schema and document list; option to include or redact identifying data. |
| Lab results with numeric values and trends | Valuable but big | HIGH | v2; AI extraction may feed it. |
| Emergency summary card (allergies, meds, blood type, contacts) | High value in emergencies | LOW-MEDIUM | v1.x. Shareable via restricted link is risky; keep in-app/PDF. |
| Medication/vaccination due reminders | Useful | MEDIUM | v1.x |

### Anti-Features

| Feature | Why Requested | Why Problematic | Alternative |
|---------|---------------|-----------------|-------------|
| Per-token paid AI API integration | Simpler, more reliable | Violates owner's explicit cost constraint | Subscription CLI agents only |
| Auto-trusting AI output (silent write to confirmed records) | Convenience | Medical errors, hallucinated doses | Always "unreviewed" until a human confirms |
| Live bank connection (PSD2/open banking aggregators) | Auto sync | Regulatory/contract burden, cost, trust; overkill for family scale | CSV import with saved profiles |
| Full zero-based/envelope budgeting (YNAB clone) | Popular method | Steep learning curve for mixed technical comfort; heavy to build | Simple category budgets plus recurring tracking |
| Double-entry accounting, invoicing, tax | "Real" accounting | Wrong audience | Simple ledger |
| Real-time collaborative editing / live sync everywhere | Feels modern | Complexity without family value | Refresh/poll; optimistic updates |
| Native mobile apps | Better push/perf | Out of scope; PWA covers | PWA |
| Medical diagnosis/advice/drug-interaction engine | AI-hype | Liability, accuracy | Records and export only; show disclaimer |
| Sharing medical records with external parties via public links | Convenience at doctor | Data leak risk | Download PDF/export and hand over |
| Marketplace/price tracking/affiliate links in wishlist | Monetization, price alerts | Scraping fragility, off-mission | Plain URLs; best-effort price |
| Chat/messaging, family social feed | Cozi-like | Competes with WhatsApp, moderation scope | Task comments only |
| Per-user permission at field level | Granularity | Unmanageable matrix | Module level + per-subject/per-account grants |
| Surprise-leaking features (activity feed showing reservations to recipient) | Transparency | Breaks wishlist core promise | Reservation data excluded from recipient-scoped queries |
| Health device integrations (Apple Health, Fitbit) | Completeness | Large scope, no PWA access | None |
| Public wishlist sharing links | Easy sharing | Privacy and reservation leak | Invite-only members |

## Feature Dependencies

```
Accounts + Family + Invites
    └──requires──> Permission model (module + per-subject/account grants)
                       ├──requires──> To-Do module
                       ├──requires──> Wishlist (reservation hiding)
                       ├──requires──> Accounting (account visibility)
                       └──requires──> Medical (subject-level access)

i18n framework ──requires before──> any UI (retrofit is costly)

Notification infrastructure (push/email, scheduler)
    ├──enhances──> To-Do reminders
    ├──enhances──> Wishlist occasion reminders
    ├──enhances──> Upcoming-bill reminders
    └──enhances──> Medication/vaccine reminders

Recurrence engine (shared concept)
    ├──used by──> Recurring tasks
    └──used by──> Recurring expenses (different semantics: expected vs actual)

Accounts + categories ──requires──> Manual transactions
    └──requires──> CSV import ──requires──> Dedup + mapping profiles
          └──enhances──> Recurring expected-vs-actual matching

File storage (uploads) ──requires──> Medical documents
    └──requires──> AI OCR pipeline
          ├──requires──> Job queue + agent runner + quota/fallback
          ├──requires──> Review/confirm UI
          └──enhances──> Bulk re-generation
Structured medical data (visits/meds/allergies/vaccines + specialty taxonomy)
    └──requires──> Exports (by specialty, AI-friendly)

Wishlist URL fetch ──requires──> SSRF-safe fetcher (reusable)
Occasions ──requires──> Member birthdays/name days
```

### Dependency Notes

- **Permissions first:** retrofitting server-side visibility into finished modules is the classic rewrite. Build one policy layer and use it from To-Do onward.
- **Medical data entry works without AI:** manual forms and exports must be complete before OCR; AI is an enhancer that writes into the same structured tables. This decouples the highest-risk dependency (AI agent subscriptions) from the module's value.
- **Specialty taxonomy must exist at visit-creation time**, otherwise export by category cannot be done retroactively without migration.
- **Recurring expenses are a distinct entity from recurring tasks:** share a date-math helper only.
- **Notification infrastructure** is shared by four modules; build it by the time the second reminder consumer ships (wishlist occasions), not per module.

## MVP Definition

### Launch With (v1: Phase 1-2 scope, To-Do first)

- [ ] Accounts, family, invites, roles, module-level none/view/edit with presets: foundation for the core value
- [ ] SK/PL/EN i18n framework and PWA shell
- [ ] To-do: assignment, due dates, recurrence, personal/shared, lists, filters
- [ ] Basic reminders (push/email) for tasks

### Add After Validation (v1.x, one module per phase)

- [ ] Wishlist: wishes, multiple URLs, og:image, reservation hiding, occasions and reminders
- [ ] Accounting: manual ledger, categories, budgets, charts, then CSV import, then recurring expense tracking with expected-vs-actual
- [ ] Medical manual: persons, visits, meds, allergies, vaccinations, documents, per-subject access, exports by specialty and AI-friendly export
- [ ] AI OCR: agent runner, schema, review UI, queue, quota/fallback, bulk re-generation
- [ ] Audit log, emergency card, co-buying, rotating chores

### Future Consideration (v2+)

- [ ] Lab-result trends, recurring detection from history, multi-currency conversion, secret santa, cross-module links, child accounts

## Feature Prioritization Matrix

| Feature | User Value | Implementation Cost | Priority |
|---------|------------|---------------------|----------|
| Auth, invites, family | HIGH | MEDIUM | P1 |
| Permission matrix + presets | HIGH | HIGH | P1 |
| i18n SK/PL/EN | HIGH | MEDIUM | P1 |
| To-do core + recurrence | HIGH | MEDIUM | P1 |
| Notifications infra | HIGH | MEDIUM | P1 |
| Wishlist + reservation hiding | HIGH | MEDIUM | P1 (module 2) |
| og:image fetch (SSRF safe) | MEDIUM | MEDIUM | P1 (module 2) |
| Occasions + reminders | HIGH | MEDIUM | P1 (module 2) |
| Manual ledger + categories + budgets + charts | HIGH | MEDIUM | P1 (module 3) |
| CSV import with profiles + dedup | HIGH | HIGH | P1 (module 3) |
| Recurring expenses tracking | HIGH | HIGH | P1 (module 3) |
| Expected-vs-actual matching | HIGH | HIGH | P2 |
| Medical manual records + docs | HIGH | MEDIUM-HIGH | P1 (module 4) |
| Per-subject medical access | HIGH | HIGH | P1 (module 4) |
| Exports (specialty, AI-friendly) | HIGH | MEDIUM | P1 (module 4) |
| AI OCR + review UI | HIGH | HIGH | P2 (module 5) |
| Bulk regen + quota fallback | MEDIUM | HIGH | P2 |
| Audit log | MEDIUM | MEDIUM | P2 |
| Co-buying, rotating chores, calendar view | MEDIUM | MEDIUM | P3 |
| Lab trends, secret santa, points | LOW-MEDIUM | HIGH | P3 |

## Competitor Feature Analysis

| Feature | Cozi / OurHome | Giftster / Elfster | YNAB / Firefly III / Actual | Medical record apps | Our Approach |
|---------|----------------|--------------------|-----------------------------|---------------------|--------------|
| Shared tasks, assignment, recurrence | Yes (core) | n/a | n/a | n/a | Same plus per-member permissions |
| Reservation hidden from owner | n/a | Yes (core) | n/a | n/a | Enforced server-side, includes notifications |
| Multi-URL wish, image auto-fetch | n/a | Browser extension / URL import; usually one link | n/a | n/a | Multiple URLs per wish, server-side og:image |
| Occasions, name days | Calendar only | Birthdays/events; no SK/PL name days | n/a | n/a | Name-day calendars SK/PL |
| CSV import | n/a | n/a | Yes (Firefly/Actual strong; YNAB basic) | n/a | Saved profiles for SK/PL banks |
| Recurring bills tracking | n/a | n/a | Firefly recurring transactions; Actual schedules with matching | n/a | Schedules with expected-vs-actual matching as focus |
| Family granular permissions | Weak | Group-level | Firefly single-user-ish | Single patient or proxy | Per-module and per-subject grants |
| AI OCR to structured records | n/a | n/a | Some receipt scanning in paid tools | Few, mostly provider-integrated | Reviewable AI extraction via subscription agents |
| Specialty export / AI-friendly export | n/a | n/a | n/a | PDF summaries common; AI export rare | Both |

(Competitor cells reflect general product knowledge, MEDIUM/LOW confidence; not freshly verified.)

## Sources

- Product knowledge of Cozi, OurHome, Todoist, TickTick, Giftster, Elfster, Amazon Wishlist, YNAB, Monarch, Firefly III, Actual Budget, Spendee, Medisafe, MyChart (training data, not re-verified; MEDIUM/LOW)
- `.planning/PROJECT.md` requirements and constraints (HIGH for scope)

## Research Flags for Roadmap

- **Headless subscription AI agents (Claude/Codex/Copilot/Antigravity):** terms-of-service and technical feasibility unverified; needs dedicated research before the AI phase. Keep manual medical entry independent.
- **CSV formats for SK/PL banks:** needs sample-file research per bank before import phase.
- **Name-day datasets (SK/PL) licensing/source:** verify before wishlist phase.
- **iOS PWA web push constraints:** verify before notifications work.
- **Medical/financial data protection (GDPR health data, encryption at rest, backups):** treat as cross-cutting requirement.

---
*Feature research for: family organization app*
*Researched: 2026-11-11*
