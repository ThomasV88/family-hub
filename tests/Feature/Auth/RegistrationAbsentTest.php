<?php

use Laravel\Fortify\Features;

it('has no registration route', function () {
    $this->get('/register')->assertNotFound();
    $this->post('/register', [])->assertNotFound();
});

it('has the two-factor challenge route, which sends a visitor without a pending login to sign in', function () {
    $this->get('/two-factor-challenge')->assertRedirect('/login'); // challenge exists, no pending login
});

it('enables only the password reset and two-factor Fortify features', function () {
    expect(config('fortify.features'))->toBe([Features::resetPasswords(), Features::twoFactorAuthentication()]);
});
