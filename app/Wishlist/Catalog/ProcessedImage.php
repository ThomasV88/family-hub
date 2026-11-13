<?php

namespace App\Wishlist\Catalog;

/** Result of the image pipeline: two metadata-free JPEGs and the dimensions of the full-size one. */
final class ProcessedImage
{
    public function __construct(
        public readonly string $fullBytes,
        public readonly string $thumbBytes,
        public readonly int $width,
        public readonly int $height,
    ) {}
}
