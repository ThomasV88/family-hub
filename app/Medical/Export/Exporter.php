<?php

namespace App\Medical\Export;

use App\Medical\Provenance;
use App\Medical\Queries\ExportQuery;
use App\Medical\Specialty;
use App\Medical\Viewer;
use App\Models\MedicalSubject;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Builds the one normalized export model that the print page, the JSON file and the Markdown file all render. Built in
 * memory on request; nothing is written anywhere.
 *
 * Options: scope all|specialty, specialty (key), include_unverified (bool).
 */
class Exporter
{
    public const FORMAT = 'family-hub-medical-export';

    public function __construct(private readonly ExportQuery $query) {}

    /**
     * @param  array{scope?: string, specialty?: ?string, include_unverified?: bool}  $options
     * @return array<string, mixed>
     */
    public function build(Viewer $viewer, MedicalSubject $subject, array $options = []): array
    {
        $specialty = ($options['scope'] ?? 'all') === 'specialty' ? ($options['specialty'] ?? null) : null;
        $include = (bool) ($options['include_unverified'] ?? false);
        $timezone = (string) config('family.timezone');
        $locale = app()->getLocale();
        $unverified = false;

        $item = function (object $row, array $fields) use (&$unverified): array {
            $provenance = Provenance::present($row);
            $unverified = $unverified || $provenance['verification'] === 'unverified';

            return $fields + [
                'verification' => $provenance['verification'],
                'provenance' => ['source' => $provenance['source'], 'verified_at' => $provenance['verified_at']],
            ];
        };

        // Documents first: visits list the ids of their (included) documents.
        $documents = [];
        $byVisit = [];
        foreach ($this->query->documents($viewer, $subject, $include, $specialty) as $doc) {
            $id = (string) $doc->getKey();
            $visitId = $doc->visit_id === null ? null : (string) $doc->visit_id;
            $documents[] = $item($doc, [
                'id' => $id,
                'title' => (string) $doc->title,
                'kind' => (string) $doc->kind,
                'visit_id' => $visitId,
                'uploaded_on' => $doc->created_at?->copy()->setTimezone($timezone)->format('Y-m-d'),
                'file_included' => false,
            ]);
            if ($visitId !== null) {
                $byVisit[$visitId][] = $id;
            }
        }

        $visits = [];
        foreach ($this->query->visits($viewer, $subject, $include, $specialty) as $visit) {
            $key = (string) $visit->specialty_key;
            $case = Specialty::tryFrom($key);
            $other = $visit->specialty_other !== null && $visit->specialty_other !== '' ? (string) $visit->specialty_other : null;
            $visits[] = $item($visit, [
                'id' => (string) $visit->getKey(),
                'visited_on' => $this->date($visit->visited_on, $timezone),
                'doctor' => $visit->doctor,
                'specialty_key' => $key,
                'specialty_label' => ($key === Specialty::Other->value && $other !== null) ? $other : ($case?->label($locale) ?? $key),
                'diagnosis_text' => $visit->diagnosis,
                'notes' => $visit->notes,
                'document_ids' => $byVisit[(string) $visit->getKey()] ?? [],
            ]);
        }

        $medications = [];
        $allergies = [];
        $vaccinations = [];
        if ($specialty === null) {
            foreach ($this->query->medications($viewer, $subject, $include) as $m) {
                $medications[] = $item($m, [
                    'name' => $m->name, 'dose' => $m->dose, 'schedule' => $m->schedule,
                    'started_on' => $this->date($m->started_on, $timezone), 'ended_on' => $this->date($m->ended_on, $timezone),
                    'notes' => $m->notes,
                ]);
            }
            foreach ($this->query->allergies($viewer, $subject, $include) as $a) {
                $allergies[] = $item($a, ['substance' => $a->substance, 'reaction' => $a->reaction, 'severity' => $a->severity]);
            }
            foreach ($this->query->vaccinations($viewer, $subject, $include) as $v) {
                $vaccinations[] = $item($v, [
                    'id' => (string) $v->public_id, 'vaccine' => $v->vaccine,
                    'given_on' => $this->date($v->given_on, $timezone), 'next_due_on' => $this->date($v->next_due_on, $timezone),
                    'notes' => $v->notes,
                ]);
            }
        }

        $birth = $subject->birth_date?->format('Y-m-d');

        return [
            'format' => self::FORMAT,
            'format_version' => 1,
            'generated_at' => now($timezone)->toIso8601String(),
            'timezone' => $timezone,
            'locale' => $locale,
            'taxonomy_version' => Specialty::TAXONOMY_VERSION,
            'scope' => $specialty === null ? 'all' : $specialty,
            'unverified_notice' => $unverified ? 'Items with "verification": "unverified" were suggested by AI and have not been confirmed by a person.' : null,
            'subject' => [
                'name' => (string) $subject->name,
                'birth_date' => $birth,
                'age_years' => $birth === null ? null : (int) floor(Carbon::createFromFormat('Y-m-d', $birth, $timezone)->diffInYears(now($timezone))),
                'sex' => $subject->sex,
                'blood_type' => $subject->blood_type,
                'ai_consent' => (bool) $subject->ai_consent,
            ],
            'allergies' => $allergies,
            'medications' => $medications,
            'vaccinations' => $vaccinations,
            'visits' => $visits,
            'documents' => $documents,
        ];
    }

    private function date(mixed $value, string $timezone): ?string
    {
        if ($value === null) {
            return null;
        }
        if ($value instanceof CarbonInterface) {
            return $value->format('Y-m-d');
        }

        return substr((string) $value, 0, 10);
    }
}
