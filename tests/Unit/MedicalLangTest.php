<?php

use App\Audit\AuditEvent;
use Tests\TestCase;

uses(TestCase::class);

it('puts the person in the vaccination title', function (string $locale) {
    expect(__('notifications.vaccination_title', ['person' => 'Eva'], $locale))->toContain('Eva');
    expect(__('notifications.vaccination_body', ['person' => 'Eva', 'date' => '5 Mar'], $locale))
        ->toContain('Eva')
        ->not->toContain('5 Mar');
    expect(trim(__('notifications.vaccination_action', [], $locale)))->not->toBe('notifications.vaccination_action');
})->with(['en', 'sk', 'pl']);

it('never uses a vaccine placeholder in notification copy', function (string $locale) {
    $lines = require base_path("lang/{$locale}/notifications.php");

    foreach ($lines as $key => $value) {
        if (str_starts_with($key, 'vaccination_')) {
            expect(str_contains($value, ':vaccine'))->toBeFalse($key);
            expect(str_contains($value, ':diagnosis'))->toBeFalse($key);
            expect(str_contains($value, '!'))->toBeFalse($key);
        }
    }

    preg_match_all('/:(\w+)/', $lines['vaccination_title'].' '.$lines['vaccination_body'], $m);
    expect(array_values(array_unique($m[1])))->toEqualCanonicalizing(['person']);
})->with(['en', 'sk', 'pl']);

it('pluralises the due-in phrase', function (string $locale, int $n) {
    $out = trans_choice('notifications.vaccination_due_in', $n, [], $locale);

    expect($out)->not->toBe('')->not->toBe('notifications.vaccination_due_in')->toContain((string) $n);
})->with(['en', 'sk', 'pl'])->with([0, 1, 2, 5, 22]);

it('has all nine medical audit events in the health group with content-free meta', function () {
    $expected = [
        'medical_subject_created' => ['subject'],
        'medical_subject_deleted' => ['subject'],
        'medical_access_changed' => ['subject', 'change'],
        'medical_admin_self_added' => ['subject'],
        'medical_exported' => ['subject', 'scope', 'format'],
        'medical_consent_changed' => ['subject', 'value'],
        'medical_ai_run_requested' => ['subject', 'document', 'bulk'],
        'medical_ai_run_finished' => ['subject', 'document', 'status', 'bulk'],
        'medical_ai_entry_decided' => ['subject', 'document', 'decision'],
    ];

    foreach ($expected as $value => $meta) {
        $event = AuditEvent::from($value);
        expect($event->group())->toBe('health');
        expect($event->metaKeys())->toBe($meta);
        expect($event->metaKeys())->not->toContain('name');
        expect(AuditEvent::eventsForGroup('health')['events'])->toContain($value);
    }
});

it('labels every medical audit event in every locale', function (string $locale) {
    $json = json_decode(file_get_contents(base_path("lang/{$locale}.json")), true);
    $detail = $json['audit']['detail'];

    foreach ([
        'medical_subject_created', 'medical_subject_deleted', 'medical_access_changed', 'medical_admin_self_added',
        'medical_exported', 'medical_consent_changed',
        'medical_ai_run_requested', 'medical_ai_run_finished', 'medical_ai_entry_decided',
    ] as $key) {
        expect(trim($detail[$key] ?? ''))->not->toBe('', $key);
    }
})->with(['en', 'sk', 'pl']);
