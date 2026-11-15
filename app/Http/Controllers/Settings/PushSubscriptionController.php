<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\StorePushSubscriptionRequest;
use App\Models\PushSubscription;
use App\Push\PushConfig;
use App\Push\PushResult;
use App\Push\PushSender;
use App\Support\UserAgentLabel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class PushSubscriptionController extends Controller
{
    public const CAP = 10;

    /** The test button runs inside a web request, so it only tries the newest few devices. */
    public const TEST_LIMIT = 3;

    public function store(StorePushSubscriptionRequest $request): JsonResponse
    {
        $user = $request->user();
        $endpoint = $request->validated('endpoint');
        $hash = PushSubscription::hashEndpoint($endpoint);

        DB::transaction(function () use ($request, $user, $endpoint, $hash): void {
            // Same endpoint from another account: rebind it (the device is now this user's).
            // updateOrCreate survives two simultaneous first inserts (unique endpoint_hash) without a 500.
            $row = PushSubscription::query()->updateOrCreate(['endpoint_hash' => $hash], [
                'user_id' => $user->id,
                'endpoint' => $endpoint,
                'p256dh' => $request->validated('keys.p256dh'),
                'auth' => $request->validated('keys.auth'),
                'content_encoding' => $request->validated('contentEncoding') ?? 'aes128gcm',
                'label' => UserAgentLabel::device($request->userAgent()),
            ]);
            // Recency decides which subscriptions the cap keeps, so an unchanged re-registration still counts.
            if (! $row->wasRecentlyCreated && ! $row->wasChanged()) {
                $row->touch();
            }

            $excess = PushSubscription::query()->where('user_id', $user->id)->orderByDesc('updated_at')->orderByDesc('id')
                ->skip(self::CAP)->take(100)->pluck('id');
            if ($excess->isNotEmpty()) {
                PushSubscription::query()->whereIn('id', $excess)->delete();
            }
        });

        return response()->json(['ok' => true], 201);
    }

    public function destroy(Request $request): Response
    {
        $endpoint = $request->validate(['endpoint' => ['required', 'string', 'max:2048']])['endpoint'];

        PushSubscription::query()->where('user_id', $request->user()->id)
            ->where('endpoint_hash', PushSubscription::hashEndpoint($endpoint))->delete();

        return response()->noContent();
    }

    public function test(Request $request, PushSender $sender): JsonResponse
    {
        $subscriptions = PushSubscription::query()->where('user_id', $request->user()->id)
            ->orderByDesc('updated_at')->orderByDesc('id')->limit(self::TEST_LIMIT)->get();
        if ($subscriptions->isEmpty()) {
            return response()->json(['error' => 'none'], 422);
        }
        if (! PushConfig::configured()) {
            return response()->json(['error' => 'not_configured'], 503);
        }

        $message = [
            'title' => trans('notifications.test_title', [], $request->user()->preferredLocale() ?? 'en'),
            'url' => '/settings/notifications',
            'tag' => 'test',
        ];
        $sent = 0;
        foreach ($subscriptions as $subscription) {
            $result = $sender->send($subscription, $message);
            if ($result === PushResult::Success) {
                $subscription->forceFill(['last_success_at' => now()])->save();
                $sent++;
            } elseif ($result === PushResult::Expired) {
                $subscription->delete();
            }
        }

        return response()->json(['sent' => $sent]);
    }
}
