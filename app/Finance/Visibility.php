<?php

namespace App\Finance;

use Illuminate\Contracts\Database\Query\Builder as BuilderContract;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The single membership scope of the finance module. Applied only inside App\Finance\Queries.
 */
final class Visibility
{
    /** Subquery: ids of the accounts the viewer was added to. */
    public static function accountIds(Viewer $viewer): Builder
    {
        return DB::table('finance_account_members')
            ->select('account_id')
            ->where('user_id', $viewer->userId());
    }

    /**
     * @template T of BuilderContract
     *
     * @param  T  $query
     * @return T
     */
    public static function apply($query, Viewer $viewer, string $column = 'account_id')
    {
        return $query->whereIn($column, self::accountIds($viewer));
    }
}
