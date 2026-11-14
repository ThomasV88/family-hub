<?php

use App\Access\Preset;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;

/** @return array<string, mixed> the Inertia page object embedded in the HTML of a GET */
function fhHealthHistPage($response): array
{
    preg_match('#<script[^>]*data-page="app"[^>]*>(.*?)</script>#s', $response->getContent(), $m);
    expect($m)->not->toBeEmpty();

    return json_decode($m[1], true, flags: JSON_THROW_ON_ERROR);
}

beforeEach(function () {
    $this->withoutMiddleware(PreventRequestForgery::class);
});

it('clears browser history on the confirm-access page the idle gate lands on', function () {
    $user = User::factory()->withPreset(Preset::Parent)->create();

    $page = fhHealthHistPage($this->actingAs($user)->get('/confirm-access'));
    expect($page['component'])->toBe('Auth/ConfirmAccess')
        ->and($page['clearHistory'] ?? false)->toBeTrue();
});

it('encrypts browser history for authenticated pages', function () {
    $user = User::factory()->withPreset(Preset::Parent)->create();

    expect(fhHealthHistPage($this->actingAs($user)->get('/'))['encryptHistory'] ?? false)->toBeTrue();
    expect(fhHealthHistPage($this->actingAs($user)->get('/todo'))['encryptHistory'] ?? false)->toBeTrue();
});

it('tells the browser to clear history after logout, once', function () {
    $user = User::factory()->withPreset(Preset::Parent)->create();
    $this->actingAs($user)->post('/logout')->assertRedirect('/login');

    expect(fhHealthHistPage($this->get('/login'))['clearHistory'] ?? false)->toBeTrue();
    expect(fhHealthHistPage($this->get('/login'))['clearHistory'] ?? false)->toBeFalse();
});
