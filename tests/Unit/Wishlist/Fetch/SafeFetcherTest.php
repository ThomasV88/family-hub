<?php

use App\Wishlist\Fetch\FetchFailed;
use App\Wishlist\Fetch\PublicIpv4Policy;
use App\Wishlist\Fetch\SafeFetcher;
use App\Wishlist\Fetch\UrlResolver;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use Tests\Support\FakeDnsResolver;

function fetcher(array $dns, array $responses, ?array &$seen = null, int $hops = 3): SafeFetcher
{
    $seen = [];
    $mock = new MockHandler($responses);
    $handler = function (RequestInterface $req, array $opts) use ($mock, &$seen) {
        $seen[] = ['url' => (string) $req->getUri(), 'opts' => $opts];

        return $mock($req, $opts);
    };

    return new SafeFetcher(new FakeDnsResolver($dns), null, $handler, $hops);
}

function reasonOf(callable $fn): string
{
    try {
        $fn();
    } catch (FetchFailed $e) {
        return $e->reason;
    }

    return 'no-exception';
}

it('denies every private, reserved and IPv6 address', function (string $ip) {
    expect((new PublicIpv4Policy)->allows($ip))->toBeFalse();
})->with([
    '0.0.0.1', '10.1.2.3', '100.64.0.1', '127.0.0.1', '169.254.169.254', '172.16.0.1', '192.0.0.1', '192.0.2.1',
    '192.88.99.1', '192.168.1.1', '198.18.0.1', '198.51.100.1', '203.0.113.1', '224.0.0.1', '240.0.0.1',
    '255.255.255.255', '::1', '::ffff:127.0.0.1', '64:ff9b::7f00:1', '2002:7f00:1::', '2001:db8::1', 'garbage',
]);

it('allows a public IPv4 address', function () {
    expect((new PublicIpv4Policy)->allows('93.184.216.34'))->toBeTrue();
});

it('rejects hostile URLs before any DNS lookup', function (string $url) {
    $dns = new FakeDnsResolver(['a.example' => ['93.184.216.34']]);
    $f = new SafeFetcher($dns, null, new MockHandler([new Response(200)]));
    expect(reasonOf(fn () => $f->get($url, 1000, 'html')))->toBeIn(['bad_url', 'blocked_address'])
        ->and($dns->total())->toBe(0);
})->with([
    'javascript:alert(1)', 'file:///etc/passwd', 'ftp://a.example/x', 'data:text/html,x',
    'http://a.example:8080/', 'http://user:pass@a.example/', 'http://127.0.0.1/', 'http://[::1]/',
    'http://2130706433/', 'http://0x7f.1/', 'http://localhost/', 'http://intranet/', 'http://a.example./',
    'http://a.example.1/', '', "http://a.example/\x00", 'http://a.example/ x',
]);

it('converts punycode IDN hosts and fetches them', function () {
    $f = fetcher(['xn--bcher-kva.example' => ['93.184.216.34']], [new Response(200, [], 'ok')], $seen);
    $r = $f->get('http://bücher.example/p?q=1', 1000, 'html');
    expect($r->body)->toBe('ok')->and($seen[0]['url'])->toBe('http://xn--bcher-kva.example/p?q=1');
});

it('rejects a host with one private A record among public ones', function () {
    $f = fetcher(['a.example' => ['93.184.216.34', '10.0.0.5']], [new Response(200)]);
    expect(reasonOf(fn () => $f->get('http://a.example/', 1000, 'html')))->toBe('blocked_address');
});

it('rejects hosts without an A record (IPv6-only)', function () {
    $f = fetcher([], [new Response(200)]);
    expect(reasonOf(fn () => $f->get('http://v6only.example/', 1000, 'html')))->toBe('blocked_address');
});

it('pins the connection, disables proxies and redirects, and resolves once per hop', function () {
    $dns = new FakeDnsResolver(['shop.example' => ['93.184.216.34']]);
    $seen = [];
    $accept = null;
    $mock = new MockHandler([new Response(200, [], 'body')]);
    $f = new SafeFetcher($dns, null, function ($req, $opts) use ($mock, &$seen, &$accept) {
        $seen = $opts;
        $accept = $req->getHeaderLine('Accept');

        return $mock($req, $opts);
    });
    $f->get('https://shop.example/item', 1000, 'image');

    expect($seen['curl'][CURLOPT_RESOLVE])->toBe(['shop.example:443:93.184.216.34'])
        ->and($seen['allow_redirects'])->toBeFalse()
        ->and($seen['proxy'])->toBe('')
        ->and($seen['connect_timeout'])->toBe(3)
        ->and($seen['timeout'])->toBe(10)
        ->and($accept)->toBe('image/*')
        ->and($dns->calls)->toBe(['shop.example' => 1]);
});

it('uses the explicit port in the pin', function () {
    $seen = [];
    $mock = new MockHandler([new Response(200, [], 'x')]);
    $f = new SafeFetcher(new FakeDnsResolver(['a.example' => ['93.184.216.34']]), null, function ($req, $opts) use ($mock, &$seen) {
        $seen = $opts;

        return $mock($req, $opts);
    });
    $f->get('http://a.example:80/', 10, 'html');
    expect($seen['curl'][CURLOPT_RESOLVE])->toBe(['a.example:80:93.184.216.34']);
});

it('compares the connected IP with the pinned IP', function () {
    expect(SafeFetcher::connectedIpMatches('93.184.216.34', '93.184.216.34'))->toBeTrue()
        ->and(SafeFetcher::connectedIpMatches('93.184.216.34', '127.0.0.1'))->toBeFalse()
        ->and(SafeFetcher::connectedIpMatches('93.184.216.34', '::ffff:93.184.216.34'))->toBeFalse()
        ->and(SafeFetcher::connectedIpMatches('93.184.216.34', 'nope'))->toBeFalse();
});

it('follows redirects manually, re-resolving each hop and resolving relative Locations', function () {
    $dns = ['a.example' => ['93.184.216.34'], 'b.example' => ['93.184.216.35']];
    $f = fetcher($dns, [
        new Response(301, ['Location' => '/next']),
        new Response(302, ['Location' => 'https://b.example/final']),
        new Response(200, [], 'done'),
    ], $seen);
    $r = $f->get('http://a.example/start', 1000, 'html');

    expect($r->body)->toBe('done')->and($r->finalUrl)->toBe('https://b.example/final')
        ->and(array_column($seen, 'url'))->toBe(['http://a.example/start', 'http://a.example/next', 'https://b.example/final'])
        ->and($seen[2]['opts']['curl'][CURLOPT_RESOLVE])->toBe(['b.example:443:93.184.216.35']);
});

it('rejects redirects to unsafe targets', function (string $location, array $dns) {
    $f = fetcher(['a.example' => ['93.184.216.34']] + $dns, [new Response(302, ['Location' => $location]), new Response(200, [], 'leak')]);
    expect(reasonOf(fn () => $f->get('http://a.example/', 1000, 'html')))->toBeIn(['blocked_address', 'bad_url']);
})->with([
    'metadata' => ['http://169.254.169.254/latest', []],
    'private host' => ['http://internal.example/', ['internal.example' => ['10.0.0.5']]],
    'non http scheme' => ['ftp://a.example/x', []],
    'javascript' => ['javascript:alert(1)', []],
    'other port' => ['http://a.example:8080/', []],
]);

it('stops after the third redirect and on loops', function () {
    $dns = ['a.example' => ['93.184.216.34'], 'b.example' => ['93.184.216.35']];
    $r = fn () => new Response(302, ['Location' => 'http://b.example/']);
    $ok = fetcher($dns, [$r(), $r(), $r(), new Response(200, [], 'x')]);
    expect($ok->get('http://a.example/', 10, 'html')->body)->toBe('x');

    $f = fetcher($dns, [$r(), $r(), $r(), $r(), new Response(200)]);
    expect(reasonOf(fn () => $f->get('http://a.example/', 10, 'html')))->toBe('too_many_redirects');

    $loop = fetcher($dns, [
        new Response(302, ['Location' => 'http://b.example/']), new Response(302, ['Location' => 'http://a.example/']),
        new Response(302, ['Location' => 'http://b.example/']), new Response(302, ['Location' => 'http://a.example/']),
        new Response(200),
    ]);
    expect(reasonOf(fn () => $loop->get('http://a.example/', 10, 'html')))->toBe('too_many_redirects');
});

it('fails on non-200 statuses and oversize bodies', function () {
    $dns = ['a.example' => ['93.184.216.34']];
    expect(reasonOf(fn () => fetcher($dns, [new Response(404)])->get('http://a.example/', 10, 'html')))->toBe('http_status')
        ->and(reasonOf(fn () => fetcher($dns, [new Response(200, ['Content-Length' => '5000'], 'x')])->get('http://a.example/', 100, 'html')))->toBe('too_large')
        ->and(reasonOf(fn () => fetcher($dns, [new Response(200, [], str_repeat('x', 101))])->get('http://a.example/', 100, 'html')))->toBe('too_large');
});

it('resolves references per RFC 3986', function (string $base, string $ref, ?string $expected) {
    expect(UrlResolver::resolve($base, $ref))->toBe($expected);
})->with([
    ['http://a.example/b/c/d?q=1', 'g', 'http://a.example/b/c/g'],
    ['http://a.example/b/c/d?q=1', './g', 'http://a.example/b/c/g'],
    ['http://a.example/b/c/d?q=1', 'g/', 'http://a.example/b/c/g/'],
    ['http://a.example/b/c/d?q=1', '/g', 'http://a.example/g'],
    ['http://a.example/b/c/d?q=1', '//other.example/g', 'http://other.example/g'],
    ['https://a.example/b/c/d?q=1', '//other.example/g', 'https://other.example/g'],
    ['http://a.example/b/c/d?q=1', '?y', 'http://a.example/b/c/d?y'],
    ['http://a.example/b/c/d?q=1', '', 'http://a.example/b/c/d?q=1'],
    ['http://a.example/b/c/d?q=1', 'g#s', 'http://a.example/b/c/g'],
    ['http://a.example/b/c/d?q=1', '../g', 'http://a.example/b/g'],
    ['http://a.example/b/c/d?q=1', '../../../g', 'http://a.example/g'],
    ['http://a.example/b/c/d?q=1', 'g/../h', 'http://a.example/b/c/h'],
    ['http://a.example/b/c/d?q=1', 'https://x.example/', 'https://x.example/'],
    ['http://a.example/b/c/d?q=1', 'javascript:alert(1)', null],
    ['http://a.example/b/c/d?q=1', 'data:image/png;base64,AAAA', null],
    ['http://a.example/b/c/d?q=1', 'ftp://x.example/a', null],
]);
