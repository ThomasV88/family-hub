<?php

use App\Access\AccessLevel;
use App\Access\Module;
use App\Audit\AuditEvent;
use App\Audit\AuditLogger;
use App\Models\AuditEntry;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

function memberWith(string $module, AccessLevel $level): User
{
    $user = User::factory()->create();
    $user->modulePermissions()->updateOrCreate(['module' => $module], ['level' => $level]);

    return $user;
}

beforeEach(function () {
    $this->withoutMiddleware(PreventRequestForgery::class);
    Route::middleware('web')->get('/probe/health', fn () => 'ok')->middleware('access:module,health,view');
    Route::middleware('web')->post('/probe/finance', fn () => 'ok')->middleware('access:module,finance,edit');
    Route::middleware('web')->get('/probe/todo', fn () => 'ok')->middleware('access:module,todo,view');
});

it('records health_read for an allowed GET with module and IP', function () {
    $user = memberWith('health', AccessLevel::View);

    $this->actingAs($user)->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])->get('/probe/health')->assertOk();

    $row = AuditEntry::sole();
    expect($row->event)->toBe('health_read')->and($row->module)->toBe('health')
        ->and($row->actor_id)->toBe($user->id)->and($row->ip)->toBe('203.0.113.7')->and($row->meta)->toBeNull();
});

it('records finance_write for an allowed POST', function () {
    $this->actingAs(memberWith('finance', AccessLevel::Edit))->post('/probe/finance')->assertOk();

    expect(AuditEntry::sole()->event)->toBe('finance_write');
});

it('records access_denied with the required level for a denied audited module', function () {
    $user = memberWith('health', AccessLevel::None);

    $this->actingAs($user)->get('/probe/health')->assertForbidden();

    $row = AuditEntry::sole();
    expect($row->event)->toBe('access_denied')->and($row->module)->toBe('health')->and($row->meta)->toBe(['level' => 'view']);
});

it('does not audit modules that are not sensitive', function () {
    $this->actingAs(memberWith('todo', AccessLevel::None))->get('/probe/todo')->assertForbidden();
    $this->actingAs(memberWith('todo', AccessLevel::View))->get('/probe/todo')->assertOk();

    expect(AuditEntry::count())->toBe(0);
});

it('fails closed: a failing audit write returns 500 and the controller never runs', function () {
    $ran = false;
    Route::middleware('web')->get('/probe/health2', function () use (&$ran) {
        $ran = true;

        return 'ok';
    })->middleware('access:module,health,view');

    $this->app->bind(AuditLogger::class, fn () => new class extends AuditLogger
    {
        public function record(AuditEvent $event, ?User $actor, array $meta = [], ?User $target = null, ?Module $module = null): void
        {
            throw new RuntimeException('db down');
        }
    });

    $this->actingAs(memberWith('health', AccessLevel::View))->get('/probe/health2')->assertStatus(500);

    expect($ran)->toBeFalse();
});
