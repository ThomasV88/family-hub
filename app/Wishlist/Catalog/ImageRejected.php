<?php

namespace App\Wishlist\Catalog;

use RuntimeException;

/** Carries only a machine reason: too_large, too_many_pixels or unsupported_type. */
final class ImageRejected extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct('image_rejected:'.$reason);
    }
}
