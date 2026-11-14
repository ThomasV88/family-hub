<?php

namespace App\Medical\Queries;

use App\Access\AccessLevel;
use App\Access\Module;
use App\Audit\AuditEvent;
use App\Audit\AuditLogger;
use App\Models\MedicalSubject;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * UNSCOPED BY DESIGN: admin settings only (Settings > Members). Returns person names, member levels and link flags,
 * never record data or record counts. PROVISIONAL (orchestrator), owner confirms in 07-20: the admin sees person
 * names on the settings screen only. It is the one place outside Visibility that reads medical_subject_members
 * without the viewer predicate, and it never hands an admin a record.
 */
class AdminSubjectQuery
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** @return list<array{id: string, name: string, hasAccount: bool, linkedUserId: ?int, selfAccessEnabled: bool, members: array<int, string>}> */
    public function listForAdmin(): array
    {
        $members = [];
        foreach (DB::table('medical_subject_members')->orderBy('user_id')->get(['subject_id', 'user_id', 'level']) as $row) {
            $members[(string) $row->subject_id][(int) $row->user_id] = (int) $row->level === AccessLevel::Edit->value ? 'edit' : 'view';
        }

        return DB::table('medical_subjects')
            ->orderByRaw('lower(name)')
            ->orderBy('id')
            ->get(['id', 'name', 'user_id', 'self_access_enabled'])
            ->map(fn ($s) => [
                'id' => (string) $s->id,
                'name' => (string) $s->name,
                'hasAccount' => $s->user_id !== null,
                'linkedUserId' => $s->user_id === null ? null : (int) $s->user_id,
                'selfAccessEnabled' => (bool) $s->self_access_enabled,
                'members' => $members[(string) $s->id] ?? [],
            ])
            ->all();
    }

    /** Null for a non-uuid id without touching the database. */
    public function find(string $id): ?MedicalSubject
    {
        if (! Str::isUuid($id)) {
            return null;
        }

        return MedicalSubject::query()->find($id, ['id', 'name', 'user_id', 'self_access_enabled']);
    }

    /**
     * Applies the given per-member levels (user id => none|view|edit); members not named stay as they are.
     * One audit row per changed member, in the same transaction.
     *
     * @param  array<int|string, string>  $levels
     */
    public function syncMembers(MedicalSubject $subject, array $levels, User $actor): void
    {
        DB::transaction(function () use ($subject, $levels, $actor) {
            $subjectId = (string) $subject->getKey();
            $existing = DB::table('medical_subject_members')->where('subject_id', $subjectId)->lockForUpdate()
                ->pluck('level', 'user_id')->map(fn ($l) => (int) $l)->all();

            foreach ($levels as $userId => $level) {
                $userId = (int) $userId;
                $new = match ($level) {
                    'edit' => AccessLevel::Edit->value,
                    'view' => AccessLevel::View->value,
                    default => 0,
                };
                $old = $existing[$userId] ?? 0;
                if ($new === $old) {
                    continue;
                }

                if ($new === 0) {
                    DB::table('medical_subject_members')->where('subject_id', $subjectId)->where('user_id', $userId)->delete();
                    $change = 'revoked';
                } else {
                    DB::table('medical_subject_members')->upsert(
                        [['subject_id' => $subjectId, 'user_id' => $userId, 'level' => $new, 'granted_by' => $actor->id, 'created_at' => now()]],
                        ['subject_id', 'user_id'],
                        ['level', 'granted_by'],
                    );
                    $change = $old === 0 ? 'granted' : 'level';
                }

                $target = User::query()->find($userId);
                if ($change === 'granted' && $userId === $actor->id) {
                    $this->audit->record(AuditEvent::MedicalAdminSelfAdded, $actor, ['subject' => $subjectId], $target, Module::Health);
                } else {
                    $this->audit->record(AuditEvent::MedicalAccessChanged, $actor, ['subject' => $subjectId, 'change' => $change], $target, Module::Health);
                }
            }
        });
    }

    /**
     * Links the person to one member account (or none) and sets the own-record switch. Every refusal (unknown id,
     * already linked elsewhere, unusable account) gives the same validation error, so the answer is not an oracle.
     */
    public function link(MedicalSubject $subject, ?int $userId, bool $selfAccess, User $actor): void
    {
        $refuse = fn () => ValidationException::withMessages(['user_id' => 'health.access.link_failed']);

        DB::transaction(function () use ($subject, $userId, $selfAccess, $actor, $refuse) {
            $row = DB::table('medical_subjects')->where('id', $subject->getKey())->lockForUpdate()->first(['user_id', 'self_access_enabled']);
            if ($row === null) {
                throw $refuse();
            }
            $current = $row->user_id === null ? null : (int) $row->user_id;
            $subjectId = (string) $subject->getKey();

            if ($userId !== $current) {
                if ($userId !== null) {
                    $taken = DB::table('medical_subjects')->where('user_id', $userId)->where('id', '!=', $subjectId)->exists();
                    if ($taken || User::query()->whereKey($userId)->doesntExist()) {
                        throw $refuse();
                    }
                }
                try {
                    DB::transaction(fn () => DB::table('medical_subjects')->where('id', $subjectId)->update(['user_id' => $userId, 'updated_at' => now()]));
                } catch (UniqueConstraintViolationException) {
                    throw $refuse();
                }
                $this->audit->record(
                    AuditEvent::MedicalAccessChanged, $actor,
                    ['subject' => $subjectId, 'change' => $userId === null ? 'unlinked' : 'linked'],
                    User::query()->find($userId ?? $current), Module::Health,
                );
            }

            $effective = $userId !== null ? $selfAccess : (bool) $row->self_access_enabled;
            if ($effective !== (bool) $row->self_access_enabled) {
                DB::table('medical_subjects')->where('id', $subjectId)->update(['self_access_enabled' => $effective, 'updated_at' => now()]);
                $this->audit->record(
                    AuditEvent::MedicalAccessChanged, $actor,
                    ['subject' => $subjectId, 'change' => $effective ? 'self_access_on' : 'self_access_off'],
                    $userId !== null ? User::query()->find($userId) : null, Module::Health,
                );
            }
        });
    }
}
