<?php

use App\Wishlist\Fetch\SafeFetcher;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
use Tests\Support\FakeDnsResolver;

function sfuaAgent(?string $agent, bool $pass): string
{
    $handler = new MockHandler([new Response(200, ['Content-Type' => 'text/html'], '<html></html>')]);
    $dns = new FakeDnsResolver(['shop.example' => ['93.184.216.34']]);
    $fetcher = $pass ? new SafeFetcher($dns, null, $handler, 3, [80, 443], $agent) : new SafeFetcher($dns, null, $handler);
    $fetcher->get('https://shop.example/x', 1000, 'html');

    return $handler->getLastRequest()->getHeaderLine('User-Agent');
}

it('keeps the wishlist user agent by default', function () {
    expect(sfuaAgent(null, false))->toBe('FamilyHubImageFetcher/1.0')
        ->and(sfuaAgent(null, true))->toBe('FamilyHubImageFetcher/1.0');
});

it('sends a custom user agent when one is given', function () {
    expect(sfuaAgent('FamilyHubRecipeFetcher/1.0', true))->toBe('FamilyHubRecipeFetcher/1.0');
});
