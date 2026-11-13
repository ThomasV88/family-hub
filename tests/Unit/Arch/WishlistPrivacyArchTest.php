<?php

// Owner-side code must never touch reservations. Namespaces that do not exist yet match nothing.
arch('owner side is blind to reservations')
    ->expect([
        'App\Wishlist\Catalog',
        'App\Wishlist\Fetch',
        'App\Wishlist\Occasions',
        'App\Http\Controllers\Wishlist\Owner',
        'App\Wishlist\ViewerContext',
        'App\Wishlist\WishAccess',
        'App\Wishlist\WishlistMembers',
        'App\Models\Wish',
        'App\Models\WishUrl',
        'App\Models\WishlistManager',
    ])
    ->not->toUse([
        'App\Models\WishReservation',
        'App\Models\WishContribution',
        'App\Wishlist\Giving',
    ]);
