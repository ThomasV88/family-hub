<?php

namespace Tests\Support;

use App\Wishlist\Fetch\IpPolicy;

/** Test-only: lets the real-socket tests reach 127.0.0.1 and nothing else. Never referenced from app/. */
final class AllowLoopbackIpPolicy implements IpPolicy
{
    public function allows(string $ip): bool
    {
        return $ip === '127.0.0.1';
    }
}
