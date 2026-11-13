<?php

namespace App\Http\Controllers\Wishlist;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Wishlist\Catalog\ListPage;
use App\Wishlist\WishlistMembers;
use Illuminate\Http\Request;
use Inertia\Response;

/**
 * The single branch point between recipient-side and giver views. In this plan every viewer gets the
 * reservation-blind ListPage; plan 04-08 changes only the non-recipient branch.
 */
class ListController extends Controller
{
    public function index(Request $request, WishlistMembers $members, ListPage $page): Response
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

        return $page->render($owner, $viewer, $request);
    }
}
