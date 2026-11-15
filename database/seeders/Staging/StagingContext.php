<?php

namespace Database\Seeders\Staging;

use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * What every module seeder of the staging demo needs: "today" in the family time zone (all dates are relative to it,
 * so the data always looks current) and the synthetic members by role.
 *
 * Roles map to the fixed accounts: parent1 sk_sk.1, child1 sk_sk.2, grandparent pl_pl.3, guest pl_pl.4, parent2 en_gb.5,
 * child2 en_gb.6, admin admin@example.test.
 */
final class StagingContext
{
    public const ROLES = ['parent1', 'child1', 'grandparent', 'guest', 'parent2', 'child2'];

    /** @param  array<string, User>  $users  keyed by role */
    public function __construct(public readonly CarbonImmutable $today, private readonly array $users) {}

    public static function today(): CarbonImmutable
    {
        return CarbonImmutable::now((string) config('family.timezone'))->startOfDay();
    }

    public function user(string $role): User
    {
        return $this->users[$role];
    }

    /** @return list<int> */
    public function memberIds(): array
    {
        return array_values(array_map(fn (User $u) => $u->id, array_filter($this->users, fn (string $role) => $role !== 'admin', ARRAY_FILTER_USE_KEY)));
    }

    /** Day offset from today as Y-m-d (negative = past). */
    public function day(int $offset): string
    {
        return $this->today->addDays($offset)->format('Y-m-d');
    }

    /** Timestamp of a day offset at the given local hour, for created_at / completed_at. */
    public function at(int $offset, int $hour = 12, int $minute = 0): CarbonImmutable
    {
        return $this->today->addDays($offset)->setTime($hour, $minute);
    }
}
