<?php

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

function rawUser(array $overrides = []): array
{
    return array_merge([
        'name' => 'Raw',
        'email' => 'raw@example.test',
        'password' => 'x',
        'password_set' => false,
        'sign_in_disabled' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides);
}

it('lets any number of children without e-mail coexist', function () {
    User::factory()->child()->count(2)->create();

    expect(User::whereNull('email')->count())->toBe(2);
});

it('refuses an empty-string e-mail', function () {
    expect(fn () => DB::table('users')->insert(rawUser(['email' => ''])))->toThrow(QueryException::class, 'users_email_not_blank');
});

it('refuses a user without e-mail unless it is a credential-free, sign-in-disabled child', function (array $overrides) {
    expect(fn () => DB::table('users')->insert(rawUser(['email' => null] + $overrides)))
        ->toThrow(QueryException::class, 'users_child_no_credentials');
})->with([
    'sign-in not disabled' => [['sign_in_disabled' => false]],
    'google id' => [['sign_in_disabled' => true, 'google_id' => '123']],
    'password set' => [['sign_in_disabled' => true, 'password_set' => true]],
    'totp secret' => [['sign_in_disabled' => true, 'two_factor_secret' => 'secret']],
]);

it('allows a user with e-mail and sign_in_disabled', function () {
    $user = User::factory()->create(['sign_in_disabled' => true]);

    expect($user->fresh()->sign_in_disabled)->toBeTrue();
});

it('still refuses two members with the same e-mail in different letter case', function () {
    User::factory()->create(['email' => 'A@example.test']);

    expect(fn () => User::factory()->create(['email' => 'a@example.test']))->toThrow(QueryException::class);
});

it('answers canSignIn for members, children and disabled rows', function () {
    expect(User::factory()->make()->canSignIn())->toBeTrue()
        ->and(User::factory()->child()->make()->canSignIn())->toBeFalse()
        ->and(User::factory()->make(['sign_in_disabled' => true])->canSignIn())->toBeFalse();
});

it('routes mail only for a user who can sign in', function () {
    $member = User::factory()->make(['email' => 'm@example.test']);

    expect($member->routeNotificationForMail())->toBe('m@example.test')
        ->and(User::factory()->child()->make()->routeNotificationForMail())->toBeNull()
        ->and(User::factory()->make(['sign_in_disabled' => true])->routeNotificationForMail())->toBeNull();
});

it('sends no password reset mail to a child', function () {
    Notification::fake();
    $child = User::factory()->child()->create();

    $child->sendPasswordResetNotification('t');

    Notification::assertNothingSent();
});

it('never serializes photo paths', function () {
    $user = User::factory()->create();
    $user->forceFill(['photo_path' => 'a/b.jpg', 'photo_thumb_path' => 'a/b-thumb.jpg'])->save();

    $array = $user->fresh()->toArray();

    expect($array)->not->toHaveKey('photo_path')->not->toHaveKey('photo_thumb_path')
        ->and(json_encode($user->fresh()))->not->toContain('b.jpg');
});

it('rolls back and forward, refusing while a child exists', function () {
    $path = 'database/migrations/2026_11_26_100000_add_child_and_photo_columns_to_users.php';
    $child = User::factory()->child()->create();

    expect(fn () => $this->artisan('migrate:rollback', ['--path' => $path, '--force' => true])->run())
        ->toThrow(RuntimeException::class, 'Remove child accounts');

    $child->delete();
    $this->artisan('migrate:rollback', ['--path' => $path, '--force' => true])->assertExitCode(0);

    $nullable = fn () => DB::selectOne("select is_nullable from information_schema.columns where table_name = 'users' and column_name = 'email'")->is_nullable;
    expect($nullable())->toBe('NO');

    $this->artisan('migrate', ['--path' => $path, '--force' => true])->assertExitCode(0);
    expect($nullable())->toBe('YES')
        ->and(DB::selectOne("select count(*) c from pg_constraint where conname in ('users_child_no_credentials','users_email_not_blank')")->c)->toBe(2)
        ->and(DB::selectOne("select count(*) c from information_schema.columns where table_name = 'invitations' and column_name = 'user_id'")->c)->toBe(1);
});
