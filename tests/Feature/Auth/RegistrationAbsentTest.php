<?php

it('has no registration route', function () {
    $this->get('/register')->assertNotFound();
    $this->post('/register', [])->assertNotFound();
});

it('has no two-factor challenge route', function () {
    $this->get('/two-factor-challenge')->assertNotFound();
});

it('enables no Fortify feature in this phase', function () {
    expect(config('fortify.features'))->toBe([]);
});
