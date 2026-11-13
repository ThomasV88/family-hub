<?php

namespace App\Wishlist\Catalog;

use App\Models\User;
use App\Models\Wish;
use App\Wishlist\Money;
use App\Wishlist\WishAccess;
use App\Wishlist\WishPriority;
use Illuminate\Support\Facades\DB;

/** Owner-side writes. Reservation-blind: an owner action is never blocked or shaped by gifting state. */
final class WishService
{
    public function __construct(private readonly WishAccess $access, private readonly WishImageStore $images) {}

    /** @param  array<string, mixed>  $data  validated input */
    public function create(User $actor, int $ownerId, array $data): Wish
    {
        $this->authorize($actor, $ownerId);

        $fetch = false;
        $wish = DB::transaction(function () use ($actor, $ownerId, $data, &$fetch) {
            $wish = new Wish($this->fields($data) + ['price_currency' => config('wishlist.default_currency'), 'priority' => WishPriority::Love]);
            $wish->forceFill(['owner_id' => $ownerId, 'created_by_id' => $actor->id]);
            $wish->save();
            $fetch = $this->replaceUrls($wish, $data['urls'] ?? []) && $this->markPendingForFetch($wish);

            return $wish;
        });
        $this->dispatchFetch($wish, $fetch);

        return $wish;
    }

    /** @param  array<string, mixed>  $data  validated input */
    public function update(User $actor, Wish $wish, array $data): Wish
    {
        $this->authorize($actor, $wish->owner_id);

        $fetch = false;
        $wish = DB::transaction(function () use ($wish, $data, &$fetch) {
            $wish->fill($this->fields($data))->save();
            if (array_key_exists('urls', $data) && $this->replaceUrls($wish, $data['urls'] ?? [])) {
                $fetch = $this->markPendingForFetch($wish);
            }

            return $wish;
        });
        $this->dispatchFetch($wish, $fetch);

        return $wish;
    }

    public function delete(User $actor, Wish $wish): void
    {
        $this->authorize($actor, $wish->owner_id);

        $this->images->remove($wish);
        DB::transaction(fn () => $wish->delete());
    }

    public function markReceived(User $actor, Wish $wish): Wish
    {
        $this->authorize($actor, $wish->owner_id);

        return DB::transaction(function () use ($wish) {
            $wish->forceFill(['received_at' => $wish->received_at ?? now()])->save();

            return $wish;
        });
    }

    public function unmarkReceived(User $actor, Wish $wish): Wish
    {
        $this->authorize($actor, $wish->owner_id);

        return DB::transaction(function () use ($wish) {
            $wish->forceFill(['received_at' => null])->save();

            return $wish;
        });
    }

    private function authorize(User $actor, int $ownerId): void
    {
        abort_unless($this->access->canEditList($actor, $ownerId), 403);
    }

    /**
     * Explicit allowlist mapping, only for keys present in the input.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function fields(array $data): array
    {
        $fields = [];
        foreach (['title', 'notes', 'size_notes'] as $key) {
            if (array_key_exists($key, $data)) {
                $fields[$key] = $data[$key];
            }
        }
        if (array_key_exists('price', $data)) {
            $fields['price_minor'] = Money::toMinor(is_string($data['price']) ? $data['price'] : null);
        }
        if (! empty($data['currency'])) {
            $fields['price_currency'] = $data['currency'];
        }
        if (! empty($data['priority'])) {
            $fields['priority'] = WishPriority::fromKey($data['priority']);
        }

        return $fields;
    }

    /** Pending only when an automatic image makes sense: some link exists and no upload is in place. */
    private function markPendingForFetch(Wish $wish): bool
    {
        if ($wish->image_source === 'uploaded' || $wish->urls()->doesntExist()) {
            return false;
        }
        $wish->forceFill(['image_status' => 'pending'])->save();

        return true;
    }

    /** The job is queued after the transaction committed, so the worker always sees the saved links. */
    private function dispatchFetch(Wish $wish, bool $fetch): void
    {
        if ($fetch) {
            FetchWishImage::dispatch($wish->id);
        }
    }

    /**
     * @param  array<int, array{url: string, label?: ?string}>  $urls
     * @return bool whether the ordered list of addresses changed
     */
    private function replaceUrls(Wish $wish, array $urls): bool
    {
        $before = $wish->urls()->pluck('url')->all();
        $wish->urls()->delete();
        foreach (array_values($urls) as $position => $row) {
            $wish->urls()->create([
                'url' => $row['url'],
                'label' => ($row['label'] ?? null) !== null && trim($row['label']) !== '' ? trim($row['label']) : null,
                'position' => $position,
            ]);
        }
        $wish->unsetRelation('urls');

        return $before !== array_values(array_map(fn ($row) => $row['url'], $urls));
    }
}
