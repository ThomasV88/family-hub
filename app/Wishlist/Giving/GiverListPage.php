<?php

namespace App\Wishlist\Giving;

use App\Access\AccessGate;
use App\Access\AccessLevel;
use App\Access\Module;
use App\Models\User;
use App\Models\Wish;
use App\Models\WishlistGroup;
use App\Models\WishReservation;
use App\Wishlist\Catalog\ListPage;
use App\Wishlist\Groups\GroupAccess;
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
        private readonly GroupAccess $groups,
    ) {}

    public function render(User $owner, User $viewer, Request $request): Response
    {
        $this->guard->assertGiver(ViewerContext::for($viewer), $owner->id);

        return Inertia::render('Wishlist/Index', $this->decorate($this->catalog->props($owner, $viewer, $request), $viewer));
    }

    /** Group list for a viewer on the giver side: gifts-mode non-members, and members of a shared-mode list. */
    public function renderGroup(WishlistGroup $group, User $viewer, Request $request): Response
    {
        abort_unless($this->groups->isGiverSide($viewer, $group), 403);
        $this->guard->assertGiverOfWish(ViewerContext::for($viewer), (new Wish)->forceFill(['group_id' => $group->id]));

        return Inertia::render('Wishlist/Index', $this->decorate($this->catalog->groupProps($group, $viewer, $request), $viewer));
    }

    /**
     * @param  array<string, mixed>  $props
     * @return array<string, mixed>
     */
    private function decorate(array $props, User $viewer): array
    {
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

        return $props;
    }
}
