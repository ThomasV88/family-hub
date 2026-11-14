---
phase: 07-medical-records
reviewed: 2026-11-14T00:00:00Z
depth: deep
files_reviewed: 62
files_reviewed_list:
  - app/Medical/Viewer.php
  - app/Medical/Visibility.php
  - app/Medical/PersonPage.php
  - app/Medical/EmergencyCard.php
  - app/Medical/AiConsent.php
  - app/Medical/Provenance.php
  - app/Medical/Documents/DocumentSniffer.php
  - app/Medical/Documents/MedicalDocumentStore.php
  - app/Medical/Documents/DocumentRejected.php
  - app/Medical/Export/Exporter.php
  - app/Medical/Export/Filename.php
  - app/Medical/Export/JsonFormat.php
  - app/Medical/Export/MarkdownFormat.php
  - app/Medical/Queries/AdminSubjectQuery.php
  - app/Medical/Queries/AllergyQuery.php
  - app/Medical/Queries/ContactQuery.php
  - app/Medical/Queries/DocumentQuery.php
  - app/Medical/Queries/ExportQuery.php
  - app/Medical/Queries/MedicationQuery.php
  - app/Medical/Queries/SubjectQuery.php
  - app/Medical/Queries/SystemQuery.php
  - app/Medical/Queries/VaccinationQuery.php
  - app/Medical/Queries/VisitQuery.php
  - app/Medical/Reminders/VaccinationDueHandler.php
  - app/Medical/Reminders/VaccinationDueNotification.php
  - app/Medical/Reminders/VaccinationReminderPlanner.php
  - app/Http/Controllers/Health/AiConsentController.php
  - app/Http/Controllers/Health/AllergyController.php
  - app/Http/Controllers/Health/DocumentController.php
  - app/Http/Controllers/Health/EmergencyCardController.php
  - app/Http/Controllers/Health/ExportController.php
  - app/Http/Controllers/Health/MedicationController.php
  - app/Http/Controllers/Health/PersonController.php
  - app/Http/Controllers/Health/VaccinationController.php
  - app/Http/Controllers/Health/VisitController.php
  - app/Http/Controllers/Settings/HealthPeopleController.php
  - app/Http/Requests/Health/AiConsentRequest.php
  - app/Http/Requests/Health/ExportRequest.php
  - app/Http/Requests/Health/MedicationRequest.php
  - app/Http/Requests/Health/StoreDocumentRequest.php
  - app/Http/Requests/Health/StorePersonRequest.php
  - app/Http/Requests/Health/UpdateDocumentRequest.php
  - app/Http/Requests/Health/UpdatePersonRequest.php
  - app/Http/Requests/Health/VaccinationRequest.php
  - app/Http/Requests/Health/VisitRequest.php
  - app/Http/Requests/Health/MarkVaccinationGivenRequest.php
  - app/Http/Requests/Settings/HealthPeopleLinkRequest.php
  - app/Http/Requests/Settings/HealthPeopleMembersRequest.php
  - app/Http/Middleware/NoStoreDynamicResponses.php
  - app/Http/Middleware/RequireAccess.php
  - app/Security/SensitiveGate.php
  - app/Console/Commands/PruneMedicalOrphans.php
  - app/Console/Commands/EnqueueVaccinationReminders.php
  - app/Wishlist/Catalog/ImageProcessor.php
  - routes/web/health.php
  - routes/web/health-documents.php
  - routes/web/settings-health-people.php
  - config/medical.php
  - config/filesystems.php
  - compose.yaml
  - compose.server.yaml
  - docker/nginx/snippets/laravel-locations.conf
  - scripts/backup.sh
  - scripts/restore-drill.sh
  - resources/js/Health/DocumentPanel.vue
  - resources/js/Pages/Health/Export.vue
  - resources/js/Health/DocumentPreview.vue
  - resources/js/sw.js
findings:
  critical: 0
  warning: 12
  info: 5
  total: 17
status: issues_found
---

# Phase 7: Code Review Report

**Reviewed:** 2026-11-14 | **Depth:** deep | **Status:** issues_found

The privacy core holds up. Every read, UPDATE and DELETE goes through `Visibility::apply` with the Edit minimum on writes. Controllers and FormRequests give the same 403 for malformed, unknown, hidden and view-only person ids. The upload pipeline sniffs bytes, uses server-generated ULID paths validated by a strict regex, re-encodes images, and never serves from a public path. Exports and the emergency card write the audit row before building. All health routes sit behind `access:module,health,*`, which calls `SensitiveGate`. Models use `$guarded = ['*']` with explicit `forceFill`. No Critical issue was found. The warnings are in audit quality, admin-link auditing, partial-PATCH data loss, cache policy, a Safari download bug, destructive-operation safeguards and consent semantics.

## Critical Issues

None.

## Warnings

### WR-01: Admin `link()` lets an admin self-grant Edit on any person without the self-added audit event

**File:** `app/Medical/Queries/AdminSubjectQuery.php:110-150`
**Issue:** `syncMembers` records `MedicalAdminSelfAdded` when the actor grants themselves access (line 98). `link()` does the same thing by another route. Linking the admin's own user id to a person gives them the own-record branch of `Visibility` (Edit, because `self_access_enabled` defaults to true). It is audited only as the generic `MedicalAccessChanged` with `change=linked`, so any alert or UI highlight keyed on the self-added event never fires. `link()` also skips the "target has Health module" check that `HealthPeopleMembersRequest` enforces.
**Fix:** In `link()`, when `$userId === $actor->id` and the link is new, record `AuditEvent::MedicalAdminSelfAdded` instead. Also refuse a link when `AccessGate::level($target, Module::Health) === None`, with the same single `link_failed` validation error.

### WR-02: Reads are audited without subject or document id, and reads of previews and files are unthrottled

**File:** `app/Http/Middleware/RequireAccess.php:98`, `routes/web/health-documents.php:8-11`
**Issue:** `afterDecision` writes `HealthRead` with empty meta for every GET. The audit log therefore cannot answer "who opened whose record or document". Only exports and the emergency card carry `subject`. Each `<img>` thumbnail fetch writes one audit row, so one person page with 200 documents writes about 200 rows. The `preview` and `file` routes have no `throttle` middleware, while the write routes do. A compromised session can bulk-download every document and flood the audit table.
**Fix:** Add `subject` to the `HealthRead` meta allow-list. Have the controllers record a content-free `MedicalOpened {subject, kind}` event on person show and document file download only, and skip the generic row for `health.documents.preview`. Add `throttle:120,1,health.documents.preview` and `throttle:30,1,health.documents.file`.

### WR-03: PATCH with a partial payload silently wipes stored fields (visit and person)

**File:** `app/Medical/Queries/VisitQuery.php:107`, `app/Medical/Queries/SubjectQuery.php:150-156`
**Issue:** `VisitQuery::attributes` uses `$data[$field] ?? null`, and `SubjectQuery::update` writes `$data['birth_date'] ?? null`, `sex ?? 'unspecified'`, `blood_type ?? null` and `notes ?? null`. `MedicationQuery` and `VaccinationQuery` deliberately keep values the client did not send. A PATCH of `{name: "x"}`, or any client that omits an optional field, erases diagnosis, notes, blood type and birth date with no audit. Blood type is safety data on the emergency card.
**Fix:** Use the `array_key_exists($field, $data)` pattern in both places, as Medication and Vaccination do. Or make the request require every field and document PATCH as full replace.

### WR-04: Safari and iPad can cancel blob downloads because the object URL is revoked synchronously

**File:** `resources/js/Health/DocumentPanel.vue:123`, `resources/js/Pages/Health/Export.vue:57`
**Issue:** `link.click()` is followed immediately by `URL.revokeObjectURL(url)`. iPad Safari and some iOS versions resolve the blob lazily, so the download fails or opens an empty tab. iPad is a stated target device. The failure is silent, with no error path.
**Fix:** `setTimeout(() => URL.revokeObjectURL(url), 30_000)`. Do this in a shared helper in `securityTransfer.js`, since the two copies are identical.

### WR-05: Document image previews are browser-cacheable (`private, no-cache` with an ETag)

**File:** `app/Http/Controllers/Health/DocumentController.php:85`, `app/Http/Middleware/NoStoreDynamicResponses.php:21`
**Issue:** The rest of the module is `no-store, private`. Preview images (scans of medical documents) are written to the browser's disk cache and survive logout on shared family devices (stated risk: shared iPad). `no-cache` forces revalidation on reuse, but the bytes stay on disk, and back/forward navigation can display them. This breaks the "browser caches" requirement for the most sensitive content.
**Fix:** Send `Cache-Control: no-store, private` for previews and drop the 304 exception. The cost is re-downloading about 40 KB thumbnails. If revalidation is kept as a deliberate trade-off, record it as an owner decision.

### WR-06: Any Edit-level member, including a person on their own record, can hard-delete a whole person and all their documents

**File:** `app/Medical/Queries/SubjectQuery.php:181-210`, `app/Http/Controllers/Health/PersonController.php:57-64`
**Issue:** The cascade removes the person, visits, allergies, medications, vaccinations, contacts, documents and files in one request. `self_access_enabled` gives every linked user Edit on their own record, and Edit members can delete other people's records. There is no server-side confirmation, no soft-delete window and no extra gate such as `access:recent`. The docblock calls this "provisional". Backups are the only recovery, and the DB and files are backed up as separate snapshots. This is a data-loss risk that should be settled before real data is entered.
**Fix:** Soft-delete with a retention window, or restrict hard delete to a "creator or admin with recent confirmation" rule and add `access:recent` to the route. At minimum, require a typed confirmation field validated server-side.

### WR-07: AI consent can be switched on by any Edit member for a person who has their own account

**File:** `app/Medical/Queries/SubjectQuery.php:105-139`, `app/Http/Requests/Health/AiConsentRequest.php:20-25`
**Issue:** `setConsent` is gated by the Edit scope only. A parent, partner or any other Edit member can enable AI processing of an adult's medical documents, which the person themselves never agreed to, even when `user_id` is set. That conflicts with the intent of a per-person consent flag. Flipping the flag is also silent to the person: no notification, and the audit row is visible only to admins.
**Fix:** When `medical_subjects.user_id` is not null, allow the change only for that user. Otherwise allow Edit members, and require a minor flag or an explicit guardian rule. Consider notifying the linked user when consent is changed by someone else.

### WR-08: The orphan sweep has no circuit breaker and can delete every medical file if it points at an empty or wrong database

**File:** `app/Console/Commands/PruneMedicalOrphans.php:17-37`, `app/Medical/Queries/SystemQuery.php:54-70`
**Issue:** A file is deleted when `knownPaths` does not list it. After a DB restore from an older snapshot, a staging or prod mix-up, or a failed migration leaving `medical_documents` empty, the next daily run irrecoverably deletes all files older than 60 minutes, and every subject directory when `medical_subjects` is empty. There is no dry-run mode and no ratio limit.
**Fix:** Abort without deleting when the candidate count exceeds a threshold (for example more than 20 files and more than 25% of the inventory), or when `medical_documents` is empty but files exist. Add a `--dry-run` option and log the counts.

### WR-09: Exception text can write medical content to `laravel.log`

**File:** `config/logging.php:64-71`, `bootstrap/app.php:55-76`
**Issue:** There is no exception scrubber. A `QueryException` (a check-constraint violation, a foreign key violation when a person is deleted during an upload, or an unexpected invalid-UTF8 byte) carries the full SQL with bound values, such as diagnosis, notes, medication names and titles, into `storage/logs`. `.env.example` defaults to `LOG_LEVEL=debug` and `APP_DEBUG=true`. Logs are not covered by the medical retention or backup policy. `MedicalLeakSuiteTest` does not exercise log output.
**Fix:** Register `$exceptions->dontReport(...)` or a `reportable` hook that rewrites `QueryException` to a message without bindings (use `getPrevious()->getMessage()` and strip the `(Connection: ... SQL: ...)` tail) when the SQL touches `medical_*`. Make the server `.env` default `LOG_LEVEL=warning` and add a leak test that forces a constraint failure and asserts the log has no payload.

### WR-10: TOCTOU on the per-person document cap, and a window of file-without-row

**File:** `app/Http/Controllers/Health/DocumentController.php:46`, `app/Medical/Queries/DocumentQuery.php:54-80`
**Issue:** `countForSubject >= max` is checked before the file write and without a lock, so concurrent uploads (10 allowed at once from the UI) can exceed 200. File write and row insert are not atomic. A crash between them leaves an orphan file that exists only until the sweep runs, which WR-08 makes risky to rely on. `DocumentQuery::update` also resolves the visit and updates in separate statements, so a visit deleted between the two raises an FK violation (500) instead of the single validation error.
**Fix:** Take `lockForUpdate` on the `medical_subjects` row in a transaction that wraps the count check and the insert (write the file first, delete it on rollback). In `update`, wrap `resolveVisit` and the UPDATE in one transaction and catch `ForeignKeyViolationException` as the `visit_id` error.

### WR-11: Reminder mail subject and lock-screen push title carry the person's name plus "Vaccination due"

**File:** `app/Medical/Reminders/VaccinationDueNotification.php:28-31`, `app/Medical/Reminders/VaccinationDueHandler.php:63-69`, `lang/en/notifications.php:19`
**Issue:** The subject line and the push title are `Vaccination due: <person name>`. This is an accepted owner decision, but it puts a health fact about a named person into mail subjects (stored in every mailbox and provider, outside the family's control) and onto lock screens of shared devices. Web Push payloads are encrypted in transit, so the exposure is the mailbox and the lock screen.
**Fix:** Use a neutral subject and title ("A reminder is waiting in Family Hub"), and keep the name and detail inside the authenticated page. If the name stays, show only a first name or initials and document the residual risk next to the owner decision.

### WR-12: Document processing shares the wishlist pixel cap and discards the original image

**File:** `app/Medical/Documents/MedicalDocumentStore.php:50-60`, `app/Wishlist/Catalog/ImageProcessor.php:44`, `config/wishlist.php:14`
**Issue:** The medical pipeline depends on `wishlist.image_max_pixels`. A change made for wish images silently changes the medical limit. Images are re-encoded to JPEG q82, downscaled to 2400 px and flattened onto white, and the original is discarded. For scanned prescriptions and lab results, small print can be lost permanently. PDFs and HEIC are stored untouched, so the behaviour is inconsistent.
**Fix:** Add `medical.image_max_pixels` and pass it into `ImageProcessor::process` as a parameter. Raise the full-size edge to 3000 px and the quality to 90 for documents, or keep the sanitized original for documents (it already passes the sniffer).

## Info

### IN-01: HEIC and PDF originals are stored unchanged, including GPS and author metadata

**File:** `app/Medical/Documents/MedicalDocumentStore.php:63-66`
**Issue:** The metadata-free guarantee holds only for images (re-encoded). A HEIC photo of a document keeps EXIF GPS. This is visible only to members who can open the file, but the docblock and UI wording imply a general strip.
**Fix:** Say so in the upload UI help text, or convert HEIC to JPEG server-side when imagick is available.

### IN-02: Hard-deleted documents and people persist in backups until retention expires

**File:** `scripts/backup.sh:76-103`
**Issue:** `medical.tar` and the DB dump keep deleted data for the retention window, and the off-site copy keeps it too. This is expected, but "hard delete" in the UI copy reads as an erasure guarantee.
**Fix:** Document the retention window in the delete confirmation or in `docs/ops/server.md`.

### IN-03: `SubjectQuery::delete` runs the file removal in `afterCommit` and can still turn into a 500

**File:** `app/Medical/Queries/SubjectQuery.php:203-204`
**Issue:** If `deleteSubjectDirectory` throws after commit, the user sees an error although the rows are gone, and may retry into a 403. The orphan sweep cleans up, so there is no data issue.
**Fix:** Wrap the afterCommit call in try/catch and `report()` without content.

### IN-04: Duplicated blob-download code and unscoped `Purpose`/`X-Fh-Passive` headers

**File:** `resources/js/Health/DocumentPanel.vue:115-123`, `resources/js/Pages/Health/Export.vue:48-58`, `app/Security/SensitiveGate.php:75-78`
**Issue:** The save-blob logic is duplicated (see WR-04). A client-supplied `X-Fh-Passive: 1` or `Purpose: prefetch` header on a non-GET request skips the idle `touch` while still being served when fresh. This is harmless for access, but it lets a script keep reading without extending the idle window and makes idle accounting inconsistent.
**Fix:** One `saveBlob` helper. Honour the passive headers only on GET and HEAD.

### IN-05: Document ETag is a prefix of the content hash

**File:** `app/Http/Controllers/Health/DocumentController.php:86`
**Issue:** `ETag: "<first 32 hex of sha256><variant>"` exposes half of the stored image hash to anyone who can already view it. It cannot be used to access anything, but a salted or opaque value is cheap.
**Fix:** `hash_hmac('sha256', $row->sha256.$variant, config('app.key'))` truncated to 32 characters (moot if WR-05 is adopted).

---

_Reviewed: 2026-11-14T00:00:00Z_
_Reviewer: Claude (gsd-code-reviewer)_
_Depth: deep_
