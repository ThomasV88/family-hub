<?php

namespace App\Shopping;

use App\Models\ShoppingCatalogItem;
use App\Models\ShoppingItem;
use App\Models\ShoppingList;
use App\Models\ShoppingUnit;
use App\Models\User;
use App\Shopping\Queries\ListQuery;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Items of an open list. Every mutation runs under the list row lock and re-checks the status inside it. */
final class ItemService
{
    public function __construct(private readonly ShoppingAccess $access) {}

    /**
     * @param  list<array{catalog_item_id?: ?int, name?: ?string, quantity?: string|int|float|null, unit_id?: ?int, note?: ?string, save_to_catalog?: bool}>  $items
     * @return array{added: int, merged: list<string>}
     */
    public function add(User $actor, int $listId, array $items): array
    {
        if ($items === [] || count($items) > (int) config('shopping.items_per_add_request')) {
            throw ValidationException::withMessages(['items' => 'shopping.error.items_count']);
        }

        return DB::transaction(function () use ($actor, $listId, $items) {
            $list = $this->lockOpenForEdit($actor, $listId);

            $position = (int) ShoppingItem::query()->where('list_id', $list->id)->max('position');
            $added = 0;
            $merged = [];

            foreach (array_values($items) as $index => $input) {
                $catalog = $this->catalogFor($actor, $input, $index);
                $name = $catalog?->name ?? $this->cleanName($input['name'] ?? null, "items.$index.name");
                $unitId = array_key_exists('unit_id', $input) ? $input['unit_id'] : $catalog?->default_unit_id;
                $this->assertUnit($unitId, "items.$index.unit_id");
                $quantity = $this->quantity($input['quantity'] ?? 1, "items.$index.quantity");
                $note = $this->note($input['note'] ?? null, "items.$index.note");

                if ($catalog !== null) {
                    $existing = ShoppingItem::query()
                        ->where('list_id', $list->id)->where('catalog_item_id', $catalog->id)
                        ->where('state', ItemState::Pending->value)
                        ->where(fn ($q) => $unitId === null ? $q->whereNull('unit_id') : $q->where('unit_id', $unitId))
                        ->orderBy('position')->orderBy('id')->lockForUpdate()->first();
                    if ($existing !== null) {
                        $sum = min((float) $existing->quantity + $quantity, (float) config('shopping.quantity_max'));
                        $existing->forceFill(['quantity' => number_format($sum, 2, '.', '')])->save();
                        if (! in_array($catalog->name, $merged, true)) {
                            $merged[] = $catalog->name;
                        }

                        continue;
                    }
                }

                $item = new ShoppingItem([
                    'list_id' => $list->id, 'catalog_item_id' => $catalog?->id, 'name' => $name,
                    'quantity' => number_format($quantity, 2, '.', ''), 'unit_id' => $unitId, 'note' => $note,
                    'position' => ++$position,
                ]);
                $item->forceFill(['state' => ItemState::Pending, 'created_by' => $actor->id])->save();
                $added++;
            }

            $list->touch();

            return ['added' => $added, 'merged' => $merged];
        });
    }

    /** @param array{name?: string, quantity?: string|int|float|null, unit_id?: ?int, note?: ?string} $data */
    public function update(User $actor, int $listId, int $itemId, array $data): ShoppingItem
    {
        return DB::transaction(function () use ($actor, $listId, $itemId, $data) {
            $list = $this->lockOpenForEdit($actor, $listId);
            $item = ShoppingItem::query()->where('list_id', $list->id)->whereKey($itemId)->firstOrFail();

            if (array_key_exists('name', $data)) {
                $item->name = $this->cleanName($data['name'], 'name');
            }
            if (array_key_exists('quantity', $data)) {
                $item->quantity = number_format($this->quantity($data['quantity'], 'quantity'), 2, '.', '');
            }
            if (array_key_exists('unit_id', $data)) {
                $this->assertUnit($data['unit_id'], 'unit_id');
                $item->unit_id = $data['unit_id'];
            }
            if (array_key_exists('note', $data)) {
                $item->note = $this->note($data['note'], 'note');
            }
            $item->save();
            $list->touch();

            return $item->refresh();
        });
    }

    public function remove(User $actor, int $listId, int $itemId): void
    {
        DB::transaction(function () use ($actor, $listId, $itemId) {
            $list = $this->lockOpenForEdit($actor, $listId);
            ShoppingItem::query()->where('list_id', $list->id)->whereKey($itemId)->firstOrFail()->delete();
            $list->touch();
        });
    }

    private function lockOpenForEdit(User $actor, int $listId): ShoppingList
    {
        $list = ListQuery::lockForActor($actor, $listId, $this->access);
        abort_unless($this->access->mayEditItems($actor, $list), 403);
        abort_unless($list->status === ListStatus::Open, 409);

        return $list;
    }

    /** @param array<string, mixed> $input */
    private function catalogFor(User $actor, array $input, int $index): ?ShoppingCatalogItem
    {
        if (! empty($input['catalog_item_id'])) {
            $catalog = ShoppingCatalogItem::query()->find($input['catalog_item_id']);
            if ($catalog === null) {
                throw ValidationException::withMessages(["items.$index.catalog_item_id" => 'shopping.error.catalog_unknown']);
            }

            return $catalog;
        }

        if (! empty($input['save_to_catalog'])) {
            $name = $this->cleanName($input['name'] ?? null, "items.$index.name");
            $existing = ShoppingCatalogItem::query()->where('name_key', NameKey::from($name))->first();
            if ($existing !== null) {
                return $existing;
            }
            $unitId = $input['unit_id'] ?? null;
            $this->assertUnit($unitId, "items.$index.unit_id");
            $catalog = new ShoppingCatalogItem(['name' => $name, 'default_unit_id' => $unitId]);
            $catalog->forceFill(['created_by' => $actor->id])->save();

            return $catalog;
        }

        return null;
    }

    private function cleanName(?string $name, string $key): string
    {
        $name = trim((string) preg_replace('/\s+/u', ' ', (string) $name));
        if ($name === '' || mb_strlen($name) > (int) config('shopping.name_max.catalog')) {
            throw ValidationException::withMessages([$key => 'shopping.error.name_invalid']);
        }

        return $name;
    }

    private function note(?string $note, string $key): ?string
    {
        $note = $note === null ? null : trim($note);
        if ($note === null || $note === '') {
            return null;
        }
        if (mb_strlen($note) > (int) config('shopping.name_max.note')) {
            throw ValidationException::withMessages([$key => 'shopping.error.note_too_long']);
        }

        return $note;
    }

    private function quantity(mixed $value, string $key): float
    {
        $string = is_string($value) ? trim($value) : (string) $value;
        if (! preg_match('/^\d{1,3}(\.\d{1,2})?$/', $string) || (float) $string <= 0 || (float) $string > (float) config('shopping.quantity_max')) {
            throw ValidationException::withMessages([$key => 'shopping.error.quantity_invalid']);
        }

        return (float) $string;
    }

    private function assertUnit(?int $unitId, string $key): void
    {
        if ($unitId !== null && ! ShoppingUnit::query()->whereKey($unitId)->exists()) {
            throw ValidationException::withMessages([$key => 'shopping.error.unit_unknown']);
        }
    }
}
