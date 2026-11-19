<?php

namespace App\Http\Controllers\Wishlist;

use App\Access\AccessGate;
use App\Access\AccessLevel;
use App\Access\Module;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\WishlistGroup;
use App\Wishlist\Catalog\ListPage;
use App\Wishlist\Giving\GiverListPage;
use App\Wishlist\Groups\GroupAccess;
use App\Wishlist\Occasions\UpcomingOccasions;
use App\Wishlist\ViewerContext;
use App\Wishlist\WishlistMembers;
use Illuminate\Http\Request;
use Inertia\Response;

/**
 * The single branch point between recipient-side and giver views, for persons and groups. Recipient-side viewers (owner, manager, member of a gifts-mode group)
 * get the reservation-blind ListPage; everyone else gets the GiverListPage. Nothing else branches.
 */
class ListController extends Controller
{
    public function index(Request $request, WishlistMembers $members, ListPage $page, GiverListPage $giverPage, UpcomingOccasions $occasions, GroupAccess $groups, AccessGate $gate): Response
    {
        $viewer = $request->user();

        $withShared = fn (Response $r) => $r
            ->with('groups', fn () => $groups->visibleGroups($viewer)->map(fn (WishlistGroup $g) => [
                'id' => $g->id, 'name' => $g->name, 'mode' => $g->mode->value, 'is_member' => $groups->isMember($viewer, $g),
            ])->values()->all())
            ->with('group_candidates', fn () => $gate->allows($viewer, Module::Wishlist, AccessLevel::Edit) ? $groups->candidates($viewer) : [])
            ->with('occasions', fn () => $occasions->strip());

        $requestedGroup = $request->query('group');
        if ($requestedGroup !== null) {
            abort_unless(is_string($requestedGroup) && ctype_digit($requestedGroup) && strlen($requestedGroup) < 10, 403);
            abort_if($request->query('member') !== null, 403);
            $group = WishlistGroup::query()->find((int) $requestedGroup);
            abort_if($group === null || ! $groups->canView($viewer, $group), 403);

            return $withShared($groups->isRecipientSide($viewer, $group)
                ? $page->renderGroup($group, $viewer, $request)
                : $giverPage->renderGroup($group, $viewer, $request));
        }

        $requested = $request->query('member');
        if ($requested === null) {
            $ownerId = $viewer->id;
        } else {
            abort_unless(is_string($requested) && ctype_digit($requested) && strlen($requested) < 10, 403);
            $ownerId = (int) $requested;
        }
        // Unknown ids and members without wishlist access are indistinguishable.
        abort_unless(in_array($ownerId, $members->ids($viewer), true), 403);

        $owner = User::query()->findOrFail($ownerId);

        if (ViewerContext::for($viewer)->isRecipientSideOf($owner->id)) {
            return $withShared($page->render($owner, $viewer, $request));
        }

        return $withShared($giverPage->render($owner, $viewer, $request));
    }
}
