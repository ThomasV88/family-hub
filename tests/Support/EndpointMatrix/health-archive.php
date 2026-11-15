<?php

use App\Access\AccessLevel;
use App\Models\MedicalVisit;
use Tests\Support\MedicalFixtures;

// Restore routes. The parent gets Health edit; for the person route the person itself is archived, for the record route
// the person is active and one of its visits is archived.
$archivedPerson = function (array $users) {
    $parent = $users['parent'];
    MedicalFixtures::grantHealth($parent);

    return ['person' => (string) MedicalFixtures::subject(['archived_at' => now()], [[$parent, AccessLevel::Edit]])->getKey()];
};
$archivedVisit = function (array $users) {
    $params = MedicalFixtures::matrixParent($users);
    $visit = MedicalVisit::factory()->create(['subject_id' => $params['person'], 'archived_at' => now()]);

    return $params + ['type' => 'visit', 'record' => (string) $visit->getKey()];
};
$expect = [
    'guest' => 302, 'admin' => 403, 'parent' => 302, 'child' => 403,
    'grandparent' => 403, 'guestPreset' => 403, 'noGrants' => 403,
];

return [
    'health.people.restore' => [
        'method' => 'POST',
        'uri' => '/health/people/{person}/restore',
        'params' => $archivedPerson,
        'expect' => $expect,
    ],
    'health.archive.restore' => [
        'method' => 'POST',
        'uri' => '/health/people/{person}/archive/{type}/{record}/restore',
        'params' => $archivedVisit,
        'expect' => $expect,
    ],
];
