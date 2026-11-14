<?php

namespace App\Medical\Queries;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Admin names-only listing (Phase 7 admin rule). Never selects document names, prompts, results or record data.
 * Reads only the content-free ai_calls row plus the person name and member display name.
 */
final class AdminAiCallQuery
{
    public const PER_PAGE = 25;

    public const STATUSES = ['queued', 'running', 'succeeded', 'failed', 'cancelled'];

    /**
     * Invalid filter values are ignored.
     *
     * @param  array{person?: mixed, member?: mixed, status?: mixed}  $filters
     * @return array{person: ?string, member: ?int, status: ?string}
     */
    public function normalise(array $filters): array
    {
        $person = $filters['person'] ?? null;
        $member = $filters['member'] ?? null;
        $status = $filters['status'] ?? null;

        return [
            'person' => is_string($person) && Str::isUuid($person) ? strtolower($person) : null,
            'member' => (is_string($member) || is_int($member)) && ctype_digit((string) $member) && (int) $member > 0
                && DB::table('users')->where('id', (int) $member)->exists() ? (int) $member : null,
            'status' => is_string($status) && in_array($status, self::STATUSES, true) ? $status : null,
        ];
    }

    /**
     * @param  array{person?: mixed, member?: mixed, status?: mixed}  $filters
     * @return array{rows: list<array<string, mixed>>, page: int, pages: int, total: int}
     */
    public function page(array $filters, int $page): array
    {
        $f = $this->normalise($filters);

        $base = DB::table('ai_calls as c')
            ->leftJoin('medical_subjects as s', 's.id', '=', 'c.subject_id')
            ->leftJoin('users as u', 'u.id', '=', 'c.started_by')
            ->when($f['person'], fn ($q, $v) => $q->where('c.subject_id', $v))
            ->when($f['member'], fn ($q, $v) => $q->where('c.started_by', $v))
            ->when($f['status'], fn ($q, $v) => $q->where('c.status', $v));

        $total = (clone $base)->count();
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min(max(1, $page), $pages);

        $rows = $base->orderByDesc('c.queued_at')->orderByDesc('c.id')
            ->forPage($page, self::PER_PAGE)
            ->get(['c.queued_at', 'c.agent', 'c.model', 'c.status', 'c.duration_ms', 's.name as person_name', 'u.name as member_name'])
            ->map(fn ($r) => [
                'time' => Carbon::parse($r->queued_at)->toIso8601String(),
                'startedBy' => $r->member_name,
                'person' => $r->person_name,
                'agent' => $r->agent,
                'model' => $r->model,
                'status' => $r->status,
                'durationMs' => $r->duration_ms === null ? null : (int) $r->duration_ms,
            ])->all();

        return ['rows' => $rows, 'page' => $page, 'pages' => $pages, 'total' => $total];
    }

    /** @return array{people: list<array{id: string, name: string}>, members: list<array{id: int, name: string}>} */
    public function options(): array
    {
        return [
            'people' => DB::table('medical_subjects')->orderByRaw('lower(name)')->orderBy('id')->get(['id', 'name'])
                ->map(fn ($p) => ['id' => (string) $p->id, 'name' => (string) $p->name])->all(),
            'members' => DB::table('users')->orderByRaw('lower(name)')->orderBy('id')->get(['id', 'name'])
                ->map(fn ($u) => ['id' => (int) $u->id, 'name' => (string) $u->name])->all(),
        ];
    }
}
