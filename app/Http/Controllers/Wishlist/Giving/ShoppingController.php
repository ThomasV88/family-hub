<?php

namespace App\Http\Controllers\Wishlist\Giving;

use App\Http\Controllers\Controller;
use App\Wishlist\Giving\ShoppingSummary;
use App\Wishlist\ViewerContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** JSON only: the ShoppingSummary component fetches this itself; it is never an Inertia page prop. */
class ShoppingController extends Controller
{
    public function index(Request $request, ShoppingSummary $summary): JsonResponse
    {
        return response()->json($summary->forViewer(ViewerContext::for($request->user())));
    }
}
