<?php

use Inertia\Testing\AssertableInertia as Assert;

it('stores a supported locale in a long-lived cookie and redirects back', function () {
    $response = $this->post('/locale', ['locale' => 'pl']);

    $response->assertStatus(303);
    $response->assertCookie('locale', 'pl');
    $cookie = collect($response->headers->getCookies())->first(fn ($c) => $c->getName() === 'locale');
    expect($cookie)->not->toBeNull();
    expect($cookie->isHttpOnly())->toBeTrue();
    expect($cookie->getSameSite())->toBe('lax');
    expect($cookie->getExpiresTime() - time())->toBeGreaterThan(60 * 60 * 24 * 360);
});

it('rejects an unsupported locale and sets no cookie', function () {
    $response = $this->post('/locale', ['locale' => 'de']);

    $response->assertSessionHasErrors('locale');
    $response->assertCookieMissing('locale');

    $json = $this->postJson('/locale', ['locale' => 'de']);
    $json->assertStatus(422);
});

it('uses the locale cookie and exposes messages and html lang', function () {
    $response = $this->withCookie('locale', 'sk')->get('/');

    $response->assertInertia(fn (Assert $page) => $page
        ->where('locale', 'sk')
        ->where('availableLocales', ['sk', 'pl', 'en'])
        ->has('messages.home.title')
    );
    expect($response->getContent())->toContain('<html lang="sk"');
});

it('picks the locale from Accept-Language when there is no cookie', function () {
    $this->withHeader('Accept-Language', 'pl-PL,pl;q=0.9,en;q=0.8')->get('/')
        ->assertInertia(fn (Assert $page) => $page->where('locale', 'pl'));

    $this->withHeader('Accept-Language', 'de-DE')->get('/')
        ->assertInertia(fn (Assert $page) => $page->where('locale', 'en'));
});

it('lets the cookie win over Accept-Language', function () {
    $this->withCookie('locale', 'sk')
        ->withHeader('Accept-Language', 'pl-PL,pl;q=0.9')->get('/')
        ->assertInertia(fn (Assert $page) => $page->where('locale', 'sk'));
});

it('ignores a tampered locale cookie value', function () {
    $this->withCookie('locale', '../../etc/passwd')->get('/')
        ->assertInertia(fn (Assert $page) => $page->where('locale', 'en'));
});
