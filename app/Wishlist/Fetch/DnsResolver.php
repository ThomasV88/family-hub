<?php

namespace App\Wishlist\Fetch;

interface DnsResolver
{
    /** @return list<string> IPv4 addresses (A records) of the final name; empty when none */
    public function resolveA(string $host): array;
}
