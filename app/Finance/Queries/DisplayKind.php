<?php

namespace App\Finance\Queries;

use App\Finance\Viewer;
use App\Finance\Visibility;

/**
 * The display-kind rule as one SQL fragment: income and expense rows keep their kind, a transfer leg whose counterpart
 * account the viewer can see stays a transfer (and is excluded from income, spending and budgets), and a transfer leg
 * with a hidden counterpart counts as income or expense by its sign. The counterpart lookup carries the membership
 * subquery, so a hidden counterpart is never revealed or counted.
 */
final class DisplayKind
{
    /**
     * @return array{0: string, 1: list<mixed>} the CASE expression (yielding 'income', 'expense' or 'transfer') and its bindings
     */
    public static function sql(Viewer $viewer, string $alias = 't'): array
    {
        $members = Visibility::accountIds($viewer);

        $sql = "(CASE WHEN {$alias}.kind <> 'transfer' THEN {$alias}.kind"
            .' WHEN EXISTS (select 1 from finance_transactions as dk_c'
            ." where dk_c.transfer_id = {$alias}.transfer_id and dk_c.id <> {$alias}.id"
            .' and dk_c.account_id in ('.$members->toSql().'))'
            ." THEN 'transfer'"
            ." WHEN {$alias}.amount_minor > 0 THEN 'income' ELSE 'expense' END)";

        return [$sql, $members->getBindings()];
    }
}
