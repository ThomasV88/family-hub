<?php

namespace App\Wishlist\Catalog;

use App\Models\User;
use App\Models\Wish;
use App\Wishlist\ViewerContext;
use App\Wishlist\WishAccess;
use App\Wishlist\WishlistMembers;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/** Reservation-blind props for /wishlist: what the recipient side (and, for now, everyone) sees. */
final class ListPage
{
    public function __construct(
        private readonly WishPresenter $presenter,
        private readonly WishAccess $access,
        private readonly WishlistMembers $members,
    ) {}

    public function render(User $owner, User $viewer, Request $request): Response
    {
        return Inertia::render('Wishlist/Index', $this->props($owner, $viewer, $request));
    }

    /**
     * The reservation-blind props. The giver branch (Giving\GiverListPage) decorates these; this class never decorates.
     *
     * @return array<string, mixed>
     */
    public function props(User $owner, User $viewer, Request $request): array
    {
        $context = ViewerContext::for($viewer);
        $recipient = $context->isRecipientSideOf($owner->id);
        $canEdit = $this->access->canEditList($viewer, $owner->id);

        $wishes = Wish::query()->with('urls')
            ->where('owner_id', $owner->id)
            ->whereNull('received_at')
            ->orderByDesc('priority')->orderByDesc('created_at')->orderByDesc('id')
            ->get();

        $list = [
            'owner' => ['id' => $owner->id, 'name' => $owner->name],
            'viewer_is_recipient' => $recipient,
            'viewer_is_manager' => $context->isManagerOf($owner->id),
            'can_edit' => $canEdit,
            'wishes' => $wishes->map(fn (Wish $w) => $this->presenter->present($w))->values()->all(),
        ];

        $visible = $wishes;
        if ($recipient) {
            $received = Wish::query()->with('urls')
                ->where('owner_id', $owner->id)
                ->whereNotNull('received_at')
                ->orderByDesc('received_at')->orderByDesc('id')
                ->get();
            $list['received'] = $received->map(fn (Wish $w) => $this->presenter->present($w))->values()->all();
            $visible = $wishes->concat($received);
        }

        return [
            'members' => $this->members->forViewer($viewer),
            'list' => $list,
            'selected' => $this->selected($request->query('wish'), $canEdit, $visible),
        ];
    }

    /**
     * @param  Collection<int, Wish>  $visible
     */
    private function selected(mixed $requested, bool $canEdit, $visible): int|string|null
    {
        if ($requested === null) {
            return null;
        }
        if ($requested === 'new') {
            abort_unless($canEdit, 403);

            return 'new';
        }
        abort_unless(is_string($requested) && ctype_digit($requested) && strlen($requested) < 10, 403);
        abort_unless($visible->contains('id', (int) $requested), 403);

        return (int) $requested;
    }
}
