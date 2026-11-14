# Phase 7: Medical Records - Context

**Gathered:** 2026-11-14
**Status:** Ready for planning

<domain>
## Phase Boundary

The family keeps each person's medical history, documents and exports in one private place, fully usable by hand without any AI. Person profiles (subjects need not be app users), per-person access, visits/medications/allergies/vaccinations with reminders, documents and photos (incl. HEIC), exports, emergency card, and a stored per-person AI-consent flag (enforced later by Phase 8). Runs behind the Phase 6 second factor and idle re-auth gate and the Phase 2 audit.
</domain>

<decisions>
## Implementation Decisions

### People and access
- A medical subject (name, birth date, sex, blood type, notes) may or may not be an app user; app users can be linked to their own profile. Children and grandparents get profiles without accounts.
- Per-person access on top of the module grant (health: none by default): the admin chooses per person who can view or edit. Members see only permitted people; hidden people leave no trace (names, counts, files, search). Admins do not automatically see medical records.
- A member linked to a profile always sees and edits their own record unless the admin disables it for a child profile.
- Same structural privacy approach as Wishlist/Finance: one scoped query layer, arch test, differential/canary/query-log leak tests, opaque UUID ids, identical 403/404 for hidden vs unknown, audit of reads/writes (content-free), the Phase 6 idle gate (route group `access:module,health`, health-ish route names) and second factor on every route.

### Records and files
- Doctor visits (date, doctor, specialty from a static versioned SK/PL/EN taxonomy plus "Other" with free text, diagnosis, notes), medications (name, dose, schedule, start/end), allergies (substance, reaction, severity), vaccinations (vaccine, date, next due).
- Vaccination due-date reminders via the Phase 3 outbox (email/push) to members who can edit that person's record; push is title-only ("Vaccination due: <person>"); never to people without access.
- Documents: PDF and images incl. HEIC/HEIF (converted to JPEG for viewing when the server can decode HEIC, original kept), attached to a person and optionally a visit; stored outside web root on a separate medical volume (Phase 4 uploads pattern), MIME sniffed from bytes, size caps, EXIF stripped from served derivatives, served only through an authenticated route with private no-cache and nosniff, included in encrypted restic backups.

### Exports, emergency card, AI consent
- Exports by specialty and everything for one person, generated on demand and never stored, authorized and audited; printable HTML (print to PDF) and a downloadable structured file; no server-side PDF.
- AI-friendly export (JSON/Markdown) where AI-extracted data not yet verified by a user carries an "unverified" label (the flag is populated in Phase 8; format and labeling rules exist now).
- One-screen emergency card per person (allergies, medications, blood type, emergency contacts), printable, no public link.
- Per-person AI-consent flag, default off, editable by members who can edit that person, audited; stored and enforceable now, used by Phase 8.

### Claude's Discretion
Table/class names, taxonomy content, HEIC decoding approach (verify what the app image supports; otherwise store original and show a download/placeholder with a clear message), UI layout following sketch 007 and the Phase 01.1 design system, test organisation.
</decisions>

<code_context>
## Existing Code Insights

### Reusable Assets
- Phase 2 policy layer and audit (health reads/writes already audited by RequireAccess), Phase 6 SensitiveGate/second factor/`SensitiveRoutes` safety net (new health routes must carry `access:module,health` and health-ish names), Phase 4/5 structural privacy patterns (Viewer/Visibility-style scope, arch/differential/canary/query-log harness, mutation scans, opaque UUIDs), Phase 4 image pipeline and uploads volume pattern, Phase 3 outbox, Phase 5 money-free parsing helpers not needed.

### Established Patterns
- Inertia pages, per-feature route files and EndpointMatrix files, lang keys in one early plan (pipe plurals), Vitest + axe sweeps SK/PL/EN light/dark, no-store dynamic responses, service worker never caches private data.

### Integration Points
- modules.js (health nav), Home card (restricted, no private data when stale), settings (person access admin UI in Members area), audit page, backup scripts (new volume), nginx upload limits, scheduler.
</code_context>

<specifics>
## Specific Ideas

Sketch 007: medical records as specialty folders with a chronological timeline, per-person AI consent and review flow.
</specifics>

<deferred>
## Deferred Ideas

Public emergency QR link, server-side PDF generation, AI extraction (Phase 8), lab value trends/graphs, appointment scheduling.
</deferred>
