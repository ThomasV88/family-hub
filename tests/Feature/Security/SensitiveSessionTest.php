<?php

use App\Security\SensitiveSession;
use Tests\Support\AlwaysFreshSensitiveSession;

function realSession(): SensitiveSession
{
    $request = request();
    $request->setLaravelSession(app('session.store'));

    return new SensitiveSession;
}

it('tracks the idle window', function () {
    $this->useRealSensitiveSession();
    $s = realSession();
    expect($s->fresh())->toBeFalse();
    $s->touch();
    $this->travel(14 * 60 + 59)->seconds();
    expect($s->fresh())->toBeTrue();
    $this->travel(2)->seconds();
    expect($s->fresh())->toBeFalse();
});

it('confirm refreshes both windows and the confirm window is 10 minutes', function () {
    $this->useRealSensitiveSession();
    $s = realSession();
    expect($s->recentlyConfirmed())->toBeFalse();
    $s->confirm();
    expect($s->fresh())->toBeTrue()->and($s->recentlyConfirmed())->toBeTrue();
    $this->travel(10 * 60 + 1)->seconds();
    expect($s->recentlyConfirmed())->toBeFalse()->and($s->fresh())->toBeTrue();
});

it('honours config overrides', function () {
    $this->useRealSensitiveSession();
    config(['security.idle_seconds' => 5, 'security.confirm_window_seconds' => 3]);
    $s = realSession();
    $s->confirm();
    $this->travel(4)->seconds();
    expect($s->recentlyConfirmed())->toBeFalse()->and($s->fresh())->toBeTrue();
    $this->travel(2)->seconds();
    expect($s->fresh())->toBeFalse();
});

it('binds the always-fresh double by default and the real class on demand', function () {
    expect(app(SensitiveSession::class))->toBeInstanceOf(AlwaysFreshSensitiveSession::class);
    $this->useRealSensitiveSession();
    expect(app(SensitiveSession::class))->not->toBeInstanceOf(AlwaysFreshSensitiveSession::class)
        ->toBeInstanceOf(SensitiveSession::class);
});
