<?php

namespace App\Http\Controllers\Wishlist;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Wishlist\Catalog\ListPage;
use App\Wishlist\Giving\GiverListPage;
use App\Wishlist\ViewerContext;
use App\Wishlist\WishlistMembers;
use Illuminate\Http\Request;
use Inertia\Response;

/**
 * The single branch point between recipient-side and giver views. Recipient-side viewers (owner, manager)
 * get the reservation-blind ListPage; everyone else gets the GiverListPage. Nothing else branches.
 */
class ListController extends Controller
{
    public function index(Request $request, WishlistMembers $members, ListPage $page, GiverListPage $giverPage): Response
    {
        $viewer = $request->user();

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
            return $page->render($owner, $viewer, $request);
        }

        return $giverPage->render($owner, $viewer, $request);
    }
}
