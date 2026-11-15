<?php

use App\Models\MedicalDocument;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\MedicalFileFixtures;
use Tests\Support\MedicalPrivacy;

// Documents area (file name sorts after subjects.php, whose hidden seed creates the hidden people). One PDF on the visible subject A with a file on the fake medical disk; canary documents (title and
// PDF bytes) on the hidden subject B and the self-access-off subject C. Later routes use the `document` parameter.
$seed = function (string $subject, string $title, string $marker): string {
    $document = MedicalDocument::factory()->pdf()->create(['subject_id' => $subject, 'title' => $title]);
    Storage::disk('medical')->put($document->path, MedicalFileFixtures::pdf($marker));

    return (string) $document->getKey();
};

return [
    'visible' => function (array &$ctx) use ($seed): void {
        Storage::fake('medical');
        $ctx['document'] = $seed($ctx['person']->getKey(), 'Visible scan', 'visible');
    },

    // Runs after `visible`, which fakes the medical disk once; faking again here would wipe the visible file.
    'hidden' => function (array &$ctx) use ($seed): void {
        $ctx['hidden']['document'] = $seed($ctx['hidden']['person'], MedicalPrivacy::CANARY.' hidden title', MedicalPrivacy::CANARY.' hidden bytes');
        $ctx['hidden']['document_own_off'] = $seed($ctx['hidden']['own_off'], MedicalPrivacy::CANARY.' own_off title', MedicalPrivacy::CANARY.' own_off bytes');
    },

    'params' => [
        'document' => fn (array $ctx): string => $ctx['document'],
    ],

    'hiddenParams' => [
        'document' => fn (array $ctx): string => $ctx['hidden']['document'],
    ],

    'payloads' => [
        'health.documents.store' => fn (array $ctx): array => [
            'file' => UploadedFile::fake()->createWithContent('scan.pdf', MedicalFileFixtures::pdf()),
        ],
    ],
];
