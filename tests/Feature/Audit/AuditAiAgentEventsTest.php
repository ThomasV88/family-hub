<?php

use App\Audit\AuditEvent;
use App\Audit\AuditLogger;
use App\Models\AuditEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('declares the meta keys and the health group for both AI events', function () {
    expect(AuditEvent::AiAgentSettingChanged->value)->toBe('ai_agent_setting_changed')
        ->and(AuditEvent::AiAgentSettingChanged->metaKeys())->toBe(['agent', 'field', 'value'])
        ->and(AuditEvent::AiAgentSettingChanged->group())->toBe('health')
        ->and(AuditEvent::AiBulkRunChanged->value)->toBe('ai_bulk_run_changed')
        ->and(AuditEvent::AiBulkRunChanged->metaKeys())->toBe(['action', 'scope', 'count'])
        ->and(AuditEvent::AiBulkRunChanged->group())->toBe('health')
        ->and(AuditEvent::eventsForGroup('health')['events'])->toContain('ai_agent_setting_changed', 'ai_bulk_run_changed');
});

it('has a group for every audit event (exhaustive match)', function () {
    foreach (AuditEvent::cases() as $event) {
        expect($event->group())->toBeString()->not->toBe('');
        expect($event->metaKeys())->toBeArray();
    }
});

it('records both events with only whitelisted, content-free meta and no subject', function () {
    $admin = User::factory()->create(['name' => 'Anna']);

    app(AuditLogger::class)->record(AuditEvent::AiAgentSettingChanged, $admin, ['agent' => 'codex', 'field' => 'model', 'value' => 'gpt-5.1']);
    app(AuditLogger::class)->record(AuditEvent::AiBulkRunChanged, $admin, ['action' => 'started', 'scope' => 'no_proposal', 'count' => 12]);

    $rows = AuditEntry::orderBy('id')->get();
    expect($rows)->toHaveCount(2)
        ->and($rows[0]->meta)->toEqual(['agent' => 'codex', 'field' => 'model', 'value' => 'gpt-5.1'])
        ->and($rows[1]->meta)->toEqual(['action' => 'started', 'scope' => 'no_proposal', 'count' => 12])
        ->and($rows[0]->target_user_id)->toBeNull()
        ->and($rows[1]->target_label)->toBeNull();
});

it('rejects extra meta such as a title or subject and writes nothing', function (AuditEvent $event, array $meta) {
    expect(fn () => app(AuditLogger::class)->record($event, null, $meta))->toThrow(InvalidArgumentException::class);
    expect(AuditEntry::count())->toBe(0);
})->with([
    'agent title' => [AuditEvent::AiAgentSettingChanged, ['agent' => 'codex', 'title' => 'Blood test']],
    'agent subject' => [AuditEvent::AiAgentSettingChanged, ['subject' => 'x']],
    'bulk document' => [AuditEvent::AiBulkRunChanged, ['action' => 'started', 'document' => 'x']],
    'bulk title' => [AuditEvent::AiBulkRunChanged, ['title' => 'Blood test']],
    'long value' => [AuditEvent::AiAgentSettingChanged, ['value' => str_repeat('a', 121)]],
]);
