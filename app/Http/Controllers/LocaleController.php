<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;

class LocaleController extends Controller
{
    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate(['locale' => ['required', 'in:sk,pl,en']]);

        $request->user()?->forceFill(['locale' => $data['locale']])->save();

        Cookie::queue(cookie('locale', $data['locale'], 60 * 24 * 365, null, null, (bool) config('session.secure'), true, false, 'lax'));

        return back(303);
    }
}
