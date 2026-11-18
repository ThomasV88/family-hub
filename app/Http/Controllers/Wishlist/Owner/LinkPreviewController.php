<?php

namespace App\Http\Controllers\Wishlist\Owner;

use App\Http\Controllers\Controller;
use App\Wishlist\Catalog\FetchLinkPreview;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/** Autofill of the wish form from its first link. Writes nothing to wishes; results live in the cache for 5 minutes. */
class LinkPreviewController extends Controller
{
    public function start(Request $request): JsonResponse
    {
        $data = $request->validate(['url' => ['required', 'string', 'max:2048', 'regex:#^https?://\S+$#i']]);
        $token = Str::random(40);
        Cache::put(FetchLinkPreview::key($token), ['status' => 'pending', 'user' => $request->user()->id], FetchLinkPreview::TTL_SECONDS);
        FetchLinkPreview::dispatch($token, $data['url'], $request->user()->id);

        return response()->json(['token' => $token]);
    }

    public function show(Request $request, string $token): JsonResponse
    {
        $entry = $this->entry($request, $token);
        if ($entry['status'] !== 'done') {
            return response()->json(['status' => 'pending']);
        }

        return response()->json([
            'status' => 'done',
            'title' => $entry['title'],
            'description' => $entry['description'],
            'image' => (bool) $entry['image'],
            'failures' => (object) $entry['failures'],
        ]);
    }

    /** The picture found on the link, as a small thumbnail, served from our own origin (the browser never contacts the remote site). */
    public function image(Request $request, string $token): Response
    {
        $entry = $this->entry($request, $token);
        abort_if(($entry['thumb'] ?? null) === null, 404);

        return response(base64_decode($entry['thumb']), 200, [
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** @return array<string, mixed> */
    private function entry(Request $request, string $token): array
    {
        $entry = Cache::get(FetchLinkPreview::key($token));
        abort_unless(is_array($entry) && ($entry['user'] ?? null) === $request->user()->id, 404);

        return $entry;
    }
}
