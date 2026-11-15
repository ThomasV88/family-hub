<?php

use App\Access\Module;
use App\Audit\AuditEvent;
use App\Audit\AuditLogger;
use App\Models\AuditEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('writes one row with actor snapshot, target, module', function () {
    $actor = User::factory()->create(['name' => 'Anna']);
    $target = User::factory()->create(['name' => 'Bob']);

    app(AuditLogger::class)->record(AuditEvent::PermissionChanged, $actor, ['from' => 'none', 'to' => 'view'], $target, Module::Finance);

    $row = AuditEntry::sole();
    expect($row->event)->toBe('permission_changed')
        ->and($row->actor_id)->toBe($actor->id)
        ->and($row->actor_label)->toBe('Anna')
        ->and($row->target_user_id)->toBe($target->id)
        ->and($row->target_label)->toBe('Bob')
        ->and($row->module)->toBe('finance')
        ->and($row->meta)->toEqual(['from' => 'none', 'to' => 'view'])
        ->and($row->occurred_at)->not->toBeNull();
});

it('rejects unknown meta keys, non-scalars and long strings without writing', function (array $meta) {
    expect(fn () => app(AuditLogger::class)->record(AuditEvent::PermissionChanged, null, $meta))
        ->toThrow(InvalidArgumentException::class);
    expect(AuditEntry::count())->toBe(0);
})->with([
    'unknown key' => [['body' => 'secret']],
    'array value' => [['from' => ['x']]],
    'long string' => [['from' => str_repeat('a', 121)]],
]);

it('allows no meta for events without an allowlist', function () {
    expect(fn () => app(AuditLogger::class)->record(AuditEvent::LoginSuccess, null, ['email' => 'a@b.c']))
        ->toThrow(InvalidArgumentException::class);
});

it('maps events to filter groups', function () {
    expect(AuditEvent::LoginSuccess->group())->toBe('login')
        ->and(AuditEvent::Lockout->group())->toBe('login_failed')
        ->and(AuditEvent::InviteRevoked->group())->toBe('invite')
        ->and(AuditEvent::SessionRevokedAll->group())->toBe('session_revoked')
        ->and(AuditEvent::AccessDenied->group('finance'))->toBe('finance')
        ->and(AuditEvent::AccessDenied->group('health'))->toBe('health')
        ->and(AuditEvent::GoogleLinked->group())->toBe('account');
});

it('refuses to update or delete a model row', function () {
    app(AuditLogger::class)->record(AuditEvent::LoginSuccess, null);
    $row = AuditEntry::sole();

    expect(function () use ($row) {
        $row->event = 'x';
        $row->save();
    })->toThrow(LogicException::class);
    expect(fn () => $row->delete())->toThrow(LogicException::class);
});
