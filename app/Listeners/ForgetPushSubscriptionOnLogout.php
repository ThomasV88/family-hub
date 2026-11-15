<?php

namespace App\Listeners;

use App\Models\PushSubscription;
use Illuminate\Auth\Events\Logout;

/**
 * Reminder titles must not keep appearing on a shared device after its user logged out. The browser
 * sends the endpoint of its own subscription with the logout request; that one row is removed. The
 * server cannot tell which device a session belongs to without it, so revoking other sessions does
 * not remove their subscriptions.
 */
class ForgetPushSubscriptionOnLogout
{
    public function handle(Logout $event): void
    {
        $endpoint = request()->input('push_endpoint');
        if ($event->user === null || ! is_string($endpoint) || $endpoint === '' || strlen($endpoint) > 2048) {
            return;
        }

        PushSubscription::query()
            ->where('user_id', $event->user->getAuthIdentifier())
            ->where('endpoint_hash', PushSubscription::hashEndpoint($endpoint))
            ->delete();
    }
}
