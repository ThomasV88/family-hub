<?php

namespace Tests\Support;

use App\Access\AccessGate;
use App\Access\AccessLevel;
use App\Models\MedicalSubject;
use App\Models\User;

/**
 * Health fixtures. No preset grants Health, so every test that needs access grants it here. Keep them tiny.
 */
class MedicalFixtures
{
    public static function grantHealth(User $user, AccessLevel $level = AccessLevel::Edit): void
    {
        $user->modulePermissions()->updateOrCreate(['module' => 'health'], ['level' => $level]);
        app(AccessGate::class)->forget($user);
    }

    /**
     * @param  array<string, mixed>  $attrs
     * @param  list<array{0: User, 1: AccessLevel}>  $members
     */
    public static function subject(array $attrs = [], array $members = []): MedicalSubject
    {
        $factory = MedicalSubject::factory();
        foreach ($members as [$user, $level]) {
            $factory = $factory->withMember($user, $level);
        }

        return $factory->create($attrs);
    }

    /**
     * EndpointMatrix params: the parent gets Health edit and a person to open.
     *
     * @param  array<string, ?User>  $users
     * @return array{person: string}
     */
    public static function matrixParent(array $users): array
    {
        $parent = $users['parent'];
        self::grantHealth($parent);

        return ['person' => (string) self::subject([], [[$parent, AccessLevel::Edit]])->getKey()];
    }
}
