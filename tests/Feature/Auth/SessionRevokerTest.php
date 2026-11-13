<?php

use App\Auth\SessionRevoker;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function sessionRow(string $id, ?int $userId): void
{
    DB::table('sessions')->insert(['id' => $id, 'user_id' => $userId, 'ip_address' => '127.0.0.1', 'user_agent' => 'x', 'payload' => 'x', 'last_activity' => time()]);
}

it('revokeAll deletes every session of the user and rotates the remember token', function () {
    $user = User::factory()->create(['remember_token' => 'old-token']);
    $other = User::factory()->create();
    sessionRow('a', $user->id);
    sessionRow('b', $user->id);
    sessionRow('c', $other->id);

    $deleted = app(SessionRevoker::class)->revokeAll($user);

    expect($deleted)->toBe(2)
        ->and(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(0)
        ->and(DB::table('sessions')->where('user_id', $other->id)->count())->toBe(1)
        ->and($user->fresh()->remember_token)->not->toBe('old-token')
        ->and(strlen($user->fresh()->remember_token))->toBe(60);
});

it('revokeOne deletes only the row matching both id and user_id', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    sessionRow('mine', $user->id);
    sessionRow('theirs', $other->id);

    expect(app(SessionRevoker::class)->revokeOne($user, 'theirs'))->toBeFalse()
        ->and(DB::table('sessions')->where('id', 'theirs')->exists())->toBeTrue();

    expect(app(SessionRevoker::class)->revokeOne($user, 'mine'))->toBeTrue()
        ->and(DB::table('sessions')->where('id', 'mine')->exists())->toBeFalse();
});

it('revokeOne also rotates the remember token', function () {
    $user = User::factory()->create(['remember_token' => 'old-token']);
    sessionRow('mine', $user->id);

    app(SessionRevoker::class)->revokeOne($user, 'mine');

    expect($user->fresh()->remember_token)->not->toBe('old-token');
});
