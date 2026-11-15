<?php

namespace Tests\Support;

use App\Wishlist\Fetch\DnsResolver;

final class FakeDnsResolver implements DnsResolver
{
    /** @var array<string, int> */
    public array $calls = [];

    /** @param array<string, list<string>> $map */
    public function __construct(private array $map = []) {}

    public function resolveA(string $host): array
    {
        $this->calls[$host] = ($this->calls[$host] ?? 0) + 1;

        return $this->map[$host] ?? [];
    }

    public function total(): int
    {
        return array_sum($this->calls);
    }
}
