<?php

use App\Wishlist\Catalog\SharedLink;
use Tests\TestCase;

uses(TestCase::class);

it('uses a valid url param', function () {
    expect(SharedLink::from('Shoes', null, 'https://shop.example/p/1?a=b'))->toBe(['url' => 'https://shop.example/p/1?a=b', 'title' => 'Shoes']);
});

it('falls back to the first link in the text and strips it from the title', function () {
    $r = SharedLink::from(null, "Nice  shoes https://shop.example/p/1.\n", 'not a url');
    expect($r)->toBe(['url' => 'https://shop.example/p/1', 'title' => 'Nice shoes']);
});

it('strips trailing punctuation from a link in the text', function () {
    expect(SharedLink::from(null, 'see (https://shop.example/p/1), ok', null)['url'])->toBe('https://shop.example/p/1');
});

it('keeps the title param over the text', function () {
    expect(SharedLink::from('Given', 'Other https://shop.example/x', null)['title'])->toBe('Given');
});

it('prefills only a title when there is no link', function () {
    expect(SharedLink::from(null, 'just some words', null))->toBe(['url' => null, 'title' => 'just some words']);
});

it('refuses non-web schemes', function (string $url) {
    expect(SharedLink::from('T', null, $url)['url'])->toBeNull();
})->with(['javascript:alert(1)', 'data:text/html,hi', 'ftp://shop.example/x', 'file:///etc/passwd']);

it('refuses credentials and over-long urls', function () {
    expect(SharedLink::from('T', null, 'https://user:pass@shop.example/x')['url'])->toBeNull();
    expect(SharedLink::from('T', 'https://user@shop.example/x', null)['url'])->toBeNull();
    expect(SharedLink::from('T', null, 'https://shop.example/'.str_repeat('a', 2048))['url'])->toBeNull();
});

it('trims the title to the limit and strips control characters', function () {
    config(['wishlist.title_max' => 10]);
    expect(SharedLink::from("Ab\x00c\x07d ".str_repeat('z', 50), null, null)['title'])->toBe('Abcd zzzzz');
});
