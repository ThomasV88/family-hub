<?php

namespace App\Medical\Queries;

use App\Access\AccessLevel;
use App\Access\Module;
use App\Audit\AuditEvent;
use App\Audit\AuditLogger;
use App\Medical\ArchiveState;
use App\Medical\Retention;
use App\Medical\Viewer;
use App\Medical\Visibility;
use App\Models\MedicalAllergy;
use App\Models\MedicalDocument;
use App\Models\MedicalMedication;
use App\Models\MedicalSubject;
use App\Models\MedicalVaccination;
use App\Models\MedicalVisit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Removing, archiving and restoring the records of one person (visits, medications, allergies, vaccinations,
 * documents). The delete window is decided here by the server (see Retention): a record created within it is deleted for
 * good, an older one is archived. Every statement is constrained to the person and to the viewer's Edit scope through
 * Visibility, so a hidden person, a record of another person, a malformed id and an unknown id all match zero rows and
 * the caller answers the one identical 403. Nothing is ever permanently deleted once the window has passed.
 */
class ArchiveQuery
{
    /** type => [table, id column]. Vaccinations are addressed by their public uuid. */
    private const TYPES = [
        'visit' => ['medical_visits', 'id'],
        'medication' => ['medical_medications', 'id'],
        'allergy' => ['medical_allergies', 'id'],
        'vaccination' => ['medical_vaccinations', 'public_id'],
        'document' => ['medical_documents', 'id'],
    ];

    public function __construct(private readonly AuditLogger $audit) {}

    /** @return list<string> */
    public static function types(): array
    {
        return array_keys(self::TYPES);
    }

    /**
     * Deletes or archives one record. Null when no row matched (hidden, unknown, malformed, view-only or already
     * archived). For a permanently deleted document `paths` lists the stored files to remove after the commit.
     *
     * @return array{outcome: 'deleted'|'archived', paths: list<string>}|null
     */
    public function remove(Viewer $viewer, MedicalSubject $subject, string $type, string $id): ?array
    {
        if (! isset(self::TYPES[$type]) || ! Str::isUuid($id) || ! $viewer->canEdit()) {
            return null;
        }
        [$table, $key] = self::TYPES[$type];
        $subjectId = (string) $subject->getKey();

        return DB::transaction(function () use ($viewer, $type, $id, $table, $key, $subjectId): ?array {
            $scoped = fn () => Visibility::apply(
                DB::table($table)->where($key, $id)->where('subject_id', $subjectId),
                $viewer,
                'subject_id',
                AccessLevel::Edit,
            );
            $row = $scoped()->lockForUpdate()->first($type === 'document' ? ['created_at', 'path', 'thumb_path'] : ['created_at']);
            if ($row === null) {
                return null;
            }

            if (! Retention::canDeletePermanently($row->created_at)) {
                $changed = $scoped()->update(['archived_at' => now(), 'archived_by' => $viewer->userId(), 'updated_at' => now()]);
                if ($changed === 0) {
                    return null;
                }
                $this->audit->record(AuditEvent::MedicalArchived, $viewer->user, ['subject' => $subjectId, 'type' => $type, 'record' => $id], null, Module::Health);

                return ['outcome' => 'archived', 'paths' => []];
            }

            if ($scoped()->delete() === 0) {
                return null;
            }

            return [
                'outcome' => 'deleted',
                'paths' => $type === 'document' ? array_values(array_filter([$row->path, $row->thumb_path])) : [],
            ];
        });
    }

    /** Restores an archived record of an active person. False when nothing matched. */
    public function restore(Viewer $viewer, MedicalSubject $subject, string $type, string $id): bool
    {
        if (! isset(self::TYPES[$type]) || ! Str::isUuid($id) || ! $viewer->canEdit()) {
            return false;
        }
        [$table, $key] = self::TYPES[$type];
        $subjectId = (string) $subject->getKey();

        return DB::transaction(function () use ($viewer, $type, $id, $table, $key, $subjectId): bool {
            $changed = Visibility::apply(
                DB::table($table)->where($key, $id)->where('subject_id', $subjectId),
                $viewer,
                'subject_id',
                AccessLevel::Edit,
                ArchiveState::Only,
            )->update(['archived_at' => null, 'archived_by' => null, 'updated_at' => now()]);
            if ($changed === 0) {
                return false;
            }
            $this->audit->record(AuditEvent::MedicalRestored, $viewer->user, ['subject' => $subjectId, 'type' => $type, 'record' => $id], null, Module::Health);

            return true;
        });
    }

    /**
     * The archived records of one person, newest archive first, for members with Edit on the person. Empty for anyone
     * else. Only what the Archive section shows: a label, the record's own date and the archive date.
     *
     * @return list<array{type: string, id: string, label: string, date: ?string, archivedOn: string}>
     */
    public function forSubject(Viewer $viewer, MedicalSubject $subject): array
    {
        if (! $viewer->canEdit()) {
            return [];
        }
        $timezone = (string) config('family.timezone');
        $out = [];
        $add = function (string $type, $rows, callable $label, callable $date, callable $id) use (&$out, $timezone): void {
            foreach ($rows as $row) {
                $out[] = [
                    'type' => $type,
                    'id' => (string) $id($row),
                    'label' => (string) $label($row),
                    'date' => $date($row),
                    'archivedOn' => $row->archived_at->copy()->setTimezone($timezone)->format('Y-m-d'),
                    '_at' => $row->archived_at->getTimestamp(),
                ];
            }
        };
        $scoped = fn (string $model) => Visibility::apply($model::query()->where('subject_id', $subject->getKey()), $viewer, 'subject_id', AccessLevel::Edit, ArchiveState::Only)
            ->orderByDesc('archived_at')->orderBy('id')->get();

        $add('visit', $scoped(MedicalVisit::class), fn (MedicalVisit $v) => trim(($v->doctor ?? '').' '.($v->diagnosis ?? '')), fn (MedicalVisit $v) => $v->visited_on?->format('Y-m-d'), fn (MedicalVisit $v) => $v->getKey());
        $add('medication', $scoped(MedicalMedication::class), fn (MedicalMedication $m) => $m->name, fn (MedicalMedication $m) => $m->started_on?->format('Y-m-d'), fn (MedicalMedication $m) => $m->getKey());
        $add('allergy', $scoped(MedicalAllergy::class), fn (MedicalAllergy $a) => $a->substance, fn () => null, fn (MedicalAllergy $a) => $a->getKey());
        $add('vaccination', $scoped(MedicalVaccination::class), fn (MedicalVaccination $v) => $v->vaccine, fn (MedicalVaccination $v) => $v->given_on?->format('Y-m-d'), fn (MedicalVaccination $v) => $v->public_id);
        $add('document', $scoped(MedicalDocument::class), fn (MedicalDocument $d) => $d->title, fn () => null, fn (MedicalDocument $d) => $d->getKey());

        usort($out, fn (array $a, array $b) => [$b['_at'], $a['type'], $a['id']] <=> [$a['_at'], $b['type'], $b['id']]);

        return array_map(function (array $row): array {
            unset($row['_at']);

            return $row;
        }, $out);
    }
}
