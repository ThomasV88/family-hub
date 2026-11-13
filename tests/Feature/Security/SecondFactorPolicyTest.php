<?php

use App\Access\AccessLevel;
use App\Access\Module;
use App\Models\User;
use App\Security\AppSettings;
use App\Security\SecondFactorPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\Totp;

uses(RefreshDatabase::class);

function secondFactorPolicy(): SecondFactorPolicy
{
    return new SecondFactorPolicy(new AppSettings, new App\Access\AccessGate);
}

it('returns defaults, round-trips values and caches per instance', function () {
    $a = new AppSettings;
    expect($a->get('missing', 'dflt'))->toBe('dflt');

    $a->set('flag', true);
    $a->set('list', ['x' => [1, 2]]);
    expect($a->get('flag'))->toBeTrue()->and($a->get('list'))->toBe(['x' => [1, 2]]);

    DB::table('app_settings')->where('key', 'flag')->update(['value' => json_encode(false)]);
    expect($a->get('flag'))->toBeTrue();
    expect((new AppSettings)->get('flag'))->toBeFalse();

    $a->set('flag', false);
    expect((new AppSettings)->get('flag'))->toBeFalse();
});

it('is off by default', function () {
    expect(secondFactorPolicy()->required())->toBeFalse();
    secondFactorPolicy()->setRequired(true);
    expect(secondFactorPolicy()->required())->toBeTrue();
});

it('applies to members with finance or health view and nobody else', function () {
    $fin = User::factory()->withGrant(Module::Finance, AccessLevel::View)->create();
    $health = User::factory()->withGrant(Module::Health, AccessLevel::Edit)->create();
    $todo = User::factory()->withGrant(Module::Todo, AccessLevel::Edit)->withGrant(Module::Wishlist, AccessLevel::Edit)->create();
    $admin = User::factory()->admin()->create();

    $p = secondFactorPolicy();
    expect($p->appliesTo($fin))->toBeTrue()->and($p->appliesTo($health))->toBeTrue()
        ->and($p->appliesTo($todo))->toBeFalse()->and($p->appliesTo($admin))->toBeFalse();
});

it('requires enrolment only when on, applicable and without a factor', function () {
    $fin = User::factory()->withGrant(Module::Finance, AccessLevel::View)->create();
    $done = User::factory()->withGrant(Module::Finance, AccessLevel::View)->create();
    Totp::enable($done);
    $todo = User::factory()->withGrant(Module::Todo, AccessLevel::Edit)->create();

    expect(secondFactorPolicy()->mustEnrol($fin))->toBeFalse();

    secondFactorPolicy()->setRequired(true);
    $p = secondFactorPolicy();
    expect($p->mustEnrol($fin))->toBeTrue()->and($p->mustEnrol($done->fresh()))->toBeFalse()->and($p->mustEnrol($todo))->toBeFalse();
});

it('counts pending members independent of the switch', function () {
    User::factory()->withGrant(Module::Finance, AccessLevel::View)->count(2)->create();
    $pk = User::factory()->withGrant(Module::Health, AccessLevel::View)->create();
    DB::table('passkeys')->insert(['user_id' => $pk->id, 'name' => 'p', 'credential_id' => 'c1', 'credential' => '{}', 'created_at' => now(), 'updated_at' => now()]);
    User::factory()->create();

    expect(secondFactorPolicy()->pendingCount())->toBe(2);
});
