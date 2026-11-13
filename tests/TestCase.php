<?php

namespace Tests;

use App\Wishlist\Fetch\DnsResolver;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Support\FakeDnsResolver;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // The test queue is sync, so a saved wish link runs FetchWishImage inline. Resolve every host to nothing so
        // no test can ever touch the network; tests that exercise the fetch swap in their own SafeFetcher.
        $this->app->bind(DnsResolver::class, fn () => new FakeDnsResolver);
    }
}
