<?php

namespace App\Medical;

use App\Access\AccessLevel;
use App\Medical\Queries\SubjectQuery;
use App\Models\MedicalSubject;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Props of the Health/Person page. Later slices add keys, never rename. Nothing here depends on client state: the
 * server picks the person, and a panel id that does not resolve inside the visible person aborts 403 exactly like a
 * hidden person.
 */
class PersonPage
{
    /** Panel kinds that exist so far. */
    private const PANELS = ['new-person'];

    public function __construct(private readonly SubjectQuery $subjects) {}

    public function render(Viewer $viewer, ?MedicalSubject $subject, Request $request): Response
    {
        return Inertia::render('Health/Person', $this->props($viewer, $subject, $request));
    }

    /** @return array<string, mixed> */
    public function props(Viewer $viewer, ?MedicalSubject $subject, Request $request): array
    {
        $people = $this->subjects->visible($viewer);

        return [
            'people' => $people->map(fn (MedicalSubject $p) => [
                'id' => (string) $p->getKey(),
                'name' => $p->name,
                'initials' => $this->initials($p->name),
            ])->values()->all(),
            'person' => $subject === null ? null : $this->person($viewer, $subject),
            'canCreate' => $viewer->canEdit(),
            'panel' => $this->panel($viewer, $request),
        ];
    }

    /** @return array<string, mixed> */
    private function person(Viewer $viewer, MedicalSubject $subject): array
    {
        $timezone = (string) config('family.timezone');

        return [
            'id' => (string) $subject->getKey(),
            'name' => $subject->name,
            'birthDate' => $subject->birth_date?->format('Y-m-d'),
            'ageYears' => $subject->birth_date === null ? null : $this->age($subject->birth_date->format('Y-m-d'), $timezone),
            'sex' => $subject->sex,
            'bloodType' => $subject->blood_type,
            'hasAccount' => $subject->user_id !== null,
            'aiConsent' => (bool) $subject->ai_consent,
            'canEdit' => $this->subjects->levelFor($viewer, $subject) === AccessLevel::Edit,
            'notes' => $subject->notes,
        ];
    }

    /** @return array{kind: string, id: ?string}|null */
    private function panel(Viewer $viewer, Request $request): ?array
    {
        $kind = $request->query('panel');
        if (! is_string($kind) || ! in_array($kind, self::PANELS, true)) {
            return null;
        }

        // No panel kind resolves a record yet, so any id is a miss: same 403 as a hidden person.
        if ($request->query->has('id')) {
            abort(403);
        }

        if ($kind === 'new-person' && ! $viewer->canEdit()) {
            return null;
        }

        return ['kind' => $kind, 'id' => null];
    }

    /** Whole years in the family's wall-clock zone. */
    private function age(string $birthDate, string $timezone): int
    {
        $born = Carbon::createFromFormat('Y-m-d', $birthDate, $timezone)->startOfDay();

        return max(0, (int) $born->diffInYears(now($timezone)->startOfDay(), true));
    }

    private function initials(string $name): string
    {
        $words = preg_split('/\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $letters = array_map(fn (string $w) => mb_strtoupper(mb_substr($w, 0, 1)), array_slice($words, 0, 2));

        return implode('', $letters);
    }
}
