<?php

namespace App\Shopping\Catalog;

use App\Models\ShoppingCatalogItem;
use App\Models\User;
use App\Shopping\Images\ShoppingImageStore;
use App\Shopping\NameKey;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Shared catalog of things to buy. Deleting an item never touches list items (name and quantity stay). */
final class CatalogService
{
    public function __construct(private readonly ShoppingImageStore $images) {}

    public function create(User $actor, string $name, ?int $defaultUnitId): ShoppingCatalogItem
    {
        $name = $this->clean($name);
        $this->assertFree($name, null);
        $item = new ShoppingCatalogItem(['name' => $name, 'default_unit_id' => $defaultUnitId]);
        $item->forceFill(['created_by' => $actor->id]);
        $this->save($item);

        return $item;
    }

    public function update(User $actor, int $id, string $name, ?int $defaultUnitId): ShoppingCatalogItem
    {
        $name = $this->clean($name);
        $item = ShoppingCatalogItem::query()->findOrFail($id);
        $this->assertFree($name, $item->id);
        $item->fill(['name' => $name, 'default_unit_id' => $defaultUnitId]);
        $this->save($item);

        return $item;
    }

    public function delete(User $actor, int $id): void
    {
        $item = ShoppingCatalogItem::query()->findOrFail($id);
        DB::transaction(fn () => $item->delete());
        $this->images->deleteDirectory(ShoppingImageStore::KIND_CATALOG, $id);
    }

    private function clean(string $name): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $name));
    }

    private function assertFree(string $name, ?int $exceptId): void
    {
        $taken = ShoppingCatalogItem::query()->where('name_key', NameKey::from($name))
            ->when($exceptId !== null, fn ($q) => $q->where('id', '!=', $exceptId))->exists();
        if ($taken) {
            throw ValidationException::withMessages(['name' => 'shopping.settings.err_duplicate']);
        }
    }

    private function save(ShoppingCatalogItem $item): void
    {
        try {
            $item->save();
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['name' => 'shopping.settings.err_duplicate']);
        }
    }
}
