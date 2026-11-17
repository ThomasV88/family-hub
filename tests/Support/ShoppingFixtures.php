<?php

namespace Tests\Support;

use App\Access\AccessGate;
use App\Access\AccessLevel;
use App\Access\Module;
use App\Models\ShoppingItem;
use App\Models\ShoppingList;
use App\Models\ShoppingListMember;
use App\Models\User;
use App\Shopping\ListStatus;
use App\Shopping\MemberRole;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Shared builders and assertions for the Shopping domain tests. */
final class ShoppingFixtures
{
    public static function user(AccessLevel $level = AccessLevel::Edit, bool $child = false): User
    {
        $factory = $child ? User::factory()->child() : User::factory();
        $user = $factory->create();
        self::setLevel($user, $level);

        return $user;
    }

    public static function setLevel(User $user, AccessLevel $level): void
    {
        $user->modulePermissions()->updateOrCreate(['module' => Module::Shopping->value], ['level' => $level]);
        app(AccessGate::class)->forget($user);
    }

    /** @param list<User> $contributors @param list<User> $viewers */
    public static function list(User $creator, ?User $responsible = null, ListStatus $status = ListStatus::Open, array $contributors = [], array $viewers = []): ShoppingList
    {
        $factory = ShoppingList::factory()->state(['creator_id' => $creator->id, 'responsible_id' => ($responsible ?? $creator)->id]);
        $factory = match ($status) {
            ListStatus::Locked => $factory->locked(),
            ListStatus::Done => $factory->done(),
            default => $factory,
        };
        $list = $factory->create();
        foreach ($contributors as $u) {
            self::member($list, $u, MemberRole::Contributor);
        }
        foreach ($viewers as $u) {
            self::member($list, $u, MemberRole::Viewer);
        }

        return $list;
    }

    public static function member(ShoppingList $list, User $user, MemberRole $role): void
    {
        $row = new ShoppingListMember;
        $row->forceFill(['list_id' => $list->id, 'user_id' => $user->id, 'role' => $role, 'created_at' => now()])->save();
    }

    public static function item(ShoppingList $list, array $over = []): ShoppingItem
    {
        return ShoppingItem::factory()->create(array_merge(['list_id' => $list->id, 'position' => (int) $list->items()->max('position') + 1], $over));
    }

    /** Assert the callable aborts with the given HTTP status. */
    public static function status(callable $fn): int
    {
        try {
            $fn();
        } catch (HttpException $e) {
            return $e->getStatusCode();
        } catch (HttpResponseException $e) {
            return $e->getResponse()->getStatusCode();
        }

        return 200;
    }

    /** @return array<string, list<string>> validation errors, or [] when the callable passes */
    public static function errors(callable $fn): array
    {
        try {
            $fn();
        } catch (ValidationException $e) {
            return $e->errors();
        }

        return [];
    }

    public static function states(ShoppingList $list): array
    {
        return $list->items()->get()->map(fn (ShoppingItem $i) => $i->state)->all();
    }
}
