<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Wishlist\Occasions\ReminderLeadDays;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class GiftReminderSettingsController extends Controller
{
    public function update(Request $request, ReminderLeadDays $leads): RedirectResponse
    {
        $data = $request->validate([
            'lead_days' => ['present', 'array', 'max:'.count(config('wishlist.allowed_lead_days'))],
            'lead_days.*' => ['integer', 'distinct', Rule::in(config('wishlist.allowed_lead_days'))],
        ]);

        // Only ever the signed-in member's own row; no user id is accepted.
        $leads->set($request->user(), $data['lead_days']);

        return back(303)->with('toast', ['key' => 'notifications.saved']);
    }
}
