<?php

namespace App\Wishlist\Fetch;

final class SystemDnsResolver implements DnsResolver
{
    public function resolveA(string $host): array
    {
        $records = @dns_get_record($host, DNS_A);
        if (! is_array($records)) {
            return [];
        }
        $ips = [];
        foreach ($records as $record) {
            if (isset($record['ip']) && is_string($record['ip'])) {
                $ips[] = $record['ip'];
            }
        }

        return $ips;
    }
}
