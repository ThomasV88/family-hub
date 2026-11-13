<?php

namespace Tests;

use App\Security\SensitiveSession;
use App\Wishlist\Fetch\DnsResolver;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Support\AlwaysFreshSensitiveSession;
use Tests\Support\FakeDnsResolver;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // The test queue is sync, so a saved wish link runs FetchWishImage inline. Resolve every host to nothing so
        // no test can ever touch the network; tests that exercise the fetch swap in their own SafeFetcher.
        $this->app->bind(DnsResolver::class, fn () => new FakeDnsResolver);

        // The sensitive idle gate would break every existing finance test; default to always fresh.
        $this->app->scoped(SensitiveSession::class, fn () => new AlwaysFreshSensitiveSession);
    }

    /** Opt in to the real gate: tests then drive it with withSession(['sensitive.active_at' => ...]). */
    protected function useRealSensitiveSession(): void
    {
        $this->app->scoped(SensitiveSession::class, fn () => new SensitiveSession);
    }
}
