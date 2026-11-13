<?php

namespace App\Wishlist\Catalog;

use App\Models\Wish;
use App\Wishlist\Fetch\FetchFailed;
use App\Wishlist\Fetch\MetaImageExtractor;
use App\Wishlist\Fetch\SafeFetcher;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Loads a product image from the wish links, in the worker only. All network access goes through SafeFetcher;
 * every candidate then passes the same ImageProcessor as an upload. Logs carry the wish id and a reason code,
 * never an address or a body.
 */
final class FetchWishImage implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    private const MAX_URLS = 3;

    private const MAX_CANDIDATES = 3;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(public readonly int $wishId) {}

    public function uniqueId(): string
    {
        return (string) $this->wishId;
    }

    public function handle(SafeFetcher $fetcher, ImageProcessor $processor, WishImageStore $store): void
    {
        $wish = Wish::query()->find($this->wishId);
        if ($wish === null || $wish->image_source === 'uploaded') {
            return;
        }

        $htmlCap = (int) config('wishlist.fetch_html_max_bytes');
        $imageCap = (int) config('wishlist.fetch_image_max_bytes');

        foreach ($wish->urls()->limit(self::MAX_URLS)->pluck('url') as $pageUrl) {
            try {
                $page = $fetcher->get($pageUrl, $htmlCap, 'html');
            } catch (FetchFailed $e) {
                $this->note('page_'.$e->reason);

                continue;
            }

            $candidates = array_slice(MetaImageExtractor::candidates($page->body, $page->finalUrl), 0, self::MAX_CANDIDATES);
            $pageHost = WishPresenter::host($page->finalUrl);
            unset($page);
            foreach ($candidates as $candidate) {
                try {
                    $image = $fetcher->get($candidate, $imageCap, 'image');
                    $processed = $processor->process($image->body, $imageCap);
                } catch (FetchFailed $e) {
                    $this->note('image_'.$e->reason);

                    continue;
                } catch (ImageRejected $e) {
                    $this->note('image_'.$e->reason);

                    continue;
                }

                $fresh = Wish::query()->find($this->wishId);
                if ($fresh === null || $fresh->image_source === 'uploaded') {
                    return;
                }
                $store->put($fresh, $processed, 'fetched', $pageHost);

                return;
            }
        }

        $this->markFailed();
    }

    public function failed(Throwable $e): void
    {
        Log::warning('wish_image_fetch_failed', ['wish' => $this->wishId, 'reason' => class_basename($e)]);
        $this->markFailed();
    }

    private function markFailed(): void
    {
        $wish = Wish::query()->find($this->wishId);
        if ($wish !== null && $wish->image_source !== 'uploaded' && $wish->image_status === 'pending') {
            $wish->forceFill(['image_status' => 'failed'])->save();
        }
        $this->note('no_image');
    }

    private function note(string $reason): void
    {
        Log::info('wish_image_fetch', ['wish' => $this->wishId, 'reason' => $reason]);
    }
}
