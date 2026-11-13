<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('marks the home page response no-store and private', function () {
    $cc = (string) $this->actingAs(User::factory()->create())->get('/')->headers->get('Cache-Control');
    expect($cc)->toContain('no-store')->toContain('private');
});

it('marks the locale redirect no-store', function () {
    $response = $this->post('/locale', ['locale' => 'pl']);
    expect((string) $response->headers->get('Cache-Control'))->toContain('no-store');
});

it('marks the heartbeat redirect no-store', function () {
    $response = $this->actingAs(User::factory()->create())->post('/heartbeat');
    expect((string) $response->headers->get('Cache-Control'))->toContain('no-store');
});

it('marks the login page no-store and private', function () {
    $cc = (string) $this->get('/login')->headers->get('Cache-Control');
    expect($cc)->toContain('no-store')->toContain('private');
});
