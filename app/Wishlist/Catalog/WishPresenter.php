<?php

namespace App\Wishlist\Catalog;

use App\Models\Wish;
use App\Models\WishUrl;
use App\Wishlist\Money;

/** Explicit allowlist of what leaves the server for a wish. Never a model dump: new columns must not leak. */
final class WishPresenter
{
    /** @return array<string, mixed> */
    public function present(Wish $wish): array
    {
        return [
            'id' => $wish->id,
            'title' => $wish->title,
            'notes' => $wish->notes,
            'price' => Money::toDecimal($wish->price_minor),
            'currency' => $wish->price_currency,
            'size_notes' => $wish->size_notes,
            'priority' => $wish->priority->key(),
            'urls' => $wish->urls->map(fn (WishUrl $u) => [
                'id' => $u->id,
                'url' => $u->url,
                'label' => $u->label,
                'host' => self::host($u->url),
            ])->values()->all(),
            'image' => [
                'status' => $wish->image_status,
                'thumb_url' => null,
                'full_url' => null,
                'source' => $wish->image_source,
                'host' => $wish->image_source_host,
            ],
            'received' => $wish->received_at !== null,
        ];
    }

    public static function host(string $url): ?string
    {
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? strtolower($host) : null;
    }
}
