<?php

namespace App\Wishlist\Catalog;

use App\Models\User;
use App\Models\Wish;
use App\Models\WishlistGroup;
use App\Wishlist\Groups\GroupAccess;
use App\Wishlist\ViewerContext;
use App\Wishlist\WishAccess;
use App\Wishlist\WishlistMembers;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/** Reservation-blind props for /wishlist, for personal and group lists: what the recipient side (and, for now, everyone) sees. */
final class ListPage
{
    public function __construct(
        private readonly WishPresenter $presenter,
        private readonly WishAccess $access,
        private readonly WishlistMembers $members,
        private readonly GroupAccess $groups,
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

    public function renderGroup(WishlistGroup $group, User $viewer, Request $request): Response
    {
        return Inertia::render('Wishlist/Index', $this->groupProps($group, $viewer, $request));
    }

    /**
     * Reservation-blind props of a group list. list.owner carries the group name so components that read
     * list.owner.name keep working; the received section and the member names are for members only.
     *
     * @return array<string, mixed>
     */
    public function groupProps(WishlistGroup $group, User $viewer, Request $request): array
    {
        $isMember = $this->groups->isMember($viewer, $group);
        $canEdit = $this->groups->canManage($viewer, $group);

        $wishes = Wish::query()->with(['urls', 'receivedBy:id,name'])
            ->where('group_id', $group->id)
            ->whereNull('received_at')
            ->orderByDesc('priority')->orderByDesc('created_at')->orderByDesc('id')
            ->get();

        $list = [
            'kind' => 'group',
            'owner' => ['id' => null, 'name' => $group->name],
            'group' => [
                'id' => $group->id,
                'name' => $group->name,
                'mode' => $group->mode->value,
                'is_member' => $isMember,
                'can_manage' => $canEdit,
                'members' => $this->groups->members($group),
            ],
            'viewer_is_recipient' => $this->groups->isRecipientSide($viewer, $group),
            'viewer_is_manager' => false,
            'can_edit' => $canEdit,
            'wishes' => $wishes->map(fn (Wish $w) => $this->presenter->present($w))->values()->all(),
        ];

        $visible = $wishes;
        if ($isMember) {
            $received = Wish::query()->with(['urls', 'receivedBy:id,name'])
                ->where('group_id', $group->id)
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
