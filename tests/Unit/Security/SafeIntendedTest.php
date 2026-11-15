<?php

use App\Security\SafeIntended;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;

function intendedRequest(): Request
{
    $request = Request::create('http://hub.test/x');
    $request->setLaravelSession(new Store('t', new ArraySessionHandler(10)));

    return $request;
}

it('keeps same-origin relative paths', function () {
    expect(SafeIntended::path('/finance?month=2026-03', 'hub.test'))->toBe('/finance?month=2026-03')
        ->and(SafeIntended::path('http://hub.test/finance/transactions?x=1', 'hub.test'))->toBe('/finance/transactions?x=1');
});

it('rejects attack strings and auth paths', function (?string $url) {
    expect(SafeIntended::path($url, 'hub.test'))->toBe('/');
})->with([
    'https://evil.example/x', '//evil.example', '/\\evil', 'javascript:alert(1)', '', null, 'finance',
    'https://hub.test@evil.example/x', '/confirm-access', '/login', '/two-factor-challenge', '/auth/google/callback', '/Login',
    "/ok\r\nLocation: x",
]);

it('validates on write and on read and peek does not remove', function () {
    $r = intendedRequest();
    SafeIntended::remember($r, 'https://evil.example/x');
    expect($r->session()->get('url.intended'))->toBe('/');

    SafeIntended::remember($r, '/finance');
    expect(SafeIntended::peek($r))->toBe('/finance')->and(SafeIntended::peek($r))->toBe('/finance');

    $r->session()->put('url.intended', 'https://evil.example/');
    expect(SafeIntended::pull($r))->toBe('/')->and(SafeIntended::peek($r))->toBeNull();
    expect(SafeIntended::pull($r))->toBe('/');
});
