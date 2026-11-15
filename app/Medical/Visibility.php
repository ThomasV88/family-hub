<?php

namespace App\Medical;

use App\Access\AccessLevel;
use Illuminate\Contracts\Database\Query\Builder as BuilderContract;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The only subject scope; applied only inside App\Medical\Queries; no admin branch.
 *
 * Two branches: subjects the viewer was made a member of (at least $min), and the viewer's own linked profile while
 * its self access is on (own record counts as Edit). The effective level is min(module level, subject level), so
 * writes additionally require Viewer::canEdit().
 */
final class Visibility
{
    /** Record tables that carry archived_at / archived_by (medical_subjects is handled by subjectIds). */
    private const ARCHIVABLE = ['medical_visits', 'medical_medications', 'medical_allergies', 'medical_vaccinations', 'medical_documents'];

    /**
     * Subquery: ids of the subjects the viewer may see at the given minimum level. Archived people are excluded unless
     * $archive says otherwise, so everything below an archived person (records, documents, AI rows) disappears with it.
     */
    public static function subjectIds(Viewer $viewer, AccessLevel $min = AccessLevel::View, ArchiveState $archive = ArchiveState::Active): Builder
    {
        $own = DB::table('medical_subjects')
            ->select('id')
            ->where('user_id', $viewer->userId())
            ->where('self_access_enabled', true);
        self::archiveClause($own, 'archived_at', $archive);

        $members = DB::table('medical_subject_members')
            ->select('subject_id')
            ->where('user_id', $viewer->userId())
            ->where('level', '>=', $min->value);
        if ($archive !== ArchiveState::Any) {
            $members->whereIn('subject_id', fn ($q) => self::archiveClause($q->select('id')->from('medical_subjects'), 'archived_at', $archive));
        }

        return $members->union($own);
    }

    /**
     * @template T of BuilderContract
     *
     * @param  T  $query
     * @return T
     *
     * On medical_subjects (column id) $archive selects the subject's own state; on a record table the subject must be
     * active and $archive selects the record's state. Other tables only get the subject constraint.
     */
    public static function apply($query, Viewer $viewer, string $column = 'subject_id', AccessLevel $min = AccessLevel::View, ArchiveState $archive = ArchiveState::Active)
    {
        $table = self::tableOf($query);
        if ($table === 'medical_subjects') {
            return $query->whereIn($column, self::subjectIds($viewer, $min, $archive));
        }
        $query->whereIn($column, self::subjectIds($viewer, $min));
        if (in_array($table, self::ARCHIVABLE, true)) {
            self::archiveClause($query, "{$table}.archived_at", $archive);
        }

        return $query;
    }

    /** @param  mixed  $query */
    private static function archiveClause($query, string $column, ArchiveState $archive)
    {
        return match ($archive) {
            ArchiveState::Active => $query->whereNull($column),
            ArchiveState::Only => $query->whereNotNull($column),
            ArchiveState::Any => $query,
        };
    }

    /** The table a builder reads, without alias; '' when it cannot be told. */
    private static function tableOf(mixed $query): string
    {
        $base = $query instanceof \Illuminate\Database\Eloquent\Builder ? $query->getQuery() : $query;
        $from = $base instanceof Builder ? $base->from : null;

        return is_string($from) ? strtolower(trim((string) preg_split('/\s+as\s+/i', $from)[0], '"')) : '';
    }
}
