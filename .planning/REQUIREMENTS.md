# Requirements: Family Hub

**Defined:** 2026-11-12
**Core Value:** The whole family can track shared tasks together in one trusted place, with per-member, per-module permissions so private data (health, money) stays private.

## v1 Requirements

Requirements for initial release. Each maps to roadmap phases.

### Foundation

- [ ] **FOUND-01**: App is deployed via Docker Compose on the owner's VPS behind the existing edge proxy, with separate staging (`develop`) and production (`main`) environments
- [ ] **FOUND-02**: UI is available in Slovak, Polish and English, with a language switcher and correct plural/date/number formatting per locale
- [ ] **FOUND-03**: App is installable as a PWA and usable on phone, desktop and iPad; service worker caches only the app shell, never medical or finance data
- [ ] **FOUND-04**: Database is backed up with encryption on a schedule, and a restore has been tested
- [ ] **FOUND-05**: Staging contains only synthetic data (no real medical or finance data)

### Identity and Permissions

- [ ] **AUTH-01**: Registration is invite-only: a family admin invites a member by email
- [ ] **AUTH-02**: User can sign in with email and password
- [ ] **AUTH-03**: User can sign in with Google, linked to their account by verified Google identity
- [ ] **AUTH-04**: User session persists across browser refresh, and an admin can revoke a member's sessions
- [ ] **AUTH-05**: Admin can set per-member, per-module access (no access / view / edit)
- [ ] **AUTH-06**: Admin can apply permission presets (Parent, Child, Grandparent, Guest) and then adjust them
- [ ] **AUTH-07**: All permission checks are enforced server-side by one deny-by-default policy layer, covered by a role-by-endpoint automated test matrix
- [ ] **AUTH-08**: Access to and changes of sensitive data (medical, finance) are recorded in an audit log that admins can view
- [ ] **AUTH-09**: User can enable TOTP or passkey as a second factor
- [ ] **AUTH-10**: User is asked to re-authenticate before opening medical or finance data after a period of inactivity

### To-Do

- [ ] **TODO-01**: User can create a task with title, notes, due date and status
- [ ] **TODO-02**: User can assign a task to a family member
- [ ] **TODO-03**: User can mark tasks personal (only they see it) or shared (visible to the family or chosen members)
- [ ] **TODO-04**: User can create recurring tasks (daily, weekly, monthly, custom), recurring either from the schedule or from completion
- [ ] **TODO-05**: Recurring tasks handle month-end and daylight-saving changes correctly
- [ ] **TODO-06**: User receives reminders for due tasks by email and by push (push on iOS only for installed PWA)

### Wishlist

- [ ] **WISH-01**: Member can add wishes to their own list with title, notes, price and size/variant notes
- [ ] **WISH-02**: A wish can have multiple URLs
- [ ] **WISH-03**: Product image is loaded automatically from a link's meta tags (og:image) in the background, with manual image upload as fallback
- [ ] **WISH-04**: Other family members can reserve a gift ("I'm buying this"); the reservation is visible to others but never to the recipient (no counts, badges, notifications, exports or search hints)
- [ ] **WISH-05**: Family occasions (birthdays, Slovak/Polish name days, Christmas, custom) are tracked per member
- [ ] **WISH-06**: Gift-givers receive reminders ahead of an occasion, never the recipient
- [ ] **WISH-07**: Several members can chip in on one gift (co-buying)

### Finance

- [ ] **FIN-01**: User can record income and expenses with amount, currency, date, account and category
- [ ] **FIN-02**: Money amounts are stored exactly (integer minor units with currency code)
- [ ] **FIN-03**: Admin can configure which members see which accounts
- [ ] **FIN-04**: User can define recurring expenses (rent, savings, services, kindergarten, energy, etc.) with amount, schedule and category
- [ ] **FIN-05**: User sees an upcoming-bills view and expected-vs-actual matching of recurring expenses against recorded or imported transactions
- [ ] **FIN-06**: User can set monthly budgets per category and see progress
- [ ] **FIN-07**: User sees overviews and charts (spending by category, balance over months)
- [ ] **FIN-08**: User can import a bank statement from CSV (Slovak/Polish formats incl. Windows-1250, semicolons, decimal comma), with saved per-bank mapping profiles, review step, duplicate detection and undo

### Medical Records

- [ ] **MED-01**: Medical records belong to a person profile (the subject), who need not be an app user
- [ ] **MED-02**: Access to medical data is granted per subject, not only per module
- [ ] **MED-03**: User can record doctor visits with date, doctor, specialty (from a taxonomy), diagnosis and notes
- [ ] **MED-04**: User can record medications and allergies per person
- [ ] **MED-05**: User can record vaccinations and gets a reminder when the next one is due
- [ ] **MED-06**: User can upload documents and photos of reports (PDF, images incl. HEIC) attached to a person and optionally a visit; files are served only to authorized users
- [ ] **MED-07**: User can export records by specialty (e.g. gastroenterology, internal medicine) for a doctor consultation
- [ ] **MED-08**: User can export all records of a person at once
- [ ] **MED-09**: User can export records in an AI-friendly format; unverified AI-extracted data is labeled as such in all exports
- [ ] **MED-10**: Each person has an emergency card showing critical info (allergies, medications, blood type, contacts) on one screen
- [ ] **MED-11**: Each person has an AI-consent flag (default off) that must be on before any of their documents are sent to an AI agent

### AI Extraction

- [ ] **AI-01**: User can run AI extraction on a document on demand; the AI returns data in an exact JSON structure through a prepared prompt
- [ ] **AI-02**: Extraction results are validated against a versioned schema and saved as a proposal, not applied, until a user reviews and accepts or edits them side by side with the document
- [ ] **AI-03**: Every call to an external AI is recorded (who, document, agent, model, time)
- [ ] **AI-04**: AI jobs run in a background worker in a locked-down, low-privilege container, using Codex CLI and GitHub Copilot CLI through subscription logins, never per-token APIs
- [ ] **AI-05**: Admin can choose the AI agent order and model per agent in settings and sees each agent's login health
- [ ] **AI-06**: When an agent's usage limit is exhausted, the job falls back to the next agent or waits until the limit resets, and the user sees its status
- [ ] **AI-07**: Admin can start bulk re-generation of extractions for past documents, with a dry run and daily cap; it never overwrites data already accepted by a human

## v2 Requirements

Deferred to future release. Tracked but not in current roadmap.

### AI providers

- **AIX-01**: Claude Code CLI adapter, if Anthropic terms permit automated use of subscription credentials
- **AIX-02**: Google Antigravity adapter, if it gains a documented headless mode and permissive terms

### Finance

- **FINX-01**: Multi-currency reporting with conversion
- **FINX-02**: Live bank connection

### Chores

- **CHORE-01**: Rotating chore assignment among members

## Out of Scope

Explicitly excluded. Documented to prevent scope creep.

| Feature | Reason |
|---------|--------|
| Per-token paid AI APIs | Owner requires subscription-based use only |
| Claude Code and Antigravity in v1 | Provider terms forbid this automated use of subscription credentials (deferred, see v2) |
| Storing subscription OAuth tokens in the app | Terms risk; CLI login is done once on the server, app shows only login health |
| Silent auto-trust of AI output | Medical data must be human-reviewed |
| Public share links for wishlists or medical data | Privacy risk |
| Chat or social feed | Not core to the family-organization value |
| Diagnosis or drug-interaction engine | Medical liability, out of purpose |
| Native mobile apps | PWA covers phone, iPad and desktop |
| Envelope-style (YNAB) budgeting | Complexity; simple category budgets suffice |
| Health device integrations | Not requested |

## Traceability

Which phases cover which requirements. Updated during roadmap creation.

| Requirement | Phase | Status |
|-------------|-------|--------|
| FOUND-01 | Phase 1 | Pending |
| FOUND-02 | Phase 1 | Pending |
| FOUND-03 | Phase 1 | Pending |
| FOUND-04 | Phase 1 | Pending |
| FOUND-05 | Phase 1 | Pending |
| AUTH-01 | Phase 2 | Pending |
| AUTH-02 | Phase 2 | Pending |
| AUTH-03 | Phase 2 | Pending |
| AUTH-04 | Phase 2 | Pending |
| AUTH-05 | Phase 2 | Pending |
| AUTH-06 | Phase 2 | Pending |
| AUTH-07 | Phase 2 | Pending |
| AUTH-08 | Phase 2 | Pending |
| TODO-01 | Phase 3 | Pending |
| TODO-02 | Phase 3 | Pending |
| TODO-03 | Phase 3 | Pending |
| TODO-04 | Phase 3 | Pending |
| TODO-05 | Phase 3 | Pending |
| TODO-06 | Phase 3 | Pending |
| WISH-01 | Phase 4 | Pending |
| WISH-02 | Phase 4 | Pending |
| WISH-03 | Phase 4 | Pending |
| WISH-04 | Phase 4 | Pending |
| WISH-05 | Phase 4 | Pending |
| WISH-06 | Phase 4 | Pending |
| WISH-07 | Phase 4 | Pending |
| FIN-01 | Phase 5 | Pending |
| FIN-02 | Phase 5 | Pending |
| FIN-03 | Phase 5 | Pending |
| FIN-04 | Phase 5 | Pending |
| FIN-05 | Phase 5 | Pending |
| FIN-06 | Phase 5 | Pending |
| FIN-07 | Phase 5 | Pending |
| FIN-08 | Phase 5 | Pending |
| AUTH-09 | Phase 6 | Pending |
| AUTH-10 | Phase 6 | Pending |
| MED-01 | Phase 7 | Pending |
| MED-02 | Phase 7 | Pending |
| MED-03 | Phase 7 | Pending |
| MED-04 | Phase 7 | Pending |
| MED-05 | Phase 7 | Pending |
| MED-06 | Phase 7 | Pending |
| MED-07 | Phase 7 | Pending |
| MED-08 | Phase 7 | Pending |
| MED-09 | Phase 7 | Pending |
| MED-10 | Phase 7 | Pending |
| MED-11 | Phase 7 | Pending |
| AI-01 | Phase 8 | Pending |
| AI-02 | Phase 8 | Pending |
| AI-03 | Phase 8 | Pending |
| AI-04 | Phase 8 | Pending |
| AI-05 | Phase 9 | Pending |
| AI-06 | Phase 9 | Pending |
| AI-07 | Phase 9 | Pending |

**Coverage:**
- v1 requirements: 54 total
- Mapped to phases: 54
- Unmapped: 0 ✓

---
*Requirements defined: 2026-11-12*
*Last updated: 2026-11-12 after roadmap creation*
