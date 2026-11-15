<?php

use Tests\Support\MedicalFixtures;

// No preset grants Health, so the parent's params closure grants Health edit and creates the membership.
$parent = fn (array $users) => MedicalFixtures::matrixParent($users);

return [
    'health.index' => [
        'method' => 'GET',
        'uri' => '/health',
        'params' => $parent,
        'expect' => [
            'guest' => 302, 'admin' => 403, 'parent' => 302, 'child' => 403,
            'grandparent' => 403, 'guestPreset' => 403, 'noGrants' => 403,
        ],
    ],
    'health.people.show' => [
        'method' => 'GET',
        'uri' => '/health/people/{person}',
        'params' => $parent,
        'expect' => [
            'guest' => 302, 'admin' => 403, 'parent' => 200, 'child' => 403,
            'grandparent' => 403, 'guestPreset' => 403, 'noGrants' => 403,
        ],
    ],
    'health.people.store' => [
        'method' => 'POST',
        'uri' => '/health/people',
        'params' => $parent,
        'data' => ['name' => 'Matrix person'],
        'expect' => [
            'guest' => 302, 'admin' => 403, 'parent' => 302, 'child' => 403,
            'grandparent' => 403, 'guestPreset' => 403, 'noGrants' => 403,
        ],
    ],
    'health.people.update' => [
        'method' => 'PATCH',
        'uri' => '/health/people/{person}',
        'params' => $parent,
        'data' => ['name' => 'Matrix person'],
        'expect' => [
            'guest' => 302, 'admin' => 403, 'parent' => 302, 'child' => 403,
            'grandparent' => 403, 'guestPreset' => 403, 'noGrants' => 403,
        ],
    ],
    'health.people.destroy' => [
        'method' => 'DELETE',
        'uri' => '/health/people/{person}',
        'params' => $parent,
        'expect' => [
            'guest' => 302, 'admin' => 403, 'parent' => 302, 'child' => 403,
            'grandparent' => 403, 'guestPreset' => 403, 'noGrants' => 403,
        ],
    ],
];
