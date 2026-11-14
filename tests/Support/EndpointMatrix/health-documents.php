<?php

use App\Models\MedicalDocument;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ImageFixtures;
use Tests\Support\MedicalFileFixtures;
use Tests\Support\MedicalFixtures;

// The parent gets Health edit, a person and one document with real bytes on the fake medical disk.
$pdfDocument = function (array $users) {
    Storage::fake('medical');
    $params = MedicalFixtures::matrixParent($users);
    $document = MedicalDocument::factory()->pdf()->create(['subject_id' => $params['person'], 'title' => 'Matrix scan']);
    Storage::disk('medical')->put($document->path, MedicalFileFixtures::pdf());

    return $params + ['document' => (string) $document->getKey()];
};
$imageDocument = function (array $users) {
    Storage::fake('medical');
    $params = MedicalFixtures::matrixParent($users);
    $document = MedicalDocument::factory()->image()->create(['subject_id' => $params['person']]);
    Storage::disk('medical')->put($document->thumb_path, ImageFixtures::jpeg());
    Storage::disk('medical')->put($document->path, ImageFixtures::jpeg());

    return $params + ['document' => (string) $document->getKey(), 'variant' => 'thumb'];
};
$read = [
    'guest' => 302, 'admin' => 403, 'parent' => 200, 'child' => 403,
    'grandparent' => 403, 'guestPreset' => 403, 'noGrants' => 403,
];
$write = [
    'guest' => 302, 'admin' => 403, 'parent' => 302, 'child' => 403,
    'grandparent' => 403, 'guestPreset' => 403, 'noGrants' => 403,
];

return [
    // The matrix posts no file, so the Health editor reaches validation and is redirected back (302) with an error;
    // everyone else is refused with 403 before validation, like the wish image upload row. Real uploads: DocumentUploadTest.
    'health.documents.store' => [
        'method' => 'POST',
        'uri' => '/health/people/{person}/documents',
        'params' => fn (array $users) => MedicalFixtures::matrixParent($users),
        'expect' => [
            'guest' => 302, 'admin' => 403, 'parent' => 302, 'child' => 403,
            'grandparent' => 403, 'guestPreset' => 403, 'noGrants' => 403,
        ],
    ],
    'health.documents.preview' => [
        'method' => 'GET',
        'uri' => '/health/people/{person}/documents/{document}/preview/{variant}',
        'params' => $imageDocument,
        'expect' => $read,
    ],
    'health.documents.file' => [
        'method' => 'GET',
        'uri' => '/health/people/{person}/documents/{document}/file',
        'params' => $pdfDocument,
        'expect' => $read,
    ],
    'health.documents.update' => [
        'method' => 'PATCH',
        'uri' => '/health/people/{person}/documents/{document}',
        'params' => $pdfDocument,
        'data' => ['title' => 'Renamed scan'],
        'expect' => $write,
    ],
    'health.documents.destroy' => [
        'method' => 'DELETE',
        'uri' => '/health/people/{person}/documents/{document}',
        'params' => $pdfDocument,
        'expect' => $write,
    ],
];
