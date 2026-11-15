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

// The reverse direction: nothing outside the giving side may even mention the reservation models.
arch('reservation models are used only by the giving side')
    ->expect(['App\Models\WishReservation', 'App\Models\WishContribution'])
    ->toOnlyBeUsedIn([
        'App\Wishlist\Giving',
        'App\Http\Controllers\Wishlist\Giving',
        'App\Models\WishReservation',
        'App\Models\WishContribution',
        'Database\Factories',
        'Tests\Support',
    ]);

// Reminders and the home card say who and when, never what is reserved.
arch('reminders and the home card are blind to reservations')
    ->expect([
        'App\Notifications\Outbox\WishOccasionHandler',
        'App\Notifications\WishOccasionNotification',
        'App\Console\Commands\EnqueueOccasionReminders',
        'App\Http\Controllers\HomeController',
    ])
    ->not->toUse([
        'App\Models\WishReservation',
        'App\Models\WishContribution',
        'App\Wishlist\Giving',
    ]);
