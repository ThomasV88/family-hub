<?php

namespace App\Wishlist\Fetch;

interface IpPolicy
{
    public function allows(string $ip): bool;
}
