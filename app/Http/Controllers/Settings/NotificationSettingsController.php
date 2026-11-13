<?php

namespace App\Http\Controllers\Settings;

use App\Access\AccessGate;
use App\Access\AccessLevel;
use App\Access\Module;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UpdateNotificationPreferenceRequest;
use App\Notifications\NotificationPreferences;
use App\Push\PushConfig;
use App\Support\SettingsSections;
use App\Wishlist\Occasions\ReminderLeadDays;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class NotificationSettingsController extends Controller
{
    public function show(Request $request, NotificationPreferences $preferences, AccessGate $gate, ReminderLeadDays $leads): Response
    {
        $user = $request->user();

        return Inertia::render('Settings/Notifications', [
            'sections' => SettingsSections::for($user),
            'email' => $user->email,
            'preferences' => [
                'mail' => $preferences->enabled($user, 'mail'),
                'push' => $preferences->enabled($user, 'push'),
            ],
            'giftReminders' => $gate->allows($user, Module::Wishlist, AccessLevel::Edit)
                ? ['lead_days' => $leads->for($user), 'allowed' => config('wishlist.allowed_lead_days')]
                : null,
            'vapidPublicKey' => PushConfig::configured() ? config('push.public_key') : null,
        ]);
    }

    public function update(UpdateNotificationPreferenceRequest $request, string $channel, NotificationPreferences $preferences): RedirectResponse
    {
        // Only ever the signed-in member's own row.
        $preferences->set($request->user(), $channel, $request->boolean('enabled'));

        return back(303)->with('toast', ['key' => 'notifications.saved']);
    }
}
