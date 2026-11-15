<?php

use App\Models\User;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Route::middleware('web')->group(function () {
        Route::get('/test-probe/denied', fn () => 'ok')->middleware('access:admin')->name('test.probe.denied');
        Route::post('/test-probe/csrf', fn () => throw new TokenMismatchException)->middleware('access:public')->name('test.probe.csrf');
    });
});

it('renders the Forbidden Inertia component with status 403', function () {
    $this->actingAs(User::factory()->create())
        ->get('/test-probe/denied')
        ->assertForbidden()
        ->assertInertia(fn (Assert $page) => $page->component('Errors/Forbidden', false));
});

it('keeps JSON clients on a plain 403', function () {
    $this->actingAs(User::factory()->create())
        ->getJson('/test-probe/denied')
        ->assertForbidden()
        ->assertJsonMissingPath('component');
});

it('redirects an expired CSRF token to login with session_ended', function () {
    $this->post('/test-probe/csrf')->assertRedirect('/login')->assertSessionHas('status', 'session_ended');
});

it('flashes session_ended for a guest whose session id no longer exists', function () {
    $this->withCookie(config('session.cookie'), 'stale-session-id-that-does-not-exist')
        ->get('/')
        ->assertRedirect('/login')
        ->assertSessionHas('status', 'session_ended');
});

it('does not flash anything for a fresh guest', function () {
    $this->get('/')->assertRedirect('/login')->assertSessionMissing('status');
});
