<?php

namespace Tests\Support;

use App\Access\Preset;
use App\Models\User;

/** The seven roles every endpoint is proven against. `guest` is no user at all. */
class Roles
{
    /** @return list<string> */
    public static function all(): array
    {
        return ['guest', 'admin', 'parent', 'child', 'grandparent', 'guestPreset', 'noGrants'];
    }

    /** @return array<string, ?User> */
    public static function make(): array
    {
        return [
            'guest' => null,
            // Admin: settings only, deliberately without any module grant.
            'admin' => User::factory()->admin()->create(),
            'parent' => User::factory()->withPreset(Preset::Parent)->create(),
            'child' => User::factory()->withPreset(Preset::Child)->create(),
            'grandparent' => User::factory()->withPreset(Preset::Grandparent)->create(),
            'guestPreset' => User::factory()->withPreset(Preset::Guest)->create(),
            'noGrants' => User::factory()->create(),
        ];
    }
}
