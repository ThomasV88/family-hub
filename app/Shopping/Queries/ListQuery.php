<?php

namespace App\Shopping\Queries;

use App\Models\ShoppingList;
use App\Models\User;
use App\Shopping\ShoppingAccess;
use Illuminate\Database\Eloquent\Builder;

final class ListQuery
{
    /**
     * Lists the user created, is responsible for, or belongs to as contributor or viewer. There is no administrator
     * bypass. Callers load with findOrFail so everything else answers a uniform 404.
     *
     * @return Builder<ShoppingList>
     */
    public static function visibleTo(User $user): Builder
    {
        return ShoppingList::query()->where(function (Builder $q) use ($user) {
            $q->where('shopping_lists.creator_id', $user->id)
                ->orWhere('shopping_lists.responsible_id', $user->id)
                ->orWhereExists(function ($sub) use ($user) {
                    $sub->selectRaw('1')->from('shopping_list_members')
                        ->whereColumn('shopping_list_members.list_id', 'shopping_lists.id')
                        ->where('shopping_list_members.user_id', $user->id);
                });
        });
    }

    /**
     * The list row under a row lock, for a mutation inside DB::transaction. A list the actor cannot see (or does not
     * exist) is a 404, uniform with every other hidden list.
     */
    public static function lockForActor(User $actor, int $listId, ShoppingAccess $access): ShoppingList
    {
        $list = self::visibleTo($actor)->whereKey($listId)->lockForUpdate()->first();
        abort_if($list === null || ! $access->canView($actor, $list), 404);

        return $list;
    }
}
