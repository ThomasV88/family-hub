<?php

use App\Models\MedicalDocument;
use Tests\Support\DocumentTextFixtures;
use Tests\Support\MedicalFixtures;

// The parent gets Health edit and a person; the search needs no id. A guest is redirected to login, everyone without
// Health view is refused before the controller runs. A query that matches nothing still answers 200 (empty items).
$withText = function (array $users) {
    $params = MedicalFixtures::matrixParent($users);
    $document = MedicalDocument::factory()->pdf()->create(['subject_id' => $params['person'], 'title' => 'Matrix text']);
    DocumentTextFixtures::save($document, ['Matrix recognised text']);

    return $params + ['document' => (string) $document->getKey()];
};
$expect = fn (int $parent) => [
    'guest' => 302, 'admin' => 403, 'parent' => $parent, 'child' => 403,
    'grandparent' => 403, 'guestPreset' => 403, 'noGrants' => 403,
];

return [
    'health.search' => [
        'method' => 'POST',
        'uri' => '/health/search',
        'params' => fn (array $users) => MedicalFixtures::matrixParent($users),
        'data' => ['q' => 'matrix'],
        'expect' => $expect(200),
    ],
    'health.documents.text' => [
        'method' => 'GET',
        'uri' => '/health/people/{person}/documents/{document}/text',
        'params' => $withText,
        'expect' => $expect(200),
    ],
    'health.documents.text.destroy' => [
        'method' => 'DELETE',
        'uri' => '/health/people/{person}/documents/{document}/text',
        'params' => $withText,
        'expect' => $expect(204),
    ],
];
