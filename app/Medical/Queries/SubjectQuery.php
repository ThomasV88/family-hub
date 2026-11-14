<?php

namespace App\Medical\Queries;

use App\Access\AccessLevel;
use App\Access\Module;
use App\Audit\AuditEvent;
use App\Audit\AuditLogger;
use App\Medical\Viewer;
use App\Medical\Visibility;
use App\Models\MedicalSubject;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SubjectQuery
{
    public function __construct(private readonly AuditLogger $audit) {}

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
     * @param  array{name: string, birth_date?: ?string, sex?: ?string, blood_type?: ?string, notes?: ?string}  $data
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

            $this->audit->record(AuditEvent::MedicalSubjectCreated, $viewer->user, ['subject' => (string) $subject->getKey()], null, Module::Health);

            return $subject;
        });
    }
}
