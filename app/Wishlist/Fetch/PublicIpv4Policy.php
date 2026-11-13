<?php

namespace App\Wishlist\Fetch;

use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Explicit IPv4 denylist. PHP's built-in private and reserved range filter flags miss CGNAT, benchmark,
 * multicast and documentation ranges, so they are deliberately not used. IPv6 is rejected wholesale
 * (covers ::ffff:127.0.0.1, NAT64 and 6to4 tricks); the fetcher pins IPv4 only.
 */
final class PublicIpv4Policy implements IpPolicy
{
    public const DENY_V4 = [
        '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16', '172.16.0.0/12',
        '192.0.0.0/24', '192.0.2.0/24', '192.88.99.0/24', '192.168.0.0/16', '198.18.0.0/15',
        '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4', '255.255.255.255/32',
    ];

    public function allows(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return false;
        }

        return ! IpUtils::checkIp($ip, self::DENY_V4);
    }
}
