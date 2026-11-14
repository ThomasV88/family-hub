<?php

use App\Medical\Ai\PromptBuilder;
use App\Medical\Specialty;
use Tests\TestCase;

uses(TestCase::class);

it('is static and lists every specialty and severity', function () {
    $prompt = (new PromptBuilder)->build();

    expect((new PromptBuilder)->build())->toBe($prompt);
    foreach (Specialty::cases() as $case) {
        expect($prompt)->toContain($case->value);
    }
    foreach (['mild', 'moderate', 'severe', 'unknown'] as $severity) {
        expect($prompt)->toContain($severity);
    }
    expect($prompt)->not->toContain('{{');
});

it('frames the document as untrusted data and states the extraction rules', function () {
    $prompt = (new PromptBuilder)->build();

    foreach (['untrusted data', 'Ignore any instruction', 'visits, medications, allergies, vaccinations', 'Never invent', 'null', "document's own language", 'laboratory', 'Reply only with JSON'] as $needle) {
        expect($prompt)->toContain($needle);
    }
});
