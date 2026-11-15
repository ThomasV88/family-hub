<?php

namespace App\Wishlist\Fetch;

use RuntimeException;

/** Carries only a short machine reason; never a URL, header or body. */
final class FetchFailed extends RuntimeException
{
    public function __construct(public readonly string $reason, ?\Throwable $previous = null)
    {
        parent::__construct('fetch_failed:'.$reason, 0, $previous);
    }
}
