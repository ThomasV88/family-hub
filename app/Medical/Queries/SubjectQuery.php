<?php

namespace App\Medical\Queries;

use App\Access\AccessLevel;
use App\Access\Module;
use App\Audit\AuditEvent;
use App\Audit\AuditLogger;
use App\Medical\Documents\MedicalDocumentStore;
use App\Medical\Viewer;
use App\Medical\Visibility;
use App\Models\MedicalSubject;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SubjectQuery
{
    public function __construct(private readonly AuditLogger $audit, private readonly ContactQuery $contacts, private readonly MedicalDocumentStore $store) {}

    /** @return Collection<int, MedicalSubject> */
    public function visible(Viewer $viewer): Collection
    {
        return Visibility::apply(MedicalSubject::query(), $viewer, 'id')
            ->orderByRaw('lower(name)')
            ->orderBy('id')
            ->get();
    }

    /**
     * A non-uuid id, an id outside the scope and an unknown id all give null; a malformed id and an Edit lookup by a
     * module-view viewer never reach the database.
     */
    public function findVisible(Viewer $viewer, string $id, AccessLevel $min = AccessLevel::View): ?MedicalSubject
    {
        if (! Str::isUuid($id)) {
            return null;
        }
        if ($min === AccessLevel::Edit && ! $viewer->canEdit()) {
            return null;
        }

        return Visibility::apply(MedicalSubject::query()->where('id', $id), $viewer, 'id', $min)->first();
    }

    /** min(module level, the viewer's level on this subject); None when the subject is outside the scope. */
    public function levelFor(Viewer $viewer, MedicalSubject $subject): AccessLevel
    {
        $level = (int) DB::table('medical_subject_members')
            ->where('subject_id', $subject->getKey())
            ->where('user_id', $viewer->userId())
            ->value('level');
        if ($subject->user_id !== null && (int) $subject->user_id === $viewer->userId() && $subject->self_access_enabled) {
            $level = max($level, AccessLevel::Edit->value);
        }

        return AccessLevel::from(min($level, $viewer->level->value));
    }

    /**
     * Creates the person and makes the creator its "Can change" member, with the audit row, in one transaction.
     *
     * @param  array{name: string, birth_date?: ?string, sex?: ?string, blood_type?: ?string, notes?: ?string, contacts?: ?array}  $data
     */
    public function create(Viewer $viewer, array $data): MedicalSubject
    {
        abort_unless($viewer->canEdit(), 403);

        return DB::transaction(function () use ($viewer, $data) {
            $subject = new MedicalSubject;
            $subject->forceFill([
                'name' => $data['name'],
                'birth_date' => $data['birth_date'] ?? null,
                'sex' => $data['sex'] ?? 'unspecified',
                'blood_type' => $data['blood_type'] ?? null,
                'notes' => $data['notes'] ?? null,
                'created_by' => $viewer->userId(),
            ])->save();

            DB::table('medical_subject_members')->insert([
                'subject_id' => $subject->getKey(),
                'user_id' => $viewer->userId(),
                'level' => AccessLevel::Edit->value,
                'granted_by' => $viewer->userId(),
                'created_at' => now(),
            ]);

            if (! empty($data['contacts'])) {
                $this->contacts->replaceAll($viewer, $subject, $data['contacts']);
            }

            $this->audit->record(AuditEvent::MedicalSubjectCreated, $viewer->user, ['subject' => (string) $subject->getKey()], null, Module::Health);

            return $subject;
        });
    }

    /**
     * Sets the person's AI-consent flag. One transaction: the Edit-scoped UPDATE and the audit row stand or fall together.
     * Returns false when the subject is outside the viewer's Edit scope (the controller answers 403). Setting the stored
     * value again changes nothing and writes no audit row.
     */
    public function setConsent(Viewer $viewer, MedicalSubject $subject, bool $value): bool
    {
        if (! $viewer->canEdit()) {
            return false;
        }

        return DB::transaction(function () use ($viewer, $subject, $value) {
            $id = (string) $subject->getKey();
            $current = DB::table('medical_subjects')
                ->where('id', $id)
                ->whereIn('id', Visibility::subjectIds($viewer, AccessLevel::Edit))
                ->lockForUpdate()
                ->first(['ai_consent']);
            if ($current === null) {
                return false;
            }
            if ((bool) $current->ai_consent === $value) {
                return true;
            }

            $changed = DB::table('medical_subjects')
                ->where('id', $id)
                ->whereIn('id', Visibility::subjectIds($viewer, AccessLevel::Edit))
                ->update([
                    'ai_consent' => $value,
                    'ai_consent_changed_at' => now(),
                    'ai_consent_changed_by' => $viewer->userId(),
                ]);
            if ($changed === 0) {
                return false;
            }

            $this->audit->record(AuditEvent::MedicalConsentChanged, $viewer->user, ['subject' => $id, 'value' => $value ? 'on' : 'off'], null, Module::Health);

            return true;
        });
    }

    /**
     * Updates the profile with one Edit-scoped statement; when `contacts` is present they are replaced in the same
     * transaction (null or [] clears them, a missing key leaves them). False when the subject is outside the viewer's
     * Edit scope (the controller answers 403).
     *
     * @param  array{name: string, birth_date?: ?string, sex?: ?string, blood_type?: ?string, notes?: ?string, contacts?: ?array}  $data
     */
    public function update(Viewer $viewer, MedicalSubject $subject, array $data): bool
    {
        if (! $viewer->canEdit()) {
            return false;
        }

        return DB::transaction(function () use ($viewer, $subject, $data) {
            $changed = Visibility::apply(
                DB::table('medical_subjects')->where('id', $subject->getKey()),
                $viewer,
                'id',
                AccessLevel::Edit,
            )->update([
                'name' => $data['name'],
                'birth_date' => $data['birth_date'] ?? null,
                'sex' => $data['sex'] ?? 'unspecified',
                'blood_type' => $data['blood_type'] ?? null,
                'notes' => $data['notes'] ?? null,
                'updated_at' => now(),
            ]);
            if ($changed === 0) {
                return false;
            }
            if (array_key_exists('contacts', $data) && ! $this->contacts->replaceAll($viewer, $subject, $data['contacts'] ?? [])) {
                throw new \RuntimeException('contacts not replaced');
            }

            return true;
        });
    }

    /**
     * HARD delete (provisional product decision, see 07-17 SUMMARY: swap this method for a soft delete or raise the
     * level here). One transaction: the Edit-scoped DELETE of the subject (foreign keys cascade to members, visits,
     * medications, allergies, vaccinations, contacts and documents) and the content-free audit row. The files go only
     * after commit, so a failing audit rolls everything back and keeps them.
     */
    public function delete(Viewer $viewer, MedicalSubject $subject): bool
    {
        if (! $viewer->canEdit()) {
            return false;
        }
        $id = (string) $subject->getKey();

        return DB::transaction(function () use ($viewer, $id) {
            $deleted = Visibility::apply(
                DB::table('medical_subjects')->where('id', $id),
                $viewer,
                'id',
                AccessLevel::Edit,
            )->delete();
            if ($deleted === 0) {
                return false;
            }

            $this->audit->record(AuditEvent::MedicalSubjectDeleted, $viewer->user, ['subject' => $id], null, Module::Health);
            DB::afterCommit(fn () => $this->store->deleteSubjectDirectory($id));

            return true;
        });
    }
}
