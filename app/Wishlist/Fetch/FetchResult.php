<?php

namespace App\Wishlist\Fetch;

final class FetchResult
{
    public function __construct(
        public readonly string $body,
        public readonly string $finalUrl,
        public readonly int $status,
        public readonly ?string $contentType = null,
    ) {}
}
