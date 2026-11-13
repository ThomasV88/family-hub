<?php

use Laravel\Fortify\Features;

it('has no registration route', function () {
    $this->get('/register')->assertNotFound();
    $this->post('/register', [])->assertNotFound();
});

it('has no two-factor challenge route', function () {
    $this->get('/two-factor-challenge')->assertNotFound();
});

it('enables only the password reset Fortify feature', function () {
    expect(config('fortify.features'))->toBe([Features::resetPasswords()]);
});
