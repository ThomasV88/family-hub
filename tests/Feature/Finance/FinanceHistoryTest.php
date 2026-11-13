<?php

use App\Access\Preset;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;

/** @return array<string, mixed> the Inertia page object embedded in the HTML of a GET */
function fhHistPage($response): array
{
    preg_match('#<script[^>]*data-page="app"[^>]*>(.*?)</script>#s', $response->getContent(), $m);
    expect($m)->not->toBeEmpty();

    return json_decode($m[1], true, flags: JSON_THROW_ON_ERROR);
}

beforeEach(function () {
    $this->withoutMiddleware(PreventRequestForgery::class);
});


it('encrypts browser history for authenticated finance pages and not for the login page', function () {
    $user = User::factory()->withPreset(Preset::Parent)->create();

    $page = fhHistPage($this->actingAs($user)->get('/finance/transactions'));
    expect($page['encryptHistory'] ?? false)->toBeTrue();

    app('auth')->forgetGuards();
    $this->flushSession();
    $guest = fhHistPage($this->get('/login'));
    expect($guest['encryptHistory'] ?? false)->toBeFalse();
});

it('tells the browser to clear history on the page after logout', function () {
    $user = User::factory()->withPreset(Preset::Parent)->create();
    $this->actingAs($user)->post('/logout')->assertRedirect('/login');

    $page = fhHistPage($this->get('/login'));
    expect($page['clearHistory'] ?? false)->toBeTrue();

    // One-shot: the following page does not clear again.
    expect(fhHistPage($this->get('/login'))['clearHistory'] ?? false)->toBeFalse();
});
