<?php

namespace App\Http\Controllers\Wishlist\Owner;

use App\Http\Controllers\Controller;
use App\Wishlist\Catalog\SharedLink;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Web Share Target (GET). Saves nothing: it flashes a sanitised prefill and sends the viewer to the new-wish form on their own list.
 * The title and url never go into the redirect address, so they stay out of logs and history. Deliberately no logging here.
 */
class ShareTargetController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $pick = fn (string $key): ?string => is_string($request->query($key)) ? $request->query($key) : null;

        $request->session()->flash('wishlist.share', SharedLink::from($pick('title'), $pick('text'), $pick('url')));

        return redirect('/wishlist?wish=new');
    }
}
