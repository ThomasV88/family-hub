<?php

use App\Models\MedicalSubject;
use App\Models\User;
use Tests\Support\MedicalFixtures;

$nonAdmins = ['parent' => 403, 'child' => 403, 'grandparent' => 403, 'guestPreset' => 403, 'noGrants' => 403];

return [
    'settings.health_people.members.update' => [
        'method' => 'PUT',
        'uri' => '/settings/health-people/{person}/members',
        'params' => fn (array $users) => ['person' => (string) MedicalSubject::factory()->create()->getKey()],
        'data' => ['levels' => []],
        'expect' => ['guest' => 302, 'admin' => 303] + $nonAdmins,
    ],
    'settings.health_people.link.update' => [
        'method' => 'PUT',
        'uri' => '/settings/health-people/{person}/link',
        // The member needs the Health module, or the link is refused (same rule as the members screen).
        'params' => function (array $users): array {
            $member = User::factory()->create();
            MedicalFixtures::grantHealth($member);

            return ['person' => (string) MedicalSubject::factory()->create()->getKey(), 'member' => $member->id];
        },
        'data' => fn (array $users, array $params) => ['user_id' => $params['member'], 'self_access_enabled' => true],
        'expect' => ['guest' => 302, 'admin' => 303] + $nonAdmins,
    ],
];
