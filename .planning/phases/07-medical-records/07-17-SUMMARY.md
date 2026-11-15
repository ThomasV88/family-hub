---
phase: 07-medical-records
plan: 17
subsystem: health
tags: [medical, person-management, hard-delete, emergency-contacts]
requires: ["07-09", "07-14", "07-16"]
provides:
  - "medical_contacts table, MedicalContact model/factory, Queries\\ContactQuery (forSubject, replaceAll)"
  - "SubjectQuery::update and ::delete (hard delete, audited, files removed after commit)"
  - "PATCH health.people.update, DELETE health.people.destroy; UpdatePersonRequest (authorize() first)"
  - "PersonPage person.contacts and panel kind edit-person"
  - "PersonHeader Export records link and More actions menu; PersonPanel edit mode with up to 3 contacts"
affects: [07-18]
key-files:
  created:
    - database/migrations/2026_11_18_160000_create_medical_contacts_table.php
    - app/Models/MedicalContact.php
    - database/factories/MedicalContactFactory.php
    - app/Medical/Queries/ContactQuery.php
    - app/Http/Requests/Health/UpdatePersonRequest.php
    - tests/Feature/Health/PersonManageTest.php
    - tests/js/health/PersonManage.test.js
  modified:
    - app/Medical/Queries/SubjectQuery.php
    - app/Medical/PersonPage.php
    - app/Http/Controllers/Health/PersonController.php
    - app/Http/Requests/Health/StorePersonRequest.php
    - routes/web/health.php
    - tests/Support/EndpointMatrix/health.php
    - tests/Support/MedicalPrivacy/subjects.php
    - tests/Feature/Health/VisibilityTest.php
    - resources/js/Health/PersonPanel.vue
    - resources/js/Health/PersonHeader.vue
    - resources/js/Pages/Health/Person.vue
    - lang/en.json
    - lang/sk.json
    - lang/pl.json
key-decisions:
  - "PROVISIONAL: deleting a person is a HARD delete at Edit level (per plan). The whole decision lives in SubjectQuery::delete (docblock marks it); to change it, swap the scoped DELETE for a deleted_at update and the afterCommit file removal for a purge job, or raise the level in the route middleware plus the findVisible level in PersonController::destroy. The owner may prefer a trash or a stricter level."
  - "A missing `contacts` key on update leaves contacts untouched; null or [] clears them"
  - "The foreign-key cascade removes children; files are removed by DB::afterCommit (a failing audit rolls back and keeps them); the daily orphan sweep (07-12) is the backstop"
metrics:
  tasks: 2
  completed: 2026-11-14
---

# Phase 7 Plan 17: Person management Summary

Editors can edit a person (name, birth date, sex, blood type, notes) with up to 3 emergency contacts, and hard-delete a person with every record and file. Create and update share one rule set; update runs authorize() first so an invalid body sent to a hidden, own_off, random or malformed id gets the same 403 as a valid one (compared with the shared `MedicalPrivacy::normalise`, no session errors).

## Commits

- feat(07-17): contacts schema, person update and hard delete (backend, matrix, privacy area, tests)
- feat(07-17): person edit panel with contacts, header actions and delete confirm

## What was built

- `medical_contacts`: uuid, subject FK cascade, name 120, relation 60 null, phone 40, sort smallint with UNIQUE (subject_id, sort) and CHECK 0..2.
- `ContactQuery::replaceAll`: Edit-scoped DELETE then INSERTs with sort 0..n in one transaction; false when not editable or over the cap.
- `SubjectQuery::update`: one Edit-scoped UPDATE plus contacts in the same transaction. `delete`: Edit-scoped DELETE plus `medical_subject_deleted` audit (meta `{subject}` only) in one transaction, `DB::afterCommit` then `MedicalDocumentStore::deleteSubjectDirectory`. `create` stores contacts in its transaction.
- Phone rule `^[0-9+()./\s-]{3,40}$`; names use ValidUtf8; 4th contact answers `health.person.err_contacts_max`.
- Header: for editors Add visit, Add document, Export records (link to `/health/people/{id}/export`) and a UiMenu "More actions for this person" (Edit person, Delete person). The delete confirm focuses "Keep person" first. View-only members see the existing view-only line.
- Panel: edit mode prefilled, contacts section (Name, Relationship, Phone `type=tel autocomplete=off`, Add contact hidden at 3, Remove contact), same section in create mode, no linked-account field; discard confirm kept.
- Matrix rows for update and destroy; subjects area file gives hidden B and own_off canary contacts (`ZxCanaryMed`, phone `+421 777 777`) and an update payload with a contact.

## Verification

- Full Pest: 1954 passed. Vitest: 118 files, 2809 passed (health: 359 incl. axe in en/sk/pl, light/dark, for header, menu open, delete confirm, panel with 0 and 3 contacts). `npm run build` SW-PRECACHE OK. Pint clean on own PHP files. Compose project fh0717 removed with `down -v`.
- Mutation scan, write parity and SensitiveGateMatrix pick up the new routes and pass.

## Deviations from Plan

1. **[Rule 2] New lang key `health.person.err_phone`** (en, sk, pl) so a bad phone shows a specific message instead of the generic load error; the plan listed only the existing keys.
2. **[Rule 3] SubjectQuery constructor** now takes ContactQuery and MedicalDocumentStore; `VisibilityTest` built it with `new SubjectQuery(app(AuditLogger::class))`, switched to `app(SubjectQuery::class)`.
3. Test fixture note: the document factory's random path is not under the subject directory, so delete tests set `path` to `{subject}/X.pdf` explicitly.
4. Reminders: no handler change was needed; `VaccinationDueHandler::stillRelevant` already returns false when the vaccination is gone, and a test now proves it after a person delete.
5. The More actions trigger shows the full aria-label text as its visible label because UiMenu uses one prop for both.

## Known Stubs

None.

## Threat Flags

None beyond the plan's register (T-07-84b, 85, 86, 87, 88 covered by tests; 89 accepted).

## Self-Check: PASSED
