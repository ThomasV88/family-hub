# Phase 7: Medical Records - Research

**Researched:** 2026-11-14
**Domain:** Per-person structurally private medical records on Laravel 13 + Inertia 3 + Vue + Postgres: scoped query layer, private file storage (PDF, images, HEIC), on-demand exports, reminders through the existing outbox, backups of a new volume.
**Confidence:** HIGH for everything that rests on the installed code and the installed image (read and probed in this session). MEDIUM for browser behaviour (HEIC from iOS, PDF viewing). Items tagged `[ASSUMED]` need owner confirmation.

<user_constraints>
## User Constraints (from CONTEXT.md)

### Locked Decisions

#### People and access
- A medical subject (name, birth date, sex, blood type, notes) may or may not be an app user; app users can be linked to their own profile. Children and grandparents get profiles without accounts.
- Per-person access on top of the module grant (health: none by default): the admin chooses per person who can view or edit. Members see only permitted people; hidden people leave no trace (names, counts, files, search). Admins do not automatically see medical records.
- A member linked to a profile always sees and edits their own record unless the admin disables it for a child profile.
- Same structural privacy approach as Wishlist/Finance: one scoped query layer, arch test, differential/canary/query-log leak tests, opaque UUID ids, identical 403/404 for hidden vs unknown, audit of reads/writes (content-free), the Phase 6 idle gate (route group `access:module,health`, health-ish route names) and second factor on every route.

#### Records and files
- Doctor visits (date, doctor, specialty from a static versioned SK/PL/EN taxonomy plus "Other" with free text, diagnosis, notes), medications (name, dose, schedule, start/end), allergies (substance, reaction, severity), vaccinations (vaccine, date, next due).
- Vaccination due-date reminders via the Phase 3 outbox (email/push) to members who can edit that person's record; push is title-only ("Vaccination due: <person>"); never to people without access.
- Documents: PDF and images incl. HEIC/HEIF (converted to JPEG for viewing when the server can decode HEIC, original kept), attached to a person and optionally a visit; stored outside web root on a separate medical volume (Phase 4 uploads pattern), MIME sniffed from bytes, size caps, EXIF stripped from served derivatives, served only through an authenticated route with private no-cache and nosniff, included in encrypted restic backups.

#### Exports, emergency card, AI consent
- Exports by specialty and everything for one person, generated on demand and never stored, authorized and audited; printable HTML (print to PDF) and a downloadable structured file; no server-side PDF.
- AI-friendly export (JSON/Markdown) where AI-extracted data not yet verified by a user carries an "unverified" label (the flag is populated in Phase 8; format and labeling rules exist now).
- One-screen emergency card per person (allergies, medications, blood type, emergency contacts), printable, no public link.
- Per-person AI-consent flag, default off, editable by members who can edit that person, audited; stored and enforceable now, used by Phase 8.

### Claude's Discretion
Table/class names, taxonomy content, HEIC decoding approach (verify what the app image supports; otherwise store original and show a download/placeholder with a clear message), UI layout following sketch 007 and the Phase 01.1 design system, test organisation.

### Deferred Ideas (OUT OF SCOPE)
Public emergency QR link, server-side PDF generation, AI extraction (Phase 8), lab value trends/graphs, appointment scheduling.
</user_constraints>

<phase_requirements>
## Phase Requirements

| ID | Description | Research Support |
|----|-------------|------------------|
| MED-01 | Records belong to a person profile (subject) who need not be an app user | `medical_subjects` table with nullable unique `user_id` link; all record tables FK to subject (Schema section) |
| MED-02 | Access granted per subject, not only per module | `medical_subject_members` (view/edit) + own-record rule, single `Visibility` scope in `App\Medical\Queries` namespace, effective level = min(module, subject) (Access Model) |
| MED-03 | Record doctor visits (date, doctor, specialty taxonomy, diagnosis, notes) | `medical_visits`, `Specialty` enum v1 with SK/PL/EN labels (Schema, Taxonomy) |
| MED-04 | Medications and allergies per person | `medical_medications`, `medical_allergies` (Schema) |
| MED-05 | Vaccinations with reminder when next due | `medical_vaccinations` (bigint id + public uuid) + `vaccination_due` outbox kind, hourly enqueue command, send-time re-check (Reminders) |
| MED-06 | Upload PDF/images incl. HEIC, attached to person and optionally visit; served only to authorized users | `medical` disk/volume, `MedicalDocumentStore`, byte-sniffed allowlist, authenticated show/download routes (Documents) |
| MED-07 | Export by specialty | Print view + structured export scoped by `specialty` (Exports) |
| MED-08 | Export all records of a person | Same exporter, scope `all` (Exports) |
| MED-09 | AI-friendly export, unverified AI data labeled | `source` + `verified_at` on every record table, one `Provenance` presenter, JSON/Markdown format spec (Exports, Provenance) |
| MED-10 | Emergency card on one screen | Subject `blood_type`, `medical_contacts`, print-layout page, no signed/public URL (Emergency card) |
| MED-11 | AI-consent flag (default off) | `ai_consent` columns + `AiConsent::allows()` contract for Phase 8 (AI consent) |
</phase_requirements>

## Summary

Phase 7 is a copy-and-adapt of the Finance structural-privacy machinery, with four additions that Finance did not have: (1) a two-branch visibility rule (membership row OR linked-self), (2) private binary files with polyglot/HEIC hazards, (3) generated documents (exports, emergency card) that are a new leak surface, and (4) a notification kind whose recipient set depends on a per-subject rule. Everything needed already exists in the repo: `RequireAccess` already audits `health_read/health_write`, already applies the idle gate to `Module::Health`, and `SecondFactorPolicy` already covers health members. The Phase 6 `SensitiveRoutes` safety net already anticipates health routes and tells Phase 7 exactly how to register them. No new composer or npm package is needed.

The biggest finding is environmental, not architectural: the app image (`php:8.4-fpm-alpine`, PHP 8.4.27) has GD with JPEG/PNG/WebP/GIF/BMP/TGA only. Verified by running the image: `AVIF Support` empty, no `imagick`, no `imagecreatefromheic`, no libheif/libde265 apk packages. **The server cannot decode HEIC/HEIF.** The context's "converted to JPEG when the server can decode HEIC" therefore resolves to the stated fallback: store the validated original, show a placeholder with a download action, and serve it as an attachment. `finfo` (libmagic 545) does identify HEIC (`image/heic`, `image/heif`, `image/heif-sequence`) from the `ftyp` box, so sniffing and allowlisting work without any new package. A second verified trap: `finfo` reports `application/pdf` even when junk precedes `%PDF` (within its scan window), so the app must additionally require `%PDF-` at byte 0.

The existing suites constrain the plan more than the code does. `SensitiveGateMatrixTest` requires a literal `EndpointMatrix` row (with a success role and a real `params` closure) for **every** route behind `access:module,health`, and replays each in html/json/inertia/head/prefetch shapes with stale sessions and a fresh html replay with an exact status. The fresh replay cannot send multipart files, so the upload route's matrix row must literally expect the validation redirect. The suite already peaks near 125 MiB under a 256M test limit, so new fixtures must be light and shared.

**Primary recommendation:** Build `App\Medical\{Viewer,Visibility,Queries\*}` as a structural clone of `App\Finance`, route everything under `/health/...` with names `health.*` and controllers in `App\Http\Controllers\Health`, add a separate `medical` volume + disk + restic snapshot (`/medical.tar`, tags `medical`, `medical-<kind>`), treat HEIC and PDF as store-and-download-only, generate exports on demand as an Inertia print-layout page (no Blade/Vite entry) plus streamed JSON/Markdown, reuse the outbox with a `vaccination_due` handler, and extend `SensitiveRoutes`, the leak harness (a `MedicalPrivacy` clone) and the endpoint matrix in the same plans that add the routes.

## Architectural Responsibility Map

| Capability | Primary Tier | Secondary Tier | Rationale |
|------------|-------------|----------------|-----------|
| Per-subject access decision | API / Backend (`App\Medical\Visibility`) | Database (membership tables, composite FKs) | Server-side only; UI hiding is cosmetic (project constraint) |
| Module grant + idle gate + second factor | API / Backend (`RequireAccess`, `SensitiveGate`) | — | Already built; routes only declare `access:module,health,<level>` |
| Subject/record CRUD | API / Backend (Queries + Actions) | Browser (Inertia forms) | Scoped queries enforce privacy; Vue is presentation |
| File validation, sniffing, thumbnails | API / Backend (request process, GD, finfo) | — | No client trust; HEIC cannot be decoded anywhere on the server |
| File storage | Storage (`medical` Docker volume, private disk) | — | Outside web root, never mounted into nginx/postgres |
| File delivery | API / Backend (authenticated streaming route) | Browser cache (private, revalidated) | nginx never serves the volume |
| Exports (print HTML, JSON, Markdown) | API / Backend (generate on demand) | Browser (print-to-PDF) | No server-side PDF; nothing stored |
| Emergency card | API / Backend (props) | Browser (print CSS) | Authenticated page; no public link |
| Vaccination reminders | Worker/Scheduler (outbox enqueue + send) | API (preferences) | Re-uses Phase 3 outbox; send-time access re-check |
| AI consent enforcement | API / Backend (`AiConsent::allows`) | Worker (Phase 8 caller) | Single check, callable without a viewer |
| Backups | Ops scripts (restic) | — | New snapshot path/tags, freshness, restore drill |
| Nav/Home card | Browser (cosmetic) | API (shared `auth.access`) | Home card carries no private data at all |

## Standard Stack

### Core (all already installed; no new packages)
| Library | Version | Purpose | Why Standard |
|---------|---------|---------|--------------|
| laravel/framework | 13.42.0 | Routing, validation, filesystem, scheduler, queue | Project stack [VERIFIED: composer.lock] |
| inertiajs/inertia-laravel + @inertiajs/vue3 | 3.7.0 / ^3.11.0 | Pages and props | Project stack [VERIFIED: composer.lock, package.json] |
| PHP ext `fileinfo` (libmagic 545) | PHP 8.4.27 | MIME sniffing from bytes (incl. HEIC/HEIF/PDF) | Used by `ImageProcessor` already; HEIC detection confirmed [VERIFIED: ran `finfo` in family-hub-app:dev] |
| PHP ext `gd` (JPEG, PNG, WebP; no AVIF/HEIF) | bundled 2.1.0 | Re-encode image uploads, thumbnails, strip EXIF | Phase 4 pipeline [VERIFIED: `gd_info()` in image] |
| PHP ext `exif` | built in | EXIF orientation read only | Already used [VERIFIED: loaded extensions] |
| Pest 4.7.8 / Vitest ^5.1 | — | Backend and frontend tests | Project stack [VERIFIED: composer.lock, package.json] |
| Existing app code | — | `AccessGate`, `RequireAccess`, `SensitiveGate`, `AuditLogger`, `OutboxWriter`/`OutboxHandlers`, `ImageProcessor`, leak harness | Reuse, do not rebuild |

### Supporting
| Item | Purpose | When to Use |
|------|---------|-------------|
| `ImageProcessor` (generalise, do not copy) | Hardened decode/re-encode with the per-container lock | Add optional `$fullPx`/`$thumbPx` arguments (currently read from `config('wishlist.*')`); medical passes 2400 / 480 |
| `symfony/http-foundation` `BinaryFileResponse` | Range-capable file streaming, ETag/Last-Modified | Document delivery (already a dependency of Laravel) |
| `Illuminate\Support\Str::slug` | Safe download filenames | Export and document download names |
| restic, tar, docker compose (ops scripts) | Backups | New `/medical.tar` snapshot |

### Alternatives Considered
| Instead of | Could Use | Tradeoff |
|------------|-----------|----------|
| Store-and-download HEIC | `apk add imagemagick libheif` + `pecl imagick` in the Dockerfile | Needs new OS and PECL packages, a rebuilt image, ~tens of MB, and a larger decode memory budget on a 192m container. Rejected for v1: the owner asked for "no new packages" and the context allows the fallback |
| Store-and-download HEIC | Client-side canvas conversion on Safari (`createImageBitmap` decodes HEIC natively in Safari) | No package, but extra client code and a second untrusted upload; optional enhancement only (see Pitfall 3) |
| App-level file encryption (APP_KEY) | restic encryption + disk permissions | See "Encryption at rest" below; recommendation is restic + perms for v1 |
| Blade print view | Inertia page with a no-shell layout | Blade needs a `window.print()` button that CSP (`script-src 'self'`) blocks inline, so it needs its own Vite entry. Inertia page reuses i18n, tokens, history encryption, Vitest+axe. Recommend Inertia |
| Soft delete | Hard delete + audit + backups | Soft delete keeps "deleted" medical data in tables behind `withTrashed` leak risk; hard delete matches "hidden people leave no trace" |

**Installation:** none. `composer.json` and `package.json` stay unchanged.

**Version verification:** versions above were read from `composer.lock`/`package.json` and from the running image, not from memory.

## Package Legitimacy Audit

No external package is installed by this phase, so there is nothing to slopcheck.

| Package | Registry | Age | Downloads | Source Repo | slopcheck | Disposition |
|---------|----------|-----|-----------|-------------|-----------|-------------|
| (none) | — | — | — | — | n/a | — |

**Packages removed due to slopcheck [SLOP] verdict:** none
**Packages flagged as suspicious [SUS]:** none

## Architecture Patterns

### System Architecture Diagram

```
Browser (Inertia/Vue, phone/iPad/desktop)
   |  HTTPS via edge -> nginx (scoped body limit on the document upload route only)
   v
php-fpm "app"  -- middleware order: NoStore -> SetLocale -> Inertia -> EnsureRouteDeclaresAccess
   |   access:module,health,<level>  (RequireAccess)
   |      |- AccessGate: module level (none -> 403 + audit access_denied)
   |      |- SensitiveGate: 2FA enrol redirect / idle gate (423 / redirect /confirm-access)
   |      '- AuditLogger: health_read | health_write (content-free)
   v
Controllers App\Http\Controllers\Health\*   (thin; no model access)
   |  Viewer::for($user)  (module level >= view, else 403)
   v
App\Medical\Queries\*  ---- Visibility::subjectIds(Viewer, min level) ----> medical_subject_members (user_id = ?)
   |                                                                         UNION medical_subjects (user_id = ? AND self_access_enabled)
   |  hidden or unknown id  -> null -> identical 404
   +--> Records (visits, meds, allergies, vaccinations, contacts)  -> Inertia props
   +--> Documents: MedicalDocumentStore -> sniff (finfo) -> branch
   |        image  -> ImageProcessor (GD re-encode, EXIF stripped) -> {ulid}-full.jpg + {ulid}-thumb.jpg
   |        pdf    -> %PDF- at byte 0 -> store original as-is
   |        heic   -> ftyp brand allowlist -> store original as-is (no preview)
   |        -> disk "medical" (volume /var/www/html/storage/app/medical, 0600/0700)
   +--> File routes: scoped lookup -> BinaryFileResponse (nosniff, fixed Content-Type, ETag from DB sha256)
   +--> Exports: ExportQuery (scoped) -> Exporter -> Inertia print page | JSON | Markdown  (never stored, audit row first)
   '--> AiConsent::allows($subject)  (system read, bool only)  <--- Phase 8 worker

scheduler: medical:enqueue-reminders (hourly) -> VaccinationReminderQuery (system query: editors of subject)
   -> OutboxWriter (kind vaccination_due, dedupe key) -> notification_outbox
   -> DispatchOutbox -> SendOutboxItem -> VaccinationDueHandler::stillRelevant (re-check row, date, editor rights)
        -> mail (localized, minimal) | push (title only)

ops: backup.sh -> pg_dump snapshot + uploads.tar snapshot + medical.tar snapshot (own tags) -> restic -> optional off-site copy
     backup-freshness.sh / restore-drill.sh check /medical.tar as well
```

### Recommended Project Structure
```
app/Medical/
├── Viewer.php                  # user + module level, for() aborts 403 below view
├── Visibility.php              # the ONLY subject scope (members UNION self)
├── Specialty.php               # backed enum + TAXONOMY_VERSION
├── BloodType.php, Sex.php, AllergySeverity.php   # enums
├── AiConsent.php               # allows(subject) contract for Phase 8
├── Provenance.php              # source/verified presenter used by every export
├── Queries/                    # the only code that touches medical models/tables
│   ├── SubjectQuery.php, VisitQuery.php, MedicationQuery.php, AllergyQuery.php,
│   ├── VaccinationQuery.php, ContactQuery.php, DocumentQuery.php, ExportQuery.php
│   ├── AdminSubjectQuery.php   # settings: names + member ids (unscoped, admin only)
│   └── SystemQuery.php         # reminders recipients, consent read, orphan sweep (no viewer)
├── Documents/ MedicalDocumentStore.php, DocumentSniffer.php, DocumentRejected.php
├── Export/ Exporter.php, JsonFormat.php, MarkdownFormat.php, Filename.php
├── Reminders/ VaccinationDueHandler.php, EnqueueVaccinationReminders.php (logic), VaccinationDueNotification.php
└── Actions/ (create subject, mark-given, delete subject incl. files)
app/Models/Medical*.php          # arch: used only by App\Medical, Health controllers, factories, tests
app/Http/Controllers/Health/     # namespace caught by SensitiveRoutes::inSensitiveNamespace
app/Http/Controllers/Settings/HealthPeopleController.php
app/Http/Requests/Health/
config/medical.php               # caps, lead days, reminder hour, px sizes
routes/web/health*.php           # one file per area, as in Finance
resources/js/Pages/Health/ ...   # Index, Person, ExportPrint, EmergencyCard
tests/Support/MedicalPrivacy.php + tests/Support/MedicalPrivacy/*.php
tests/Support/EndpointMatrix/health-*.php
```

### Pattern 1: One visibility scope, two branches
**What:** `Visibility::subjectIds(Viewer $v, AccessLevel $min = View): Builder` returns a subquery of subject ids the viewer may touch. Branch A: `medical_subject_members` where `user_id = ?` and `level >= ?`. Branch B (own record): `medical_subjects` where `user_id = ?` and `self_access_enabled`. Joined with `union`; callers use `whereIn(<subject column>, $subquery)`. No admin branch.
**When to use:** every read and write in `App\Medical\Queries`. Record tables carry `subject_id` and scope on it; the subjects table scopes on `id`.
**Example:**
```php
// Source: adapted from app/Finance/Visibility.php (installed code)
public static function subjectIds(Viewer $viewer, AccessLevel $min = AccessLevel::View): Builder
{
    $own = DB::table('medical_subjects')->select('id')
        ->where('user_id', $viewer->userId())->where('self_access_enabled', true);

    return DB::table('medical_subject_members')->select('subject_id')
        ->where('user_id', $viewer->userId())->where('level', '>=', $min->value)
        ->union($own); // own record counts as edit
}
```
Effective level is `min(module level, subject level)`: write queries require both `$viewer->canEdit()` (module edit, also enforced by route middleware) and the `Edit` scope. Own-record branch is equal to Edit but the module cap still applies.

### Pattern 2: Hidden equals unknown
**What:** Every `findVisible($viewer, $id)` returns `null` for malformed uuid (no SQL), unknown id and hidden id alike, and the controller does `abort_if($row === null, 404)`. A subject the viewer can see but may not edit answers 403 (existence is already known to them). Resolve ids in the controller, never with route model binding (Phase 6 IN-02 lesson: binding answers differently for existing and missing ids).
**Rule:** do NOT write an `access_denied` audit row for a hidden/unknown subject id (it would make the audit page an oracle); module-level denial keeps its existing row.

### Pattern 3: Child belongs to parent, enforced twice
Composite FKs `(visit_id, subject_id) -> medical_visits (id, subject_id)` on documents, mirrors the Finance `(account_id, currency)` trick, so a document can never point at another person's visit. The query also constrains `subject_id = {person} AND id = {record}`; a mismatching `{person}/{record}` pair is the same 404.

### Pattern 4: System queries are explicit and few
Anything that runs without a viewer (reminder recipients, `AiConsent`, orphan sweep, prune) lives in `Queries\SystemQuery` with a docblock stating it is unscoped by design, returns ids/bool only, and is covered by its own tests. The query-log net applies to viewer requests only (as in Finance), and the arch test limits who may call `SystemQuery`.

### Anti-Patterns to Avoid
- **Route model binding for subjects/records:** different 404 vs 403 behaviour; also eager exposure of a model outside the scope.
- **Admin bypass or `Gate::before`:** the AccessGate already has none; do not add one for convenience (admin sees names only on the settings screen).
- **Counts or names outside scoped queries** (nav badges, Home card, settings, audit page, `<title>` tags, breadcrumbs): see Leakage Surfaces.
- **Putting names, vaccine names, diagnoses in audit meta, outbox payload, logs or flash messages.** Only opaque uuids and enums.
- **Original client filename anywhere** (storage path, Content-Disposition, log): storage names are ULIDs; the download name comes from a slug of the document title.
- **Streaming files through `Storage::response()` or `serve => true`:** that route sits outside the web group's access guard (config comment in `config/filesystems.php`).
- **Calling `abort()`/`Viewer::for()` inside queue code:** `SendOutboxItem` catches Throwable and retries 5 times; use `AccessGate::allows` and return false from `stillRelevant`.

## Access Model (question 1)

**Tables**
| Table | Key columns | Notes |
|-------|-------------|-------|
| `medical_subjects` | `id uuid` PK (v4, override `newUniqueId` to `Str::uuid()` so ids carry no timestamp), `name` 120, `birth_date date`, `sex` ('female','male','other'), `blood_type` nullable (A+,A-,B+,B-,AB+,AB-,O+,O-), `notes` 2000, `user_id` nullable **unique** FK users `nullOnDelete`, `self_access_enabled bool default true`, `ai_consent bool default false`, `ai_consent_changed_at`, `ai_consent_changed_by` (plain bigint, no FK, like audit), `created_by`, timestampsTz | birth date is required by the context; `sex` check constraint; keep `name` out of every URL |
| `medical_subject_members` | `subject_id` FK cascade, `user_id` FK cascade, `level smallint CHECK in (1,2)`, `granted_by`, `created_at`; PK `(subject_id, user_id)`; index `user_id` | 1 = view, 2 = edit, matches `AccessLevel` ints |

**Rules (recommended)**
- Creating a subject: any member with module `health` edit, or an admin. The creator is inserted as an `edit` member in the same transaction (audited `medical_subject_created`). Admin-created subjects have no members until the admin adds some.
- Membership, linking a user (`user_id`) and `self_access_enabled`: admin only, via settings, all behind `access:admin` + `access:recent` (grant routes, see SensitiveRoutes below). Admin does not become a member automatically; adding oneself is audited with a distinct event like `FinanceAdminSelfAdded`.
- Linked self: computed (Branch B), not a stored row, so toggling `self_access_enabled` cannot desync.
- The linked-self rule sits **on top of** the module grant: a member with `health = none` still gets 403 at the middleware (this keeps the Phase 6 gate unchanged). The members admin UI should warn when linking a user whose health module level is `none`. See Open Question 1.
- Delete subject: members with effective edit, with typed-name confirmation, hard delete (FK cascade) and file removal after commit, audited. Orphan sweep is the backstop (see Documents).
- Export: the sketch (`.claude/skills/sketch-findings-family-hub/references/medical-and-settings.md`) says export and running AI need Edit on that person. Recommend following the sketch (route `access:module,health,edit` plus Edit scope). See Open Question 2.
- Members who can view a person may see the list of names that share it ("Shared with"); nobody sees a person they cannot access.

**Admin UI (sketch 008 + context):** per-member "Health: people" panel in the Members permissions area (None/View/Edit per person, including people without accounts) plus a small "People" admin list for create, link-to-user and self-access toggle. Both write `medical_subject_members` through `AdminSubjectQuery`.

**How to extend the Phase 6 safety net (`tests/Support/SensitiveRoutes.php`)**
- All health routes: `access:module,health,<level>`, URI under `/health`, name containing `health`, controller under `App\Http\Controllers\Health`. `isSensitive()` then finds them automatically and `offenders()` finds a forgetful one.
- Settings admin routes: name them `settings.health_people.*` (contains `health`, so `offenders()` sees them), controller `App\Http\Controllers\Settings\HealthPeopleController` (not caught by the namespace regex, as with `FinanceAccountsController`). Then edit the test support file:
  - add the state-changing names to `accessGranting()` (store, update, members update, link) and give them `access:admin` + `access:recent`;
  - add `'settings.health_people.'` to `grantPrefixes()`;
  - add the read-only list to `grantAllowlist()` and `allowlist()` (reason: names and membership only, no records; mark PROVISIONAL for owner confirmation like the finance one);
  - if the per-member panel reuses `settings.members.permissions.*`, the new route name under that prefix must also be listed in `accessGranting()`, otherwise `unclassifiedGrantRoutes()` fails.
- Add matrix rows in `tests/Support/EndpointMatrix/health-*.php` and `settings-health-people.php` (see Validation Architecture).

## Records Schema (question 2)

Dates are `date` columns with `date:Y-m-d` casts (no time, no timezone shift). "Today" for reminders and card state is `now(config('family.timezone'))->toDateString()` computed in PHP, never `CURRENT_DATE` (database timezone is not the family zone). Timestamps are `timestampTz` (existing convention).

| Table | Columns (beyond `subject_id` FK cascade, `created_by`, timestampsTz) |
|-------|------------------------------------------------------------------|
| `medical_visits` (`id uuid`, unique `(id, subject_id)`) | `visited_on date`, `doctor` 120 null, `specialty_key` 40, `specialty_other` 80 null, `diagnosis` 2000 null (free text; no ICD in v1), `notes` 4000 null; CHECK `(specialty_key = 'other') = (specialty_other IS NOT NULL)`; index `(subject_id, visited_on desc, id)` |
| `medical_medications` (`id uuid`) | `name` 120, `dose` 120 null, `schedule` 160 null, `started_on` null, `ended_on` null (CHECK `ended_on >= started_on`), `notes` 1000 null |
| `medical_allergies` (`id uuid`) | `substance` 120, `reaction` 200 null, `severity` ('mild','moderate','severe','unknown') |
| `medical_vaccinations` (`id bigint identity` internal + `public_id uuid unique`) | `vaccine` 120, `given_on date`, `next_due_on date null` (CHECK `next_due_on >= given_on`), `notes` 500 null; partial index on `next_due_on WHERE next_due_on IS NOT NULL`. Bigint id exists only because `notification_outbox.subject_id` and `audit_logs.subject_id` are `unsignedBigInteger`; it is never exposed (model `$hidden`, presenters use `public_id`) |
| `medical_contacts` (`id uuid`) | `name` 120, `relation` 60 null, `phone` 40 (loose pattern), `sort smallint`; cap 5 per subject in the request/action |
| `medical_documents` (`id uuid`) | `visit_id uuid null` (composite FK to visits), `kind` ('pdf','image','heic'), `title` 120, `mime` 40, `size_bytes`, `sha256 char(64)`, `path`, `thumb_path` null, `width`/`height` null, `uploaded_by` |

**Provenance fields (design now, populated in Phase 8):** every record table (visits, medications, allergies, vaccinations; contacts and documents excluded) carries `source varchar(6) NOT NULL DEFAULT 'manual' CHECK (source IN ('manual','ai'))`, `verified_at timestamptz NULL`, `verified_by bigint NULL`. Definition (one place, `Provenance`): a record is **unverified** iff `source = 'ai' AND verified_at IS NULL`. Manual records are verified by construction and never carry `verified_at`. Add a DB CHECK `(source = 'ai') OR (verified_at IS NULL)` so a manual record can never carry a verification stamp. Phase 8 sets `source='ai'` on create and `verified_at` on user acceptance. Documents carry no provenance in v1.

**Deletes:** hard delete; cascade from subject; no soft delete (Alternatives table). Medical data persists in restic snapshots for the retention window (daily 14, weekly 8, monthly 6 in production); note this for the owner (erasure is not immediate in backups).

### Specialty taxonomy (versioned static data)
Keys live in `App\Medical\Specialty` (string-backed enum) with `public const TAXONOMY_VERSION = 1`. The database stores only the key (no CHECK listing keys, so adding a specialty in a later version needs no migration); `Rule::enum(Specialty::class)` validates. Labels are lang keys `health.specialty.<key>` in `lang/{en,sk,pl}.json` (this brings them under the existing key-parity Vitest pattern, `tests/js/i18n-phase*.test.js`). Exports emit `specialty_key`, localized `specialty_label` and `taxonomy_version`. Proposed v1 list (SK/PL labels are `[ASSUMED]`, need native review as for Phase 5 copy):

| key | en | sk | pl |
|-----|----|----|----|
| general_practice | General practitioner | Všeobecný lekár | Lekarz rodzinny |
| pediatrics | Pediatrics | Pediatria | Pediatria |
| internal_medicine | Internal medicine | Interná medicína | Choroby wewnętrzne |
| gastroenterology | Gastroenterology | Gastroenterológia | Gastroenterologia |
| cardiology | Cardiology | Kardiológia | Kardiologia |
| pulmonology | Pulmonology | Pneumológia | Pulmonologia |
| endocrinology | Endocrinology | Endokrinológia | Endokrynologia |
| diabetology | Diabetology | Diabetológia | Diabetologia |
| nephrology | Nephrology | Nefrológia | Nefrologia |
| urology | Urology | Urológia | Urologia |
| gynecology | Gynecology and obstetrics | Gynekológia a pôrodníctvo | Ginekologia i położnictwo |
| dermatology | Dermatology | Dermatovenerológia | Dermatologia |
| ophthalmology | Ophthalmology | Oftalmológia | Okulistyka |
| ent | Ear, nose and throat | Otorinolaryngológia | Laryngologia |
| orthopedics | Orthopedics | Ortopédia | Ortopedia |
| neurology | Neurology | Neurológia | Neurologia |
| psychiatry | Psychiatry | Psychiatria | Psychiatria |
| psychology | Psychology | Psychológia | Psychologia |
| rheumatology | Rheumatology | Reumatológia | Reumatologia |
| oncology | Oncology | Onkológia | Onkologia |
| hematology | Hematology | Hematológia | Hematologia |
| allergology | Allergology and immunology | Alergológia a imunológia | Alergologia i immunologia |
| infectious_diseases | Infectious diseases | Infektológia | Choroby zakaźne |
| surgery | Surgery | Chirurgia | Chirurgia |
| rehabilitation | Rehabilitation and physiotherapy | Rehabilitácia a fyzioterapia | Rehabilitacja i fizjoterapia |
| dentistry | Dentistry | Stomatológia | Stomatologia |
| radiology | Radiology and imaging | Rádiológia a zobrazovacie metódy | Radiologia i diagnostyka obrazowa |
| laboratory | Laboratory tests | Laboratórne vyšetrenia | Badania laboratoryjne |
| geriatrics | Geriatrics | Geriatria | Geriatria |
| other | Other (free text) | Iné (vlastný text) | Inne (własny tekst) |

Sorting inside the picker by localized label (`Intl.Collator` per locale), with `other` last. Specialty folders in the UI group visits (and their attached documents) by `specialty_key`; `other` visits group by key `other`, the free text is the visit's own label. Use ICU/`trans_choice` plurals for counts ("1 návšteva / 2 návštevy / 5 návštev"), never string concatenation (sketch finding, existing pipe-plural convention).

## Documents (question 3)

### What the installed image supports (verified)
| Capability | Result |
|-----------|--------|
| GD | JPEG, PNG, WebP, GIF, BMP, TGA read; AVIF empty; no HEIF |
| `imagick`, `imagecreatefromheic`, `imagecreatefromavif` | absent |
| libheif/libde265/imagemagick apk packages | absent |
| `finfo` sniff of ISO-BMFF `ftyp` | `heic`,`heix` -> `image/heic`; `mif1`,`heis` -> `image/heif`; `msf1` -> `image/heif-sequence`; `avif` -> `image/avif` |
| `finfo` on `junk\n%PDF-1.7` and on 500 spaces then `%PDF-` | `application/pdf` (too lenient) |
| `zip`, `exif`, `fileinfo`, `bcmath`, `intl`, `pcntl` | present |
All from running `family-hub-app:dev` (PHP 8.4.27). **Note** the prod image is built from the same base layers; rebuilds are needed anyway (Phase 4/5 DEFERRED notes say staging/prod images need a rebuild via deploy).

### Type policy (recommended)
| Detected (from bytes) | Accept | Stored | Served |
|----------------------|--------|--------|--------|
| JPEG, PNG, WebP (GD decodes) | yes | **sanitized re-encode only**: `{ulid}-full.jpg` (long edge capped at 2400 px, q82, no EXIF/GPS, orientation applied) + `{ulid}-thumb.jpg` (480 px). Original is not kept (drops GPS EXIF, bounds size, removes polyglot tails). Discretion item; lossless original retention can be added later | inline, `image/jpeg`, thumb and view variants |
| GIF, BMP, TGA, SVG, AVIF, anything else | reject (`unsupported_type`) | — | — |
| PDF | yes, only if the first 5 bytes are `%PDF-` AND `finfo` says `application/pdf` | original bytes, unchanged | **attachment only** (`Content-Disposition: attachment`), `application/pdf`, nosniff. No server rendering, no thumbnail |
| HEIC/HEIF (`image/heic`, `image/heif`, `image/heif-sequence`) | yes if `ftyp` major brand in `heic heix hevc hevx heim heis hevm hevs mif1 msf1` | original bytes, unchanged | **attachment only**, `image/heic` or `image/heif`, nosniff. UI shows a placeholder "Preview not available, download to view" (and a clear note that the download keeps the photo's metadata) |

Caps (config/medical.php, Claude's discretion): file max 10 MiB, pixel cap 13 MP (reuse `wishlist.image_max_pixels` value so the proven memory budget holds), 200 documents per subject. nginx location for the upload route `client_max_body_size 12m`, PHP `upload_max_filesize=11M`, `post_max_size=13M` (global PHP limits; nginx keeps every other route at 1m), `max_file_uploads` stays 2 (one file per request, the client loops for multi-page reports).

### Safety rules
- MIME comes from bytes, never from `$file->getClientMimeType()` or the filename. iOS can send a `.heic` name with JPEG bytes or the reverse [CITED: WebKit bug threads via search, see Sources]; the sniffed type decides and the extension is derived from it.
- Check order: size, then `getimagesizefromstring` pixel cap before any decode (a tiny PNG header can claim 20000x20000), then `finfo`, then the branch. HEIC/PDF skip decode entirely.
- Never keep or echo the client filename. `title` defaults to the basename with control characters stripped and 120-char cut; it is user-editable text (escaped by Vue).
- Download filename: `Str::slug($title)` (ASCII) + extension from the sniffed type, fallback `document`. This avoids header injection and non-ASCII `Content-Disposition` problems.
- Response headers on every file route: fixed `Content-Type`, `X-Content-Type-Options: nosniff`, `Content-Security-Policy: default-src 'none'`, `Referrer-Policy: no-referrer` (the NoReferrer middleware exists), `Vary: Cookie`, `Cache-Control` per below, `Content-Disposition` attachment for PDF/HEIC.
- PDF inline viewing is not offered in v1 (global `X-Frame-Options: DENY` and CSP `frame-ancestors 'none'` already block framing; the browser's own viewer on a top-level attachment download is outside our control). Do not add a `sandbox` CSP to PDF responses: it is reported to break Chrome's built-in PDF viewer `[ASSUMED]`. Active-content scanning of PDFs (searching for `/JavaScript`) has high false-negative and false-positive rates and is not recommended; antivirus is out of scope (note in docs).
- Atomic write: write both files, verify both returned non-false (the disk is `throw=false`), only then insert the row; on any failure delete the new files first (Phase 4 WR-03 lesson, `WishImageStore::put`). Insert the row inside a transaction with the file write after, or delete files if the insert fails.
- Orphan sweep: a command `medical:prune-orphans` (daily) deletes files with no row older than 60 minutes, analogous to `WishImageStore::sweepOrphans`; subject deletion removes the directory `{subject}/` after commit.
- ETag: from DB `sha256` + variant (stable, no path leakage), `isNotModified($request)` after the access check, so a 304 can only follow an authorized request.

### Cache headers (note the existing middleware)
`NoStoreDynamicResponses` rewrites `Cache-Control` on every response except `wishlist.images.show`. The context says "private no-cache". Recommended split:
- Image **thumb/view** derivatives: `private, no-cache` + ETag (add `health.documents.show` to the middleware exception list next to the wishlist one, only for 200/304).
- **PDF, HEIC and every download**: leave the default `no-store, private` (stricter; nothing lands in the disk cache of a shared family iPad). Flag to the owner because it is slightly stricter than the wording of the decision (Open Question 3).
- Never `public`, never `s-maxage`. The service worker must keep not caching private data; add the medical routes to the existing sw tests (`tests/js/sw-precache.test.js`, `scripts/check-sw-precache.mjs` patterns).

### Storage and volume
- New disk in `config/filesystems.php`: `medical` => local, root `storage_path('app/medical')`, `serve => false`, `throw => false`, `report => false`, `visibility => 'private'`, permissions file 0600 / dir 0700 (`permissions` key of the local driver `[ASSUMED: from Laravel filesystem docs, verify on implement]`). Do not nest under `app/private` so the `local` disk's `allFiles()` code can never touch it.
- compose: add `medical` named volume, mounted at `/var/www/html/storage/app/medical` into `app`, `worker`, `scheduler` (extend the existing `x-uploads` anchor in `compose.yaml` and `compose.server.yaml`); never `nginx` or `postgres`. `scripts/check-compose.sh` asserts the wish-images mount list and "no other service mounts it"; extend it for the new path.
- **Pitfall:** a fresh named volume takes ownership from the image directory. Add `mkdir -p storage/app/medical` + `chown` to the Dockerfile `dev` and `prod` targets (as done for `wish-images`), otherwise the first write fails with root-owned storage and `WishImageWriteFailed`-style errors.
- Worker mounts it now because Phase 8 needs the files; the scheduler needs it for the orphan sweep.

### Encryption at rest (cost vs benefit)
Recommendation for v1: **no application-level file encryption**; rely on restic (encrypted, already required) for backups and on 0600/0700 permissions inside a private volume.
- Threat gain is small: `APP_KEY` lives in `app.env` on the same VPS and the app container, so a host or container compromise yields key and files together. It protects only a volume-only leak.
- Cost: `Crypt::encryptString` is not streaming (a 10 MiB file becomes about 14 MB base64 inside JSON, decrypted fully into memory, Range/streaming lost) on a 192m container with a 128M `memory_limit`; `APP_KEY` rotation requires re-encrypting every file; losing the key makes every backup useless; it is inconsistent while diagnoses and notes stay plaintext in Postgres.
- Keep a seam: all file IO goes through `MedicalDocumentStore` (read/write/delete), so a libsodium secretstream layer can be added later without touching controllers. `[ASSUMED]` that the owner accepts this residual risk; record in the Assumptions Log.

### Backups (extend, do not fork)
Current mechanism (`scripts/backup.sh`): database snapshot (`/<db>.dump`, tags `<env>` + kind), uploads snapshot (`/uploads.tar`, tags `<env>`, `uploads`, `uploads-<kind>`), restic retention per tag+path, off-site copy of the explicit snapshot ids, `backup-freshness.sh` (nightly DB and `uploads-nightly`), `restore-drill.sh` (lists the newest uploads tar), `test-backup-local.sh` (end-to-end proof with a marker file).
Plan for the medical volume:
- Generalise the uploads block of `backup.sh` into a function run for `(UPLOADS_PATH, /uploads.tar, uploads)` and `(MEDICAL_PATH, /medical.tar, medical)`; snapshot tags `<env>`, `medical`, `medical-<kind>`; **never** the bare kind tag (so `--tag nightly` still means database). Skip on `pre-migrate` like uploads.
- Add `MEDICAL_PATH` (default `/var/www/html/storage/app/medical`) to the key list read from `.env` (Phase 4 IN-04 lesson: a key missing from the list is silently ignored).
- Retention: `forget --tag medical-nightly --path /medical.tar` with the same keep policy. Include the new snapshot id in `COPY_IDS` and the copy log line.
- Freshness: add `medical-nightly` / `/medical.tar` to `backup-freshness.sh`. After the first deploy the check fails until one backup ran: have deploy run a `manual` backup, or accept one stale alert.
- Restore drill: parallel block for `medical-$DRILL_TAG`, list the tar, print `RESTORE-DRILL MEDICAL OK files=N`; refuse a medical snapshot id as drill argument (as for uploads).
- `test-backup-local.sh`: add a medical marker file and the same eight assertions (counts, tags without `manual`, copy repo, drill, failure leaves no new snapshot).
- A tar failure for medical must set a failure flag but still let the database snapshot and the other upload finish (same `UFAIL` pattern, one flag per volume).
- Medical files are encrypted by restic (`--password-file`); the off-site repo has its own password file. Never write a plaintext tar to disk (existing design streams).
- Docs: `docs/ops/server.md` runbook entry (volume, snapshot names, how to restore a person's files).

### nginx
Add one location in `docker/nginx/snippets/laravel-locations.conf`, same pattern as the wish image upload: `location ~ ^/health/people/[0-9a-f-]{36}/documents$ { client_max_body_size 12m; include security-headers; include fastcgi-app; }` (pins SCRIPT_NAME so the route still resolves). It must match only the POST upload URL (GET list of documents is part of the person page, not this URI). Extend `scripts/check-upload-limit.sh` and `scripts/check-nginx-template.sh` with the new location (413 on any other route, not 413/404 on this one).

## Reminders (question 4)

Reuse the Phase 3 outbox exactly as `wish_occasion` does.
- Register `'vaccination_due' => VaccinationDueHandler::class` in `OutboxHandlers::HANDLERS`. Place the handler, notification and command logic in `App\Medical\Reminders` so `App\Notifications\Outbox` never references Medical Queries or models (arch rule stays simple).
- Command `medical:enqueue-reminders`, scheduled hourly with `->withoutOverlapping()->name('medical-enqueue-reminders')` in `routes/console.php` (same shape as `occasions:enqueue`), gated by `config('medical.reminder_hour')` (default 8) in `family.timezone`.
- Lead days (discretion): `[14, 0]` days before `next_due_on`, fire on D-lead with one day of grace after an outage, never after D (copy the occasions condition). No per-user lead preference in v1 (existing notification preferences already gate channels per user: `NotificationPreferences::enabled`).
- Recipients: members whose effective level on the subject is Edit **and** whose module level is edit **and** (linked self with edit). Computed by `SystemQuery::reminderRecipientIds($subjectId)`; the same predicate is re-checked at send time. Never members without access, never admins by virtue of admin.
- Dedupe key (unique, 191 chars): `vaccination_due:{vaccination bigint id}:{next_due_on}:{lead}:{user_id}:{channel}`. Editing `next_due_on` changes the key and the old row becomes irrelevant (see below). `OutboxWriter::enqueue('vaccination_due', $user, $channel, 'vaccination', $vaccinationId, $key, ['person' => <subject uuid>, 'vaccination' => <public_id>, 'date' => 'YYYY-MM-DD', 'lead' => N])`. Payload: ids and dates only.
- `stillRelevant($item, $user)`: vaccination row exists, `next_due_on` equals payload date, due date not past, `lead` is still in config, subject exists, `AccessGate::allows($user, Health, Edit)` and `SystemQuery::isEditor($subjectId, $user->id)`. Return false (row marked skipped) for any miss. Do not call `Viewer::for` (it aborts with an HttpException that the job would retry).
- Push: `pushMessage` returns title only: localized "Vaccination due: <person>" and `url = /health/people/<uuid>` (title and target only reach the lock screen, `SendOutboxItem` already enforces that). The owner explicitly accepted the person name in the title.
- Mail: subject = same title; body = person name, due date, link; **omit the vaccine name** (recommendation: mailboxes are third-party storage). Build an explicit text/plain part from unescaped strings and escape the Markdown part, per the Phase 4 WR-06 lesson (`WishOccasionNotification::plain`). Extract that helper to a shared trait rather than copying.
- Lang keys: `lang/{en,sk,pl}/notifications.php` (`vaccination_title`, `vaccination_body`, `vaccination_action`), plural form for "in N days" via `trans_choice` (already used for gifts); SK/PL need three/four plural forms, so use the existing pipe-plural convention and the `TransChoiceTest` pattern.
- Mark-given flow (UX and correctness): a "Mark as given" action records a new vaccination (`given_on` today, optional next due) and clears `next_due_on` on the old row in one transaction, so the old reminder goes irrelevant. Without it, users would keep stale due dates.
- Timezone: compare `next_due_on` (date) with family-local today in PHP.
- `outbox:prune` already deletes finished rows after 30 days; payload has no medical content.
- Arch test: `App\Medical\Reminders` must not use `App\Models\User` mail internals beyond `notifyNow`, and the handler's payload keys are asserted by a canary test (see Validation).

## Exports (question 5)

**Authorization and audit.** Routes `health.exports.print` (page), `health.exports.download` (`{format}` = `json|md`), both `access:module,health,edit` (see Open Question 2), throttled with the route name as bucket prefix (`throttle:10,1,health.exports.download`). Flow: resolve subject through the scoped query with Edit scope (hidden/unknown = 404), **write the audit row first** (`medical_exported`, meta `scope` in `person|specialty|emergency`, `format` in `html|json|md`, `subject` = uuid; fail closed like `RequireAccess`), then generate. `RequireAccess` additionally writes its normal `health_read` row.

**Never stored.** No `Storage::put`, no cache. `Cache-Control: no-store, private`. The structured file is built in memory: it is text only (documents appear as metadata, never bytes), typically well under 1 MB; add a feature test with a 500-visit fixture to prove memory stays flat, and use `lazy()`/`cursor()` in `ExportQuery`. If a person ever exceeds this, switch to `response()->streamDownload`.

**Scopes and options (query string, validated):** `scope=all|specialty`, `specialty=<key>` (required for `specialty`), `include_summary=1` (current medications and allergies header, default on for the doctor export), `include_unverified=0|1` (default **0**, per sketch 007; when 1 every unverified item is labeled), `anonymize=1` (AI formats only, default on for `md`: the person's name is omitted and `birth_date` is replaced by `age_years`, per sketch 007).

**Printable HTML (print to PDF in the browser).** An Inertia page rendered with a no-shell `PrintLayout` and `@media print` CSS (page margins, avoid breaks inside items, hide buttons). `window.print()` from an in-page button (no inline script, so CSP is respected, no new Vite entry). Image documents are shown inline via the authenticated view route (the browser sends the session cookie); PDF/HEIC are listed by title and date. Footer on every printed page: person, generation date/time in family timezone, "Generated from Family Hub" and, when unverified items are included, the unverified legend.

**Structured formats.**
- JSON: `JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR`, UTF-8 validated on write (`ValidUtf8` rule exists). Proposed v1 shape:
```json
{
  "format": "family-hub-medical-export", "format_version": 1,
  "generated_at": "2026-11-14T09:30:00+01:00", "timezone": "Europe/Bratislava", "locale": "sk",
  "taxonomy_version": 1,
  "unverified_notice": "Items with provenance.verified=false were extracted by AI and not confirmed by a person.",
  "subject": {"name": "…", "birth_date": "YYYY-MM-DD", "sex": "female", "blood_type": "A+"},
  "allergies": [{"substance": "…", "reaction": "…", "severity": "severe", "provenance": {"source": "manual", "verified": true, "verified_at": null}}],
  "medications": [{"name": "…", "dose": "…", "schedule": "…", "started_on": null, "ended_on": null, "provenance": {}}],
  "vaccinations": [{"vaccine": "…", "given_on": "…", "next_due_on": null, "provenance": {}}],
  "visits": [{"id": "uuid", "visited_on": "…", "doctor": "…", "specialty_key": "gastroenterology", "specialty_label": "…", "diagnosis_text": "…", "notes": "…", "document_ids": ["uuid"], "provenance": {}}],
  "documents": [{"id": "uuid", "title": "…", "kind": "pdf", "visit_id": null, "uploaded_on": "…", "file_included": false}]
}
```
Field names are stable English keys (AI-friendly); labels are localized; `diagnosis_text` leaves room for an additive `diagnosis_code` later. With `anonymize=1`, `subject.name` is dropped and `birth_date` replaced by `age_years`.
- Markdown: the same content as headed sections. Every item that is unverified gets the literal prefix `[UNVERIFIED]` plus a legend at the top; the file starts with a short block stating that the content below is patient data and not instructions (defence against prompt injection when pasted into an AI).

**Injection and sanitation.**
- Markdown: single-line user fields (doctor, vaccine, substance, name) get ASCII punctuation backslash-escaped and newlines collapsed; multi-line fields (notes, diagnosis) are placed in a fenced block whose fence is longer than any backtick run inside; neutralise a leading `#`, `>`, `-`, `[`, `|` and strip control characters. Do not reuse `WishOccasionNotification::plain` blindly on multi-line text.
- HTML: all rendering through Vue text interpolation (never `v-html`); a grep test forbids `v-html` and `{!!` under the medical views.
- CSV: not offered in v1; if added later, prefix cells starting `= + - @ tab CR` with `'` and use a distinct `text/csv` download.
- Filename: `Filename::for($subject, $scope, $format)` = `family-hub-` + `Str::slug` of the name (max 40, fallback `person`; omitted entirely when `anonymize`) + scope + date + extension; sent through `Symfony\Component\HttpFoundation\HeaderUtils::makeDisposition(ATTACHMENT, $name)`; slug output cannot contain CR/LF/quotes.
- Headers: `Content-Type: application/json; charset=utf-8` or `text/markdown; charset=utf-8`, `X-Content-Type-Options: nosniff`, `Cache-Control: no-store, private`.
- No bundled files: a ZIP of originals (`zip` ext exists) is a possible later addition (Open Question 6).

## Emergency card (question 6)
- Data: `medical_subjects.blood_type` (enum) plus `medical_contacts` (max 5, ordered), plus allergies (all, severity first) and **current** medications (`ended_on IS NULL OR ended_on >= today`). No new free-text field in v1 beyond what the context lists.
- Route: `health.emergency.show` (GET Inertia page, no-shell print layout, one printed page; allergy severity visible without colour alone for a11y and for B/W printing). Behind the module gate like everything else; a card for a person visible to the viewer at **view** level is acceptable (it is the one item a view-only member may print; this is a deliberate exception to the export-needs-edit rule and should be confirmed, Open Question 2).
- No public link: no `URL::signedRoute`/`temporarySignedRoute`, no token column, no unauthenticated route. An arch/grep test forbids `signedRoute` anywhere under `App\Medical` and `App\Http\Controllers\Health`.
- Audit: `medical_exported` with `scope=emergency`.
- Offline: the service worker must never cache this page; ambulance-time access is by paper copy (deferred idea: Wallet/lock-screen card).

## AI consent (question 7)
- Storage: columns on `medical_subjects` (`ai_consent` default false NOT NULL, `ai_consent_changed_at`, `ai_consent_changed_by`).
- Who may edit: members with effective Edit on the subject (route `PATCH /health/people/{person}/ai-consent`, `access:module,health,edit`, Edit scope). A linked user with self-access has Edit on own record. Admin cannot, unless also an edit member.
- Audit: new event `medical_consent_changed`, meta `subject` (uuid), `value` (`on|off`), written in the same transaction as the update (a failing audit write rolls the change back, Phase 6 WR-04 lesson).
- Enforcement contract for Phase 8: `App\Medical\AiConsent::allows(MedicalSubject|string $subject): bool` (and `allowsDocument(string $documentId)`). It reads through `SystemQuery` (no viewer, bool only, `false` for a missing subject, deleted subject or any error). Phase 8 MUST call it at job dispatch and again immediately before the CLI is invoked (consent can be revoked in between). An arch test in Phase 8 should assert the AI adapter classes `use` `AiConsent`.
- Phase 7 test: toggling off makes `allows()` false immediately; default is false for a new subject; the flag is part of the export `subject` block only when the viewer can edit; it never appears in Home, nav or notifications.

## Home card, nav and leakage surfaces (question 8)
- Home: **no private data at all**. Keep the generic health `ModuleCard`, but give `MODULES.health` a real `href: '/health'` (currently `null`, so it opens the coming-soon panel; update `tests/js/shell/moduleAccess.test.js` which asserts `href` is null, plus Home/AppShell tests). Do not add a `healthCard` prop to `HomeController` (add `App\Http\Controllers\HomeController` to the arch `not->toUse` list for Medical queries and models, like finance). `home` is not behind the gate (Phase 6 PROVISIONAL decision for the finance card); with no data there is nothing to decide. Optional later: counts only when `SensitiveSession` is fresh.
- Nav: health item appears for any member with module level view/edit (cosmetic, existing `visibleModules`). It carries no badge, no count, no person name.
- Empty states must be identical for "no people exist" and "none visible to you".
- `<title>`, breadcrumbs, flash toasts, validation messages, error pages and the `Errors/Forbidden` page must never contain a person name for a person the viewer cannot access; toasts use lang keys only (existing `toast: ['key' => ...]` pattern).
- Settings admin screen shows names and member lists (metadata, admin only), never record counts or titles; the admin audit page shows uuids only.
- Audit page: new events need `AuditEvent` cases, `metaKeys()` (`subject`, `change`, `scope`, `format`, `value`), `group()` = `health`, `AuditTable.vue` detail label branch and `audit.detail.*` lang keys in 3 locales; extend the audit content canary test so subject names, diagnoses and file titles never appear in `audit_logs`.
- Inertia shared props (`HandleInertiaRequests::share`) must not gain anything medical (an arch/source scan like the existing "shares no factor data" test).
- Reminder surfaces: see Reminders (the title naming the person is the one owner-accepted disclosure).
- Search: no global search in v1; list filters (specialty, year) run through scoped queries only.

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| Module/level decision, idle gate, 2FA, audit write | A medical-specific auth layer | `access:module,health,<level>` (`RequireAccess`) | Already audited, already gated, safety net finds routes by it |
| Image sanitising | New GD pipeline | `ImageProcessor` (parameterised) | Hardened, lock-serialised, memory-measured (59 MB at 13 MP) |
| MIME detection | Extension or header trust, magic-byte tables | `finfo` on bytes + one `%PDF-` offset check + ftyp brand allowlist | libmagic handles HEIC; hand tables rot |
| Notification fan-out and retries | A new job table | Phase 3 outbox (`OutboxWriter`, `OutboxHandlers`, `SendOutboxItem`) | Dedupe, backoff, claim, send-time re-check exist |
| Content-Disposition building | String concatenation | `HeaderUtils::makeDisposition` + `Str::slug` | Header injection and quoting |
| JSON encoding | Manual string building | `json_encode(..., JSON_THROW_ON_ERROR)` | Escaping and errors |
| Permission policy | CASL/Gates package | One `Visibility` + `AccessGate` (project rule) | Three levels do not need an abstraction |
| Backups | A second backup tool | `backup.sh` generalised for a second path | Same restic repo, retention and copy semantics |
| Leak testing | Ad hoc asserts | Clone of the `FinancePrivacy` harness | Differential, canary, query-log and mutation sanity already proven |
| PDF generation | Server-side renderer | Browser print of the print page | Out of scope (deferred) |

**Key insight:** the risk in this phase is not a missing library, it is a missing scope on one of ~25 new surfaces. Every surface must be enumerable from the router so the net grows with the code.

## Runtime State Inventory

Not a rename/refactor/migration phase. Greenfield tables and a new volume. One related runtime state item: the existing `Module::Health` rows in `module_permissions` and audit rows `health_read/health_write` already exist in staging/production; nothing is renamed and no data migration is needed.

## Common Pitfalls

### Pitfall 1: `finfo` calls a PDF with leading junk a PDF
**What goes wrong:** A polyglot (HTML or script before `%PDF`) passes the MIME check and is later opened by some viewer.
**Why:** libmagic scans a window for `%PDF`; verified in the image.
**How to avoid:** require bytes 0..4 == `%PDF-` as well as `application/pdf`; serve attachment with nosniff and fixed type.
**Warning signs:** fixture `"<script>alert(1)</script>\n%PDF-1.7"` is accepted.

### Pitfall 2: Fresh named volume is root-owned
**What goes wrong:** Upload returns an error on first use in staging; `Storage::put` returns false (`throw=false`).
**Why:** a new Docker volume copies ownership from the image directory; the directory does not exist in the image.
**How to avoid:** `mkdir -p` + `chown` in both Dockerfile targets (dev uid `app`, prod `www-data`); a smoke step (`touch`) in `smoke-server.sh`.

### Pitfall 3: HEIC arrives, no preview, user confusion; iOS may transcode anyway
**What goes wrong:** Users upload iPhone photos and see only a placeholder.
**Why:** server cannot decode HEIC (verified). Safari can transcode a picked HEIC to JPEG depending on the `accept` list and version, and iOS can label JPEG bytes with a `.heic` name `[CITED: WebKit bugs via search]`.
**How to avoid:** trust sniffed bytes only; for the file input use `accept="image/jpeg,image/png,image/webp,application/pdf,.heic,.heif"` after testing on a real iPhone/iPad (Phase 4's live iPhone upload check is deferred, so this stays an owner device check). Placeholder copy must explain "download to view". Optional cheap enhancement: try `<img>` of the original with an `onerror` placeholder (Safari renders HEIC natively) `[ASSUMED]`. Not a blocker.

### Pitfall 4: Default replay requires a literal matrix row per route
**What goes wrong:** `SensitiveGateMatrixTest` fails ("Sensitive routes without a usable matrix row") the moment a health route exists without a row, and the fresh html replay needs an exact status.
**How to avoid:** add `EndpointMatrix/health-*.php` rows in the same plan as each route. Upload: the harness cannot send files, so the literal expectation is the validation redirect (302, Location not `/confirm-access`). File routes need `params` closures that create a subject, membership and a document with a faked disk file for the role the matrix uses (typically `parent`; the harness `Roles::make()` users have module grants but no subject membership, so the closure must create membership).

### Pitfall 5: Suite memory budget
**What goes wrong:** Adding about 25 routes to an already heavy replay pushes the suite past 256M.
**How to avoid:** keep fixtures tiny (a 1x1 JPEG, 20-byte PDF, synthetic ftyp box), reuse `Roles::make()` caches, do not seed per route, use `Storage::fake('medical')`, and keep the memoised `EndpointMatrix::all()`.

### Pitfall 6: Query-log regex expects one predicate shape
**What goes wrong:** `unscopedReads` (Finance) matches `finance_account_members ... where|on ... user_id = ?`. The medical scope is a `union`; if the `members` branch is assembled with `whereIn` on a different column order the regex misses it and every statement reads as unscoped.
**How to avoid:** the `MedicalPrivacy` clone defines the predicate against `medical_subject_members` and the own-record branch; include helper self-tests with literal SQL (as `FinancePrivacyTest` does) for the exact Laravel-generated union text, then build the scope to match. `subjects` table statements need the predicate on `id`, records on `subject_id`; list every table in `READ_TABLES` and classify all `medical_*` tables (`FAMILY_WIDE_TABLES` should be empty or justified; the schema-classification test fails if a table is unclassified).

### Pitfall 7: Outbox queue code that aborts
**What goes wrong:** `Viewer::for($user)` inside `stillRelevant` throws `HttpException(403)`; `SendOutboxItem` catches it as a send failure and retries up to five times.
**How to avoid:** use `AccessGate::allows` and boolean system queries.

### Pitfall 8: `NoStoreDynamicResponses` overrides your file headers
**What goes wrong:** Setting `Cache-Control: private, no-cache` on the document route has no effect (rewritten to `no-store, private`), or the opposite for ETag revalidation.
**How to avoid:** deliberate: either keep `no-store` (documents) or add the route name to the middleware exception (derivatives), and test both headers.

### Pitfall 9: Settings routes named `*health*` silently allowlisted or unclassified
**What goes wrong:** `offenders()` and `unclassifiedGrantRoutes()` flag the new settings routes until classified.
**How to avoid:** classify in the same plan (see "How to extend").

### Pitfall 10: Decode memory with two big uploads
**What goes wrong:** A 10 MiB upload plus a 13 MP decode on a 192m container.
**How to avoid:** the per-container flock in `ImageProcessor::exclusive` already serialises decodes; keep bytes in one string, free GD resources, and keep `memory_limit=128M`. HEIC/PDF never decode.

### Pitfall 11: Audit parity oracle
**What goes wrong:** Writing `access_denied` only for hidden subjects makes the admin audit page distinguish hidden from unknown.
**How to avoid:** no audit row for either (see Pattern 2).

### Pitfall 12: Cascade deletes leave files
**What goes wrong:** FK cascade removes rows but the volume keeps the files.
**How to avoid:** delete the directory after commit in the action; nightly orphan sweep as backstop; test that deleting a subject leaves no file on the fake disk.

## Code Examples

### Strict document sniffing
```php
// Source: verified behaviour of finfo in family-hub-app:dev; structure follows app/Wishlist/Catalog/ImageProcessor.php
final class DocumentSniffer
{
    private const HEIC_BRANDS = ['heic','heix','hevc','hevx','heim','heis','hevm','hevs','mif1','msf1'];

    /** @return array{kind: 'pdf'|'image'|'heic', mime: string} */
    public function sniff(string $bytes): array
    {
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        if ($mime === 'application/pdf' && str_starts_with($bytes, '%PDF-')) {
            return ['kind' => 'pdf', 'mime' => 'application/pdf'];
        }
        if (in_array($mime, ['image/heic', 'image/heif', 'image/heif-sequence'], true)
            && strlen($bytes) >= 12 && substr($bytes, 4, 4) === 'ftyp'
            && in_array(substr($bytes, 8, 4), self::HEIC_BRANDS, true)) {
            return ['kind' => 'heic', 'mime' => $mime];
        }
        if (in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            return ['kind' => 'image', 'mime' => $mime];
        }
        throw new DocumentRejected('unsupported_type');
    }
}
```

### Authenticated file response
```php
// Source: pattern of app/Http/Controllers/Wishlist/Owner/WishImageController::show (installed) + Symfony BinaryFileResponse
$doc = $documents->findVisible($viewer, $person, $document) ?? abort(404);   // hidden == unknown == mismatched pair
$path = Storage::disk('medical')->path($variant === 'thumb' ? $doc->thumb_path : $doc->path);
abort_unless(is_file($path), 404);
$response = new BinaryFileResponse($path, 200, [
    'Content-Type' => $doc->servedMime($variant),          // fixed per kind, never the client's
    'X-Content-Type-Options' => 'nosniff',
    'Content-Security-Policy' => "default-src 'none'",
    'Vary' => 'Cookie',
    'ETag' => '"'.substr($doc->sha256, 0, 32).$variant.'"',
]);
if ($asAttachment) {
    $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, Str::slug($doc->title).'.'.$doc->extension());
}
$response->isNotModified($request);
return $response;
```

### Reminder condition (copy of occasions logic)
```php
// Source: app/Console/Commands/EnqueueOccasionReminders.php (installed)
foreach (config('medical.vaccination_lead_days') as $lead) {
    if ($daysAway > $lead || $daysAway < $lead - 1 || $daysAway < 0) { continue; }  // D-lead, one day grace, never after D
    // enqueue per recipient and channel with the dedupe key above
}
```

### Mutation sanity for the new net (shape, from FinanceLeakSuiteTest)
```php
Route::middleware(['web', 'access:module,health,view'])->get('/health/zz-mutant-count',
    fn () => response((string) DB::table('medical_visits')->count()))->name('health.zz_mutant_count');
// the differential, canary and query-log checks must each catch it while real surfaces stay identical
```

## State of the Art

| Old Approach | Current Approach | When Changed | Impact |
|--------------|------------------|--------------|--------|
| Server converts HEIC with ImageMagick | Store original, Safari/iOS transcodes at pick time, or client-side conversion | ongoing | We cannot decode on this image; do not promise previews |
| Trust `finfo` alone for PDFs | `finfo` + byte-0 `%PDF-` check | n/a | Closes the leading-junk polyglot |
| Per-module permission only | Per-subject membership + module cap (this project's Finance pattern since Phase 5) | Phase 5 | Reuse the pattern |

**Deprecated/outdated:** nothing in the stack. Note Laravel 13's `HasUuids` default generates ordered (v7) uuids; this phase overrides to v4 for subjects/documents so ids leak no creation time `[ASSUMED]` default behaviour; check `Str::uuid()`/`newUniqueId` on implement.

## Assumptions Log

| # | Claim | Section | Risk if Wrong |
|---|-------|---------|---------------|
| A1 | Linked-self access stays subject to the module grant (health module level must still be view/edit) rather than implying module access | Access Model | If the owner expects a child with no health grant to reach their own record, a separate route or gate exception is needed, which would bypass the Phase 6 gate |
| A2 | Exports require Edit on the person (from sketch 008); emergency card needs only View | Access Model, Exports, Emergency card | Wrong level means either too permissive bulk export or blocking view-only members from printing the card |
| A3 | PDF and HEIC/derived downloads use `no-store, private`; image derivatives use `private, no-cache` + ETag | Documents | Slightly stricter than the decision text; owner may want `no-cache` everywhere |
| A4 | No application-level file encryption in v1 (restic + permissions) | Documents | If the threat model includes volume-only leaks, an encryption layer is needed |
| A5 | JPEG/PNG/WebP originals are not kept (sanitized re-encode only) | Documents | If the owner wants lossless originals for doctors, add original retention with EXIF warning |
| A6 | Admin can see names and member lists of all people on the settings screen (no records, no counts) | Access Model | Owner may want admin to see only people they are a member of |
| A7 | Home card carries no data; nav carries no badges | Home card | Owner may want vaccination due counts on Home |
| A8 | Reminder leads `[14, 0]`, mail omits vaccine name, push title names the person (owner-accepted) | Reminders | Wording/lead preferences differ |
| A9 | SK and PL specialty labels and notification copy are my translations | Taxonomy | Needs native review (same deferred item as Phase 5 copy) |
| A10 | Laravel local filesystem `permissions` array accepts `file.private`/`dir.private` modes as described | Documents | Mode config wrong -> files 0644; verify when implementing |
| A11 | Chrome PDF viewer breaks under a CSP `sandbox` directive | Documents | Only affects an option we do not use |
| A12 | iOS/Safari picker transcoding of HEIC depends on `accept` list and version | Pitfall 3 | Must be confirmed on a real iPhone/iPad |
| A13 | Delete subject allowed for edit members (not admin-only), hard delete | Access Model | Owner may want admin-only or a soft-delete grace period |
| A14 | One person can have at most one linked user and one user at most one profile (unique `user_id`) | Schema | Shared-profile cases not supported |

## Open Questions

1. **Does a linked member reach their own record without a `health` module grant?**
   - Known: the route middleware requires module `health >= view`; presets give `health = none` to everyone.
   - Unclear: the owner's intent for "always sees and edits their own record".
   - Recommendation: keep the module grant as prerequisite (A1) and have the Members UI warn on linking; revisit only if the owner wants zero-grant self access.
2. **Export and emergency-card level.** Sketch says export needs Edit. Recommendation: export = Edit, emergency card = View. Confirm.
3. **Cache header split for files** (A3). Recommendation as stated; confirm or switch all to `private, no-cache`.
4. **Admin visibility of person names** (A6). Recommendation: names and member lists only.
5. **Who may delete a person** (A13).
6. **ZIP bundle of original files with the all-records export.** Not in the context; deferred unless requested.
7. **HEIC preview.** Install `libheif` + `imagick` later if iPhone users complain; needs a new image layer and a memory budget decision.

## Environment Availability

| Dependency | Required By | Available | Version | Fallback |
|------------|------------|-----------|---------|----------|
| Docker | Test and build stack | ✓ | 29.9.2 | — |
| `family-hub-app:dev` image | Probing, tests | ✓ | PHP 8.4.27 | — |
| PHP `gd` JPEG/PNG/WebP | Image documents | ✓ | bundled 2.1.0 | — |
| PHP `gd` AVIF / HEIF decode | HEIC preview | ✗ | — | Store original, download-only (chosen) |
| `imagick`, libheif | HEIC preview | ✗ | — | same |
| `fileinfo` (libmagic) | MIME sniffing | ✓ | libmagic 545 | — |
| `zip`, `exif`, `intl`, `bcmath` | Optional zip export, orientation, plurals | ✓ | built in | — |
| Host PHP | not used (tests run in container) | ✓ | 8.3.6 (differs from image 8.4.27) | use the container |
| restic, tar on the server | Backup of medical volume | not probed (server side, used by existing scripts) | — | — |
| Postgres 18 (compose) | Schema | ✓ in compose files | postgres:18-alpine | — |

**Missing dependencies with no fallback:** none.
**Missing dependencies with fallback:** HEIC decoding (store and download only).

## Validation Architecture

### Test Framework
| Property | Value |
|----------|-------|
| Framework | Pest 4.7.8 (PHP, Postgres test DB `family_hub_test`) + Vitest ^5.1 with axe |
| Config file | `phpunit.xml` (256M test memory), `vitest.config.js` |
| Quick run command | `docker compose run --rm app ./vendor/bin/pest --compact` |
| Targeted run | `docker compose run --rm app ./vendor/bin/pest tests/Feature/Health --compact` |
| Full suite command | `docker compose run --rm app php -d memory_limit=1G ./vendor/bin/pest && npx vitest run` |

### Phase Requirements to Test Map
| Req ID | Behavior | Test Type | Automated Command | File Exists? |
|--------|----------|-----------|-------------------|-------------|
| MED-01 | Subject without account; link unique; self-access toggle | feature | `pest tests/Feature/Health/SubjectTest.php` | ❌ Wave 0 |
| MED-02 | Scope: members, linked-self, no admin bypass, min(module, subject) | feature + unit | `pest tests/Feature/Health/VisibilityTest.php` | ❌ Wave 0 |
| MED-02 | Arch: Queries/models used only by Medical layers; Home/Inertia share blind | arch | `pest tests/Unit/Arch/MedicalPrivacyArchTest.php` | ❌ Wave 0 |
| MED-02 | Differential/canary/query-log leak suite incl. files, exports, emergency card | feature | `pest tests/Feature/Health/MedicalPrivacyTest.php` | ❌ Wave 0 |
| MED-02 | Hidden == unknown on every record-id route and write; scoped mutations | feature | `pest tests/Feature/Health/MedicalWriteParityTest.php` | ❌ Wave 0 |
| MED-02 | Mutation sanity (unscoped GET, INSERT, DELETE mutants caught) | feature | `pest tests/Feature/Health/MedicalLeakSuiteTest.php` | ❌ Wave 0 |
| MED-03 | Visit CRUD, taxonomy validation, other-text rule, lang parity | feature + vitest | `pest tests/Feature/Health/VisitTest.php`; `vitest tests/js/i18n-phase7.test.js` | ❌ Wave 0 |
| MED-04 | Medications and allergies CRUD, date checks | feature | `pest tests/Feature/Health/MedicationAllergyTest.php` | ❌ Wave 0 |
| MED-05 | Vaccination CRUD, mark-given, enqueue (lead, grace, dedupe), handler re-check, title-only push, locale | feature | `pest tests/Feature/Health/VaccinationReminderTest.php` | ❌ Wave 0 |
| MED-06 | Upload type policy (JPEG/PNG/WebP/PDF/HEIC accept; SVG/GIF/polyglot reject), EXIF stripped, headers, 404 parity, orphan sweep, delete cleanup | feature + unit | `pest tests/Feature/Health/DocumentTest.php tests/Unit/Health/DocumentSnifferTest.php` | ❌ Wave 0 |
| MED-06 | nginx limit scoped to upload route; compose volume mounts | script | `bash scripts/check-upload-limit.sh`; `bash scripts/check-compose.sh` | ✅ extend |
| MED-06 | Backup/restore/freshness of `/medical.tar` | script | `bash scripts/test-backup-local.sh` | ✅ extend |
| MED-07/08 | Export by specialty and all; options; audit row first; filename; never stored; large fixture memory | feature | `pest tests/Feature/Health/ExportTest.php` | ❌ Wave 0 |
| MED-09 | JSON/Markdown format spec, unverified labeling and exclusion, anonymize, Markdown/HTML injection fixtures | feature + unit | `pest tests/Unit/Health/ExportFormatTest.php` | ❌ Wave 0 |
| MED-10 | Emergency card content, no signed URL, view-level access | feature | `pest tests/Feature/Health/EmergencyCardTest.php` | ❌ Wave 0 |
| MED-11 | Default off, edit-only toggle, audited, `AiConsent::allows` | feature | `pest tests/Feature/Health/AiConsentTest.php` | ❌ Wave 0 |
| Gate | Every health route in matrix; stale-session replay (all shapes, no audit rows, grant routes need recent) | feature | `pest tests/Feature/Security/SensitiveGateMatrixTest.php` | ✅ (extends via matrix files) |
| Audit | New events allowlisted and content-free; canary scan | feature | `pest tests/Feature/Audit tests/Feature/Security/SecurityAuditContentTest.php` | ✅ extend |
| UI | Pages, print layout, a11y (axe) SK/PL/EN light/dark, SW never caches `/health` | vitest | `npx vitest run tests/js/health` | ❌ Wave 0 |

### Test-by-surface leak plan (the heart of this phase)
1. **Harness:** `tests/Support/MedicalPrivacy.php` cloned from `FinancePrivacy` with area files `tests/Support/MedicalPrivacy/{subjects,visits,medications,allergies,vaccinations,documents,exports,emergency,settings,home}.php`. Scenario: viewer V (Parent preset, health edit) and co-member O; visible subject A (V and O), hidden subject B (O only), a hidden-by-flag linked subject C (`self_access_enabled=false` for V's own linked profile), canary strings `ZxCanaryMed` in names, diagnoses, doctor, vaccine, file titles, contact names, phone numbers, document bytes (distinctive JPEG pixel text is not needed: use the document `title` and bytes marker in a PDF fixture).
2. **Surfaces enumerated from the router at run time**: every GET `health.*`, `settings.index`, `settings.health_people.*`, `home`, the audit page; both Inertia JSON and HTML. File and export routes are captured with `BinaryFileResponse`/`StreamedResponse` handling (the existing `normalise()` md5s binary bodies).
3. **Differential:** capture as V before and after seeding hidden data B and C; bodies, statuses and headers must be identical (files and exports too, including `Content-Disposition` and `ETag`).
4. **Canary:** no needle in any captured body, header, filename, Inertia prop, `<title>`, toast or flash.
5. **Query log:** every statement touching a `medical_*` table in a viewer request carries the scope predicate; helper self-tests with literal SQL; table classification test (every `medical_*` table scope-checked or declared).
6. **Mutation sanity:** `health.zz_mutant_*` routes (unscoped count, grouped aggregate, weak-scope, unscoped INSERT/DELETE, a file route with no subject filter, an export that skips the scope) must each be caught while real surfaces stay green.
7. **Parity:** every record-id route (subjects, visits, medications, allergies, vaccinations, contacts, documents show/download/update/destroy, exports, consent, emergency card) answers a hidden id exactly like a random uuid, including a mismatched `{person}/{record}` pair and malformed ids (no SQL issued for malformed ids).
8. **Aggregates:** assert no endpoint returns counts across subjects (grep test for `count(`/`sum(` outside `Queries` and a differential on every list).
9. **Admin proof:** admin without membership gets an empty list, 404 on a known subject uuid, and never sees records; admin self-add is audited.
10. **Reminders:** a user without access never gets an outbox row; a user whose access was removed after enqueue gets `skipped`; payload canary scan; push body is title only.
11. **Audit content:** subject names, diagnoses, titles, filenames never appear in `audit_logs`, logs, outbox payload or `last_error`.
12. **Stale-session replay:** matrix rows make the existing `SensitiveGateMatrixTest` replays cover every new route in html/json/inertia/head/prefetch with no audit rows; settings grant routes also replay under `access:recent`.
13. **Service worker:** `/health` responses are never precached or runtime cached (extend `sw-precache.test.js` and the build check).

### File fixtures (generated in memory like `ImageFixtures`, no binaries from the internet)
- `ImageFixtures::jpegWithExif()` (exists) -> assert served derivative has no EXIF/GPS bytes and orientation applied.
- Minimal valid PDF string; PDF with leading junk (must be rejected); `%PDF-` followed by `<script>` (accepted as PDF, served attachment + nosniff + CSP none); a JPEG with appended `%PDF-` tail (re-encoded, tail gone); a JPEG with PHP tag tail (stored name is `.jpg` from sniff, no execution path, outside web root).
- Synthetic HEIC: `pack('N', 24).'ftyp'.'heic'.pack('N',0).'heic'.'mif1'` + padding (finfo -> `image/heic`); wrong-brand `ftyp` (rejected); truncated; size over cap; 0-byte.
- PNG header claiming 20000x20000 (pixel cap rejection before decode); SVG; GIF; AVIF `ftyp avif` (rejected); a `.heic` filename with JPEG bytes (accepted as image, extension from bytes).
- Large-subject export fixture (500 visits) for the memory assertion.

### Sampling Rate
- **Per task commit:** `pest --compact` on the touched area (`tests/Feature/Health`, `tests/Unit/Health`, relevant arch file)
- **Per wave merge:** full Pest + `npx vitest run`
- **Phase gate:** full suite green, `npm run build` prints `SW-PRECACHE OK`, backup/compose/nginx scripts pass, before `/gsd-verify-work`

### Wave 0 Gaps
- [ ] `tests/Support/MedicalPrivacy.php` + `tests/Support/MedicalPrivacy/*.php` (harness clone)
- [ ] `tests/Support/EndpointMatrix/health-*.php`, `settings-health-people.php` (literal rows)
- [ ] `tests/Support/MedicalFixtures.php` (PDF, HEIC, polyglot, EXIF fixtures; reuse `ImageFixtures`)
- [ ] `tests/Unit/Arch/MedicalPrivacyArchTest.php`
- [ ] `database/factories/Medical*Factory.php` + `User` factory helper `withSubjectAccess`
- [ ] `tests/js/i18n-phase7.test.js` (key parity for `health.*`, new `audit.detail.*`, notifications keys) and `tests/js/health/*`
- [ ] Extend `SensitiveRoutes` (`accessGranting`, `grantPrefixes`, `grantAllowlist`, `allowlist`) in the same plan as the settings routes
- Framework install: none.

## Security Domain

`security_enforcement` is on (config) with `security_asvs_level: 1`; the data class (health) justifies applying selected L2 controls (marked).

### Applicable ASVS Categories

| ASVS Category | Applies | Standard Control |
|---------------|---------|-----------------|
| V2 Authentication | yes (inherited) | Fortify + second factor for health members (Phase 6); `access:recent` on admin grant routes |
| V3 Session Management | yes (inherited) | Idle gate `SensitiveGate`, session rotation on confirm (Phase 6) |
| V4 Access Control | yes (core of phase) | `Visibility` scope, deny-by-default, module cap, no admin bypass, identical 404, L2: record-level and function-level checks tested per route |
| V5 Input Validation | yes | FormRequests with `Rule::enum`, length caps, date rules, `ValidUtf8`; file validation from bytes; L2: size/pixel/count limits |
| V6 Cryptography | partly | No new crypto; backups encrypted by restic; app-level file encryption deliberately deferred (A4); `Str::uuid()` for opaque ids |
| V7 Error handling and logging | yes | Content-free audit with allowlisted meta; no names or content in logs, outbox payload, errors |
| V8 Data protection | yes (L2) | No-store/no-cache headers, private volume, 0600/0700, no public links, retention note for backups, minimal push/mail content |
| V10 Malicious code | partly | No dynamic code from uploads; antivirus out of scope (documented) |
| V12 Files and resources | yes | Sniffed type allowlist, outside web root, ULID names, nosniff, attachment for PDF/HEIC, SSRF n/a (no URL fetch), upload size scoped in nginx |
| V13 API/Web service | partly | Throttles named per route, CSRF (web group), JSON export content types |
| V14 Configuration | yes | CSP/headers via nginx snippets, volume not mounted into nginx/postgres, `check-compose.sh` assertion |

### Known Threat Patterns for this stack

| Pattern | STRIDE | Standard Mitigation |
|---------|--------|---------------------|
| IDOR on subject/record/document ids | Information disclosure | Scoped queries, uuid v4, identical 404, parity tests |
| Aggregate or count leak (nav, home, lists, exports, settings) | Information disclosure | Counts only from scoped queries; Home carries no data; admin screen without counts; grep + differential tests |
| Hidden-vs-unknown oracle via status, body, headers, timing, audit | Information disclosure | Same code path and response; no audit row on either; ETag/Content-Disposition equal in differential |
| Admin privilege creep | Elevation of privilege | No bypass; membership edits behind `access:admin` + `access:recent`; self-add audited distinctly |
| Polyglot / mislabeled upload | Tampering | Byte sniffing, `%PDF-` offset, ftyp brand allowlist, re-encode images, fixed response type, nosniff, CSP none |
| Decompression/pixel bomb | Denial of service | Pixel cap before decode, per-container decode lock, size cap, nginx body limit scoped |
| Stored XSS via names, notes, titles, exports | Tampering | Vue interpolation only, no `v-html`; Markdown escaping; JSON encoder |
| Markdown/CSV injection and AI prompt injection in exports | Tampering | Escape/fence, instruction preface for AI formats; no CSV in v1 |
| Header injection in downloads | Tampering | `Str::slug` + `HeaderUtils::makeDisposition` |
| Cached private data on shared devices | Information disclosure | `no-store` default, derivatives `private, no-cache` + ETag only after auth, service worker never caches `/health` |
| Phishing-by-reminder / data in push or mail | Information disclosure | Title-only push (owner-accepted name), minimal mail without vaccine, recipients re-checked at send time |
| Consent bypass by Phase 8 | Tampering | Single `AiConsent::allows` checked at dispatch and before CLI; arch test in Phase 8 |
| Stale or stolen session | Spoofing | Idle gate, second factor, stale-session matrix replay on every route |
| Backup exposure and slow erasure | Information disclosure | restic encryption; separate snapshot; documented retention; never plaintext tar on disk |
| Orphaned files after delete | Information disclosure | Delete after commit + orphan sweep |

## Sources

### Primary (HIGH confidence, read in this session)
- Installed code: `app/Access/*`, `app/Http/Middleware/{RequireAccess,NoStoreDynamicResponses,HandleInertiaRequests,EnsureRouteDeclaresAccess}.php`, `app/Security/{SensitiveGate,SecondFactorPolicy,SensitiveSession}.php`, `app/Finance/{Viewer,Visibility,HomeSummary}.php`, `app/Finance/Queries/AccountQuery.php`, `app/Finance/Accounts/AccountAdmin.php`, `app/Wishlist/Catalog/{ImageProcessor,WishImageStore}.php`, `app/Http/Controllers/Wishlist/Owner/WishImageController.php`, `app/Notifications/Outbox/*`, `app/Jobs/SendOutboxItem.php`, `app/Console/Commands/EnqueueOccasionReminders.php`, `app/Audit/*`, `routes/web/*.php`, `routes/console.php`, `bootstrap/app.php`
- Installed tests and harness: `tests/Support/{SensitiveRoutes,FinancePrivacy,EndpointMatrix,Roles,ImageFixtures}.php`, `tests/Feature/Security/SensitiveGateMatrixTest.php`, `tests/Feature/Finance/{FinanceLeakSuiteTest,FinancePrivacyTest,FinanceWriteParityTest,VisibilityTest}.php`, `tests/Unit/Arch/*`
- Ops: `docker/app/Dockerfile`, `docker/app/php-*.ini/conf`, `docker/nginx/*`, `compose.yaml`, `compose.server.yaml`, `scripts/{backup,backup-freshness,restore-drill,check-compose,check-upload-limit,test-backup-local}.sh`
- Probes run in `family-hub-app:dev`: `gd_info()`, loaded extensions, `finfo` results for HEIC/HEIF/AVIF/PDF fixtures (PHP 8.4.27, libmagic 545)
- Planning: `07-CONTEXT.md`, `REQUIREMENTS.md`, Phase 4/5/6 `DEFERRED.md` and `06-REVIEW-FIX.md`, `04-REVIEW-FIX.md`, `.claude/skills/sketch-findings-family-hub/references/medical-and-settings.md`, `composer.lock`, `package.json`

### Secondary (MEDIUM confidence)
- [WebKit/Apple forum threads on HEIC transcoding by file inputs](https://developer.apple.com/forums/thread/743049) and [WebKit bug 292350](https://bugs.webkit.org/show_bug.cgi?id=292350) via web search: behaviour depends on `accept` list and Safari version
- [coping with HEIC in the browser](https://shkspr.mobi/blog/2020/12/coping-with-heic-in-the-browser/) (Safari decodes HEIC natively, others do not)

### Tertiary (LOW confidence)
- Chrome PDF viewer vs CSP `sandbox` (training knowledge, unverified, A11)
- Laravel local-driver `permissions` array details (training knowledge, A10)

## Metadata

**Confidence breakdown:**
- Standard stack: HIGH, nothing new, all read from lock files and the image
- Architecture: HIGH, direct structural clone of installed Finance/Wishlist/Phase 6 code
- Access model details: MEDIUM, several owner-confirmable choices (A1, A2, A6, A13)
- Documents/HEIC: HIGH for server capability (probed), MEDIUM for browser behaviour
- Exports/reminders: MEDIUM-HIGH, patterns exist; copy and levels need owner confirmation
- Pitfalls: HIGH, most come from this repo's own review-fix history

**Research date:** 2026-11-14
**Valid until:** 2026-12-14 (stable stack; re-verify HEIC/browser claims if the image or iOS version changes)
