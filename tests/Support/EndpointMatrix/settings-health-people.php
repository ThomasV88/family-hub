<?php

use App\Models\MedicalSubject;
use App\Models\User;

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
        'params' => fn (array $users) => ['person' => (string) MedicalSubject::factory()->create()->getKey(), 'member' => User::factory()->create()->id],
        'data' => fn (array $users, array $params) => ['user_id' => $params['member'], 'self_access_enabled' => true],
        'expect' => ['guest' => 302, 'admin' => 303] + $nonAdmins,
    ],
];
