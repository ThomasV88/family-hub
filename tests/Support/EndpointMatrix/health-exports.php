<?php

use App\Models\MedicalVisit;
use Tests\Support\MedicalFixtures;

// The parent gets Health edit, a person and one visit to export. The print and download rows pass the scope in the
// query string (literal parent 200, everyone else 403, guest to login).
$parent = function (array $users) {
    $params = MedicalFixtures::matrixParent($users);
    MedicalVisit::factory()->create(['subject_id' => $params['person'], 'specialty_key' => 'cardiology']);

    return $params;
};
$expect = [
    'guest' => 302, 'admin' => 403, 'parent' => 200, 'child' => 403,
    'grandparent' => 403, 'guestPreset' => 403, 'noGrants' => 403,
];

return [
    'health.exports.show' => ['method' => 'GET', 'uri' => '/health/people/{person}/export', 'params' => $parent, 'expect' => $expect],
    'health.exports.print' => [
        'method' => 'GET', 'uri' => '/health/people/{person}/export/print', 'params' => $parent,
        'data' => ['scope' => 'all'], 'expect' => $expect,
    ],
    'health.exports.download' => [
        'method' => 'GET', 'uri' => '/health/people/{person}/export/download', 'params' => $parent,
        'data' => ['scope' => 'all', 'format' => 'json'], 'expect' => $expect,
    ],
];
