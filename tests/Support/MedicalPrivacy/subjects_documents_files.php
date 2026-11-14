<?php

use App\Models\MedicalDocument;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ImageFixtures;
use Tests\Support\MedicalPrivacy;

// File surfaces of the documents area (sorts after subjects_documents.php, which fakes the medical disk and seeds the
// PDFs). The preview route is compared with the hidden PDF (a non-image answers 403 either way) and the hidden image
// documents below carry canary titles, so the preview and file bodies and headers are part of the differential.
return [
    'hidden' => function (array &$ctx): void {
        foreach (['person' => 'document_image', 'own_off' => 'document_image_own_off'] as $subject => $key) {
            $document = MedicalDocument::factory()->image()->create([
                'subject_id' => $ctx['hidden'][$subject],
                'title' => MedicalPrivacy::CANARY.' hidden image title',
            ]);
            Storage::disk('medical')->put($document->path, ImageFixtures::jpeg());
            Storage::disk('medical')->put($document->thumb_path, ImageFixtures::jpeg());
            $ctx['hidden'][$key] = (string) $document->getKey();
        }
    },

    'params' => [
        'variant' => fn (array $ctx): string => 'thumb',
    ],

    'payloads' => [
        'health.documents.update' => fn (array $ctx): array => ['title' => 'Parity rename'],
    ],
];
