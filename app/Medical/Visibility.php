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
    /** Subquery: ids of the subjects the viewer may see at the given minimum level. */
    public static function subjectIds(Viewer $viewer, AccessLevel $min = AccessLevel::View): Builder
    {
        $own = DB::table('medical_subjects')
            ->select('id')
            ->where('user_id', $viewer->userId())
            ->where('self_access_enabled', true);

        return DB::table('medical_subject_members')
            ->select('subject_id')
            ->where('user_id', $viewer->userId())
            ->where('level', '>=', $min->value)
            ->union($own);
    }

    /**
     * @template T of BuilderContract
     *
     * @param  T  $query
     * @return T
     */
    public static function apply($query, Viewer $viewer, string $column = 'subject_id', AccessLevel $min = AccessLevel::View)
    {
        return $query->whereIn($column, self::subjectIds($viewer, $min));
    }
}
