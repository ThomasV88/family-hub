<?php

namespace App\Medical\Queries;

use App\Medical\Documents\Text\SearchTerms;
use App\Medical\Documents\Text\Snippets;
use App\Medical\Viewer;
use App\Medical\Visibility;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Document search for the request: ONE statement that starts from the viewer's visible, non-archived documents
 * (Visibility::apply on medical_documents, so a hidden document never enters the result set) and matches the recognised
 * text or the title. There is no count query and no total: the page asks for one row more than it shows. A malformed
 * person filter answers like a person with no match, without a query. Nothing here logs or catches: a QueryException
 * carries the bound query, and the application's scrubbed report (ScrubbedQueryReport) is what keeps it out of the log.
 */
class DocumentSearchQuery
{
    public const PER_PAGE = 20;

    /**
     * @param  list<list<string>>  $terms  SearchTerms::parse() output
     * @return array{items: list<array<string, mixed>>, hasMore: bool}
     */
    public function search(Viewer $viewer, array $terms, ?string $person, int $page): array
    {
        $empty = ['items' => [], 'hasMore' => false];
        $person = $person === null || $person === '' ? null : $person;
        if ($person !== null && ! Str::isUuid($person)) {
            return $empty;
        }

        [$tsquery, $bindings] = $this->tsquery($terms);
        if ($tsquery === null) {
            return $empty;
        }
        $page = max(1, min(50, $page));

        $query = Visibility::apply(DB::table('medical_documents'), $viewer, 'medical_documents.subject_id')
            ->crossJoinSub(DB::query()->selectRaw($tsquery.' as q', $bindings), 'sq')
            ->join('medical_subjects', 'medical_subjects.id', '=', 'medical_documents.subject_id')
            ->leftJoin('medical_document_texts', function ($join) {
                $join->on('medical_document_texts.document_id', '=', 'medical_documents.id')
                    ->on('medical_document_texts.subject_id', '=', 'medical_documents.subject_id');
            })
            // A visit that is archived reads as no visit, like PersonPage::documentProps.
            ->leftJoin('medical_visits', function ($join) {
                $join->on('medical_visits.id', '=', 'medical_documents.visit_id')
                    ->on('medical_visits.subject_id', '=', 'medical_documents.subject_id')
                    ->whereNull('medical_visits.archived_at');
            })
            ->whereRaw("(medical_document_texts.search @@ sq.q OR to_tsvector('simple', public.fh_unaccent(medical_documents.title)) @@ sq.q)");
        if ($person !== null) {
            $query->where('medical_documents.subject_id', $person);
        }

        $rows = $query
            ->orderByRaw('coalesce(ts_rank_cd(medical_document_texts.search, sq.q, 32), 0) desc')
            ->orderByDesc('medical_documents.created_at')
            ->orderBy('medical_documents.id')
            ->limit(self::PER_PAGE + 1)
            ->offset(($page - 1) * self::PER_PAGE)
            ->get([
                'medical_documents.id as document_id', 'medical_documents.subject_id', 'medical_documents.title', 'medical_documents.created_at',
                'medical_subjects.name as person_name',
                'medical_visits.id as visit_id', 'medical_visits.doctor as visit_doctor', 'medical_visits.visited_on as visit_on', 'medical_visits.specialty_key as visit_specialty',
                'medical_document_texts.pages_read', 'medical_document_texts.pages_total', 'medical_document_texts.complete', 'medical_document_texts.body',
            ]);

        $timezone = (string) config('family.timezone');
        $items = [];
        foreach ($rows->take(self::PER_PAGE) as $row) {
            $hasText = $row->body !== null;
            $built = $hasText ? Snippets::build((string) $row->body, $terms) : ['snippets' => [], 'page' => null];
            $items[] = [
                'documentId' => (string) $row->document_id,
                'personId' => (string) $row->subject_id,
                'personName' => (string) $row->person_name,
                'title' => (string) $row->title,
                'uploadedOn' => Carbon::parse($row->created_at)->setTimezone($timezone)->format('Y-m-d'),
                'visitId' => $row->visit_id === null ? null : (string) $row->visit_id,
                'visitName' => $row->visit_id === null || $row->visit_doctor === null ? null : (string) $row->visit_doctor,
                'visitOn' => $row->visit_id === null ? null : Carbon::parse($row->visit_on)->format('Y-m-d'),
                'visitSpecialty' => $row->visit_id === null ? null : (string) $row->visit_specialty,
                'page' => $built['page'],
                'snippets' => $built['snippets'],
                'textComplete' => $hasText ? (bool) $row->complete : true,
                'pagesRead' => $hasText ? (int) $row->pages_read : null,
                'pagesTotal' => $hasText ? (int) $row->pages_total : null,
            ];
        }

        return ['items' => $items, 'hasMore' => $rows->count() > self::PER_PAGE];
    }

    /**
     * to_tsquery text built in SQL from bound parameters: a prefix match per alternative, OR inside a token, AND between
     * tokens. The bound values are the folded alternatives, lower case ASCII letters and digits only, so no tsquery
     * operator can ever be injected; the SQL fold runs over them again for the index side.
     *
     * @param  list<list<string>>  $terms
     * @return array{?string, list<string>}
     */
    private function tsquery(array $terms): array
    {
        $groups = [];
        $bindings = [];
        foreach ($terms as $alternatives) {
            $parts = [];
            foreach ($alternatives as $alt) {
                $folded = SearchTerms::fold($alt);
                if (preg_match('/^[a-z0-9]+$/', $folded) !== 1) {
                    continue;
                }
                $parts[] = "(public.fh_unaccent(lower(?)) || ':*')";
                $bindings[] = $folded;
            }
            if ($parts !== []) {
                $groups[] = "'(' || ".implode(" || ' | ' || ", $parts)." || ')'";
            }
        }
        if ($groups === []) {
            return [null, []];
        }

        return ["to_tsquery('simple', ".implode(" || ' & ' || ", $groups).')', $bindings];
    }
}
