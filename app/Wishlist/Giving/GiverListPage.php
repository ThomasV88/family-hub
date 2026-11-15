<?php

namespace App\Wishlist\Giving;

use App\Access\AccessGate;
use App\Access\AccessLevel;
use App\Access\Module;
use App\Models\User;
use App\Models\WishReservation;
use App\Wishlist\Catalog\ListPage;
use App\Wishlist\ViewerContext;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Props for a non-recipient viewer: the Catalog props plus who is buying what. Fail-closed for recipient-side viewers. */
final class GiverListPage
{
    public function __construct(
        private readonly ListPage $catalog,
        private readonly GivingGuard $guard,
        private readonly ReservationPresenter $presenter,
        private readonly AccessGate $gate,
    ) {}

    public function render(User $owner, User $viewer, Request $request): Response
    {
        $this->guard->assertGiver(ViewerContext::for($viewer), $owner->id);

        $props = $this->catalog->props($owner, $viewer, $request);
        $wishes = $props['list']['wishes'];

        $reservations = WishReservation::query()
            ->whereIn('wish_id', array_column($wishes, 'id'))
            ->with(['user:id,name', 'contributions.user:id,name'])
            ->get()
            ->keyBy('wish_id');

        $decorated = array_map(function (array $wish) use ($reservations, $viewer) {
            $wish['reservation'] = $this->presenter->present($reservations->get($wish['id']), $viewer);

            return $wish;
        }, $wishes);

        // Available first, reserved after; each group keeps the Catalog order (the sort is stable).
        $available = array_filter($decorated, fn (array $w) => $w['reservation'] === null);
        $reserved = array_filter($decorated, fn (array $w) => $w['reservation'] !== null);

        $props['list']['wishes'] = array_values([...$available, ...$reserved]);
        $props['list']['viewer_can_reserve'] = $this->gate->allows($viewer, Module::Wishlist, AccessLevel::Edit);

        return Inertia::render('Wishlist/Index', $props);
    }
}
