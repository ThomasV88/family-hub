<?php

namespace App\Wishlist\Catalog;

use App\Wishlist\Fetch\FetchFailed;
use App\Wishlist\Fetch\MetaImageExtractor;
use App\Wishlist\Fetch\PagePreview;
use App\Wishlist\Fetch\SafeFetcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Reads title, description and picture of ONE link for the wish form, in the worker only. All network access goes
 * through SafeFetcher; the picture passes the same ImageProcessor as an upload and only its thumbnail is kept, in
 * the cache for a few minutes, bound to the asking user. Nothing is written to wishes. Logs carry a reason code only.
 */
final class FetchLinkPreview implements ShouldQueue
{
    use Queueable;

    public const TTL_SECONDS = 300;

    private const MAX_CANDIDATES = 3;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(public readonly string $token, public readonly string $url, public readonly int $userId) {}

    public static function key(string $token): string
    {
        return 'wishlist.link_preview.'.$token;
    }

    public function handle(SafeFetcher $fetcher, ImageProcessor $processor): void
    {
        $htmlCap = (int) config('wishlist.fetch_html_max_bytes');
        try {
            $page = $fetcher->get($this->url, $htmlCap, 'html');
        } catch (FetchFailed $e) {
            $this->finish(PagePreview::failed(self::reason($e)), null);

            return;
        }

        $candidates = array_slice(MetaImageExtractor::candidates($page->body, $page->finalUrl), 0, self::MAX_CANDIDATES);
        $thumb = null;
        $imageCap = (int) config('wishlist.fetch_image_max_bytes');
        foreach ($candidates as $candidate) {
            try {
                $image = $fetcher->get($candidate, $imageCap, 'image');
                $thumb = $processor->process($image->body, $imageCap)->thumbBytes;
                break;
            } catch (FetchFailed|ImageRejected) {
                continue;
            }
        }

        $preview = PagePreview::fromHtml($page->body, $page->finalUrl, $thumb !== null);
        unset($page);
        $this->finish($preview, $thumb);
    }

    public function failed(Throwable $e): void
    {
        Log::warning('link_preview_failed', ['reason' => class_basename($e)]);
        $this->finish(PagePreview::failed('unreachable'), null);
    }

    private function finish(PagePreview $preview, ?string $thumb): void
    {
        Log::info('link_preview', ['reason' => implode(',', array_unique($preview->failures)) ?: 'ok']);
        Cache::put(self::key($this->token), [
            'status' => 'done',
            'user' => $this->userId,
            'title' => $preview->title,
            'description' => $preview->description,
            'image' => $preview->hasImage,
            'thumb' => $thumb === null ? null : base64_encode($thumb),
            'failures' => $preview->failures,
        ], self::TTL_SECONDS);
    }

    private static function reason(FetchFailed $e): string
    {
        return match ($e->reason) {
            'timeout' => 'timeout',
            'blocked_address', 'bad_url' => 'blocked',
            'http_blocked' => 'http_blocked',
            'too_large' => 'too_large',
            default => 'unreachable',
        };
    }
}
