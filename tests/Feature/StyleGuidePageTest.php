<?php

use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->originalEnv = app()->environment();
});

afterEach(function () {
    app()['env'] = $this->originalEnv;
});

it('renders the StyleGuide component in the testing environment', function () {
    $this->get('/_style')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('StyleGuide'));

    expect(route('style', absolute: false))->toBe('/_style');
});

it('returns 200 on staging and local', function (string $env) {
    app()['env'] = $env;

    $this->get('/_style')->assertOk();
})->with(['staging', 'local']);

it('returns 404 in production', function () {
    app()['env'] = 'production';

    $this->get('/_style')->assertNotFound();
});

it('returns 404 in environments outside the allowlist', function (string $env) {
    app()['env'] = $env;

    $this->get('/_style')->assertNotFound();
})->with(['development', 'qa', 'stage']);

it('shares a styleGuide flag derived from the same allowlist', function (string $env, bool $expected) {
    app()['env'] = $env;

    $this->get('/')->assertInertia(fn (Assert $page) => $page->where('app.styleGuide', $expected));
})->with([['local', true], ['staging', true], ['production', false], ['qa', false]]);
