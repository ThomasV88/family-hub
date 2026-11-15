<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;

uses(RefreshDatabase::class);

function recallerCookie($response)
{
    $name = Auth::guard('web')->getRecallerName();

    return collect($response->headers->getCookies())->first(fn ($c) => $c->getName() === $name);
}

it('sets a recaller cookie of about 30 days when remember is ticked', function () {
    User::factory()->create(['email' => 'anna@example.test']);

    $response = $this->post('/login', ['email' => 'anna@example.test', 'password' => 'password', 'remember' => 'on']);

    $cookie = recallerCookie($response);
    expect($cookie)->not->toBeNull();
    $days = ($cookie->getExpiresTime() - time()) / 86400;
    expect($days)->toBeGreaterThan(29)->and($days)->toBeLessThan(31);
});

it('sets no recaller cookie without remember', function () {
    User::factory()->create(['email' => 'anna@example.test']);

    $response = $this->post('/login', ['email' => 'anna@example.test', 'password' => 'password']);

    expect(recallerCookie($response))->toBeNull();
});

it('ends the session with the browser', function () {
    expect(config('session.expire_on_close'))->toBeTrue();
});
