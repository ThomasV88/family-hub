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
