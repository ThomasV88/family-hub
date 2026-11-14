<?php

namespace App\Medical;

use App\Access\AccessLevel;
use App\Medical\Queries\AllergyQuery;
use App\Medical\Queries\DocumentQuery;
use App\Medical\Queries\MedicationQuery;
use App\Medical\Queries\SubjectQuery;
use App\Medical\Queries\VaccinationQuery;
use App\Medical\Queries\VisitQuery;
use App\Models\MedicalAllergy;
use App\Models\MedicalDocument;
use App\Models\MedicalMedication;
use App\Models\MedicalSubject;
use App\Models\MedicalVaccination;
use App\Models\MedicalVisit;
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
    /** Panel kinds that exist so far; those listed in WITH_ID resolve a record id inside the visible person. */
    private const PANELS = ['new-person', 'new-visit', 'visit', 'new-allergy', 'allergy', 'new-medication', 'medication', 'new-vaccination', 'vaccination', 'new-document', 'document'];

    private const WITH_ID = ['visit', 'allergy', 'medication', 'vaccination', 'document'];

    public function __construct(private readonly SubjectQuery $subjects, private readonly VisitQuery $visits, private readonly AllergyQuery $allergies, private readonly MedicationQuery $medications, private readonly VaccinationQuery $vaccinations, private readonly DocumentQuery $documents) {}

    public function render(Viewer $viewer, ?MedicalSubject $subject, Request $request): Response
    {
        return Inertia::render('Health/Person', $this->props($viewer, $subject, $request));
    }

    /** @return array<string, mixed> */
    public function props(Viewer $viewer, ?MedicalSubject $subject, Request $request): array
    {
        $people = $this->subjects->visible($viewer);

        $documents = $this->documentProps($viewer, $subject);

        return $this->visitProps($viewer, $subject, $request, $documents) + [
            'documents' => $documents,
            'unattachedDocumentIds' => array_values(array_column(array_filter($documents, fn (array $d) => $d['visitId'] === null), 'id')),
            'limits' => ['maxMb' => intdiv((int) config('medical.document_max_bytes'), 1048576), 'filesAtOnce' => (int) config('medical.upload_batch_max')],
            'allergies' => $this->allergyProps($viewer, $subject),
            'medications' => $this->medicationProps($viewer, $subject),
            'vaccinations' => $this->vaccinationProps($viewer, $subject),
            'vaccinationAttention' => $this->vaccinationAttention($viewer, $subject),
            'people' => $people->map(fn (MedicalSubject $p) => [
                'id' => (string) $p->getKey(),
                'name' => $p->name,
                'initials' => $this->initials($p->name),
            ])->values()->all(),
            'person' => $subject === null ? null : $this->person($viewer, $subject),
            'canCreate' => $viewer->canEdit(),
            'panel' => $this->panel($viewer, $subject, $request),
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

    /**
     * visits, folders, timeline, timelineFilters, view, filter, specialties. Everything comes from the visible
     * person's scoped list, so a count can never include a record the viewer may not see.
     *
     * @param  list<array<string, mixed>>  $documentList  the person's scoped documents
     * @return array<string, mixed>
     */
    private function visitProps(Viewer $viewer, ?MedicalSubject $subject, Request $request, array $documentList): array
    {
        $list = $subject === null ? collect() : $this->visits->forSubject($viewer, $subject);
        $documents = collect($documentList);
        $perVisit = $documents->whereNotNull('visitId')->countBy('visitId');
        $visits = $list->map(fn (MedicalVisit $v) => [
            'id' => (string) $v->getKey(),
            'visitedOn' => $v->visited_on->format('Y-m-d'),
            'doctor' => $v->doctor,
            'specialtyKey' => $v->specialty_key,
            'specialtyOther' => $v->specialty_other,
            'diagnosis' => $v->diagnosis,
            'notes' => $v->notes,
            'documentCount' => (int) ($perVisit[(string) $v->getKey()] ?? 0),
        ])->values();

        $folders = $visits->groupBy('specialtyKey')->map(fn ($group, $key) => [
            'key' => (string) $key,
            'otherLabel' => $key === 'other' ? $group->first()['specialtyOther'] : null,
            'count' => $group->count(),
            'latestOn' => $group->max('visitedOn'),
            'visitIds' => $group->pluck('id')->values()->all(),
        ])->sort(fn (array $a, array $b) => [$b['latestOn'], $a['key']] <=> [$a['latestOn'], $b['key']])->values();

        $vaccinations = $subject === null ? collect() : $this->vaccinationProps($viewer, $subject);
        $filterKeys = $visits->pluck('specialtyKey')->unique()->values()->all();
        if (count($vaccinations) > 0) {
            $filterKeys[] = 'vaccinations';
        }
        $unattached = $documents->whereNull('visitId');
        if ($unattached->isNotEmpty()) {
            $filterKeys[] = 'documents';
        }
        $requested = $request->query('filter');
        $filter = is_string($requested) && in_array($requested, $filterKeys, true) ? $requested : 'all';

        $visitEntries = in_array($filter, ['vaccinations', 'documents'], true) ? collect() : $visits
            ->when($filter !== 'all', fn ($c) => $c->where('specialtyKey', $filter))
            ->map(fn (array $v) => ['type' => 'visit', 'id' => $v['id'], 'date' => $v['visitedOn']]);
        $documentEntries = in_array($filter, ['all', 'documents'], true)
            ? $unattached->map(fn (array $d) => ['type' => 'document', 'id' => $d['id'], 'date' => $d['uploadedOn']])
            : collect();
        $vaccinationEntries = in_array($filter, ['all', 'vaccinations'], true)
            ? collect($vaccinations)->map(fn (array $v) => ['type' => 'vaccination', 'id' => $v['id'], 'date' => $v['givenOn']])
            : collect();
        $timeline = $visitEntries->concat($vaccinationEntries)->concat($documentEntries)->sortByDesc('date')->values();

        return [
            'visits' => $visits->all(),
            'folders' => $folders->all(),
            'timeline' => $timeline->all(),
            'timelineFilters' => $filterKeys,
            'view' => $request->query('view') === 'timeline' ? 'timeline' : 'folders',
            'filter' => $filter,
            'specialties' => array_map(fn (Specialty $s) => $s->value, Specialty::cases()),
        ];
    }

    /**
     * Never the path, the thumb path or the hash; only what the screen shows. 
     *
     * @return list<array{id: string, kind: string, title: string, visitId: ?string, uploadedOn: string, sizeBytes: int}>
     */
    private function documentProps(Viewer $viewer, ?MedicalSubject $subject): array
    {
        if ($subject === null) {
            return [];
        }
        $timezone = (string) config('family.timezone');

        return $this->documents->forSubject($viewer, $subject)->map(fn (MedicalDocument $d) => [
            'id' => (string) $d->getKey(),
            'kind' => (string) $d->kind,
            'title' => (string) $d->title,
            'visitId' => $d->visit_id === null ? null : (string) $d->visit_id,
            'uploadedOn' => $d->created_at->copy()->setTimezone($timezone)->format('Y-m-d'),
            'sizeBytes' => (int) $d->size_bytes,
        ])->values()->all();
    }

    /** @return list<array{id: string, substance: string, reaction: ?string, severity: string}> severe first */
    private function allergyProps(Viewer $viewer, ?MedicalSubject $subject): array
    {
        if ($subject === null) {
            return [];
        }

        return $this->allergies->forSubject($viewer, $subject)->map(fn (MedicalAllergy $a) => [
            'id' => (string) $a->getKey(),
            'substance' => $a->substance,
            'reaction' => $a->reaction,
            'severity' => $a->severity,
        ])->values()->all();
    }

    /**
     * Ongoing is decided here with the family-timezone date, so the client never compares dates.
     *
     * @return list<array{id: string, name: string, dose: ?string, schedule: ?string, startedOn: ?string, endedOn: ?string, notes: ?string, ongoing: bool}>
     */
    private function medicationProps(Viewer $viewer, ?MedicalSubject $subject): array
    {
        if ($subject === null) {
            return [];
        }
        $today = now((string) config('family.timezone'))->toDateString();

        return $this->medications->forSubject($viewer, $subject, $today)->map(fn (MedicalMedication $m) => [
            'id' => (string) $m->getKey(),
            'name' => $m->name,
            'dose' => $m->dose,
            'schedule' => $m->schedule,
            'startedOn' => $m->started_on?->format('Y-m-d'),
            'endedOn' => $m->ended_on?->format('Y-m-d'),
            'notes' => $m->notes,
            'ongoing' => $m->ended_on === null || $m->ended_on->format('Y-m-d') >= $today,
        ])->values()->all();
    }

    /**
     * Status and day counts are decided here with the family-timezone date; ids are the public uuids.
     *
     * @return list<array{id: string, vaccine: string, givenOn: string, nextDueOn: ?string, notes: ?string, status: string, dueInDays: ?int}>
     */
    private function vaccinationProps(Viewer $viewer, ?MedicalSubject $subject): array
    {
        if ($subject === null) {
            return [];
        }

        return $this->vaccinations->forSubject($viewer, $subject, $this->today())->map(fn (MedicalVaccination $v) => [
            'id' => (string) $v->public_id,
            'vaccine' => $v->vaccine,
            'givenOn' => $v->given_on->format('Y-m-d'),
            'nextDueOn' => $v->next_due_on?->format('Y-m-d'),
            'notes' => $v->notes,
            'status' => (string) $v->getAttribute('status'),
            'dueInDays' => $v->getAttribute('dueInDays'),
        ])->values()->all();
    }

    /** This person only; never summed across people. */
    private function vaccinationAttention(Viewer $viewer, ?MedicalSubject $subject): int
    {
        return $subject === null ? 0 : $this->vaccinations->attentionCount($viewer, $subject, $this->today());
    }

    private function today(): string
    {
        return now((string) config('family.timezone'))->toDateString();
    }

    /** @return array{kind: string, id: ?string}|null */
    private function panel(Viewer $viewer, ?MedicalSubject $subject, Request $request): ?array
    {
        $kind = $request->query('panel');
        if (! is_string($kind) || ! in_array($kind, self::PANELS, true)) {
            return null;
        }

        // A kind without a record takes no id, and a kind with one must resolve it inside this person: any miss is the
        // same 403 as a hidden person.
        $id = $request->query('id');
        if (in_array($kind, self::WITH_ID, true)) {
            $found = $subject !== null && is_string($id) && match ($kind) {
                'visit' => $this->visits->find($viewer, $subject, $id) !== null,
                'medication' => $this->medications->find($viewer, $subject, $id) !== null,
                'vaccination' => $this->vaccinations->find($viewer, $subject, $id) !== null,
                'document' => $this->documents->find($viewer, $subject, $id) !== null,
                default => $this->allergies->find($viewer, $subject, $id) !== null,
            };
            abort_unless($found, 403);

            return ['kind' => $kind, 'id' => $id];
        }
        if ($request->query->has('id')) {
            abort(403);
        }

        if ($kind === 'new-person' && ! $viewer->canEdit()) {
            return null;
        }
        if (in_array($kind, ['new-visit', 'new-allergy', 'new-medication', 'new-vaccination', 'new-document'], true)) {
            abort_if($subject === null || $this->subjects->levelFor($viewer, $subject) !== AccessLevel::Edit, 403);
        }
        if ($kind === 'new-document') {
            // The optional visit must be one of this person's visits; anything else is the same 403.
            $visit = $request->query('visit');
            abort_if($request->query->has('visit') && (! is_string($visit) || $this->visits->find($viewer, $subject, $visit) === null), 403);

            return ['kind' => $kind, 'id' => null, 'visit' => $visit];
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
