<?php

namespace App\Wishlist;

use App\Access\AccessLevel;
use App\Access\Module;
use App\Models\User;
use Collator;

/** Members whose lists exist: everyone with wishlist view or edit. Names only. */
final class WishlistMembers
{
    /** @return list<array{id: int, name: string, is_viewer: bool}> viewer first, the rest locale-sorted */
    public function forViewer(User $viewer): array
    {
        $rows = User::query()
            ->whereIn('users.id', fn ($q) => $q->select('user_id')->from('module_permissions')
                ->where('module', Module::Wishlist->value)
                ->where('level', '>=', AccessLevel::View->value))
            ->get(['id', 'name']);

        $members = $rows->map(fn (User $m) => ['id' => $m->id, 'name' => $m->name, 'is_viewer' => $m->id === $viewer->id])->all();

        $collator = new Collator(app()->getLocale());
        usort($members, function (array $a, array $b) use ($collator) {
            if ($a['is_viewer'] !== $b['is_viewer']) {
                return $a['is_viewer'] ? -1 : 1;
            }

            return ($collator->compare($a['name'], $b['name']) ?: 0) ?: $a['id'] <=> $b['id'];
        });

        return array_values($members);
    }

    /** @return list<int> */
    public function ids(User $viewer): array
    {
        return array_map(fn (array $m) => $m['id'], $this->forViewer($viewer));
    }
}
