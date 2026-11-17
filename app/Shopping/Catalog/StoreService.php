<?php

namespace App\Shopping\Catalog;

use App\Models\ShoppingStore;
use App\Models\User;
use App\Shopping\Images\ShoppingImageStore;
use App\Shopping\NameKey;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Store chains. Deleting one clears the store of open lists (SET NULL); finished lists keep their stored store name. */
final class StoreService
{
    public function __construct(private readonly ShoppingImageStore $images) {}

    public function create(User $actor, string $name): ShoppingStore
    {
        $name = $this->clean($name);
        $this->assertFree($name, null);
        $store = new ShoppingStore(['name' => $name]);
        $store->forceFill(['created_by' => $actor->id]);
        $this->save($store);

        return $store;
    }

    public function update(User $actor, int $id, string $name): ShoppingStore
    {
        $name = $this->clean($name);
        $store = ShoppingStore::query()->findOrFail($id);
        $this->assertFree($name, $store->id);
        $store->fill(['name' => $name]);
        $this->save($store);

        return $store;
    }

    public function delete(User $actor, int $id): void
    {
        $store = ShoppingStore::query()->findOrFail($id);
        DB::transaction(fn () => $store->delete());
        $this->images->deleteDirectory(ShoppingImageStore::KIND_STORES, $id);
    }

    private function clean(string $name): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $name));
    }

    private function assertFree(string $name, ?int $exceptId): void
    {
        $taken = ShoppingStore::query()->where('name_key', NameKey::from($name))
            ->when($exceptId !== null, fn ($q) => $q->where('id', '!=', $exceptId))->exists();
        if ($taken) {
            throw ValidationException::withMessages(['name' => 'shopping.settings.err_duplicate']);
        }
    }

    private function save(ShoppingStore $store): void
    {
        try {
            $store->save();
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['name' => 'shopping.settings.err_duplicate']);
        }
    }
}
