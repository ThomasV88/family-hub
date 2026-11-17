<?php

use App\Recipes\Import\ImportUrl;

it('normalises scheme, host and fragment and keeps path and query', function () {
    expect(ImportUrl::normalise(' HTTPS://Recepty.Example/a?b=1#x '))->toBe('https://recepty.example/a?b=1')
        ->and(ImportUrl::normalise('http://recepty.example'))->toBe('http://recepty.example')
        ->and(ImportUrl::normalise('https://recepty.example:443/x'))->toBe('https://recepty.example:443/x')
        ->and(ImportUrl::normalise('http://Příklad.example/x'))->toBe('http://xn--pklad-zsa96e.example/x');
});

it('refuses anything that is not a plain http or https address', function (string $input) {
    expect(ImportUrl::normalise($input))->toBeNull();
})->with([
    'ftp' => 'ftp://x.example/a',
    'javascript' => 'javascript:alert(1)',
    'userinfo' => 'https://user:pw@x.example/',
    'user only' => 'https://user@x.example/',
    'no scheme' => 'recepty.example',
    'empty' => '   ',
    'inner space' => 'https://x.example/a b',
    'odd port' => 'https://recepty.example:8443/x',
    'localhost' => 'http://localhost/',
    'single label' => 'http://intranet/page',
    'ipv4 literal' => 'http://93.184.216.34/',
    'ipv6 literal' => 'http://[2001:db8::1]/',
    'decimal ip' => 'http://2130706433/',
    'trailing dot' => 'https://x.example./',
    'too long' => 'https://x.example/'.str_repeat('a', 2100),
]);

it('hashes the normalised url with sha256', function () {
    $url = ImportUrl::normalise('https://Recepty.Example/a#frag');

    expect(ImportUrl::hash($url))->toBe(hash('sha256', 'https://recepty.example/a'))->toHaveLength(64)
        ->and(ImportUrl::host($url))->toBe('recepty.example');
});
