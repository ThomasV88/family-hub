<?php

namespace App\Models;

use App\Shopping\ItemState;
use Database\Factories\ShoppingItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShoppingItem extends Model
{
    /** @use HasFactory<ShoppingItemFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = ['list_id', 'catalog_item_id', 'name', 'quantity', 'unit_id', 'note', 'position'];

    protected function casts(): array
    {
        return [
            'state' => ItemState::class,
            'quantity' => 'decimal:2',
            'state_changed_at' => 'immutable_datetime',
            'position' => 'integer',
        ];
    }

    public function list(): BelongsTo
    {
        return $this->belongsTo(ShoppingList::class, 'list_id');
    }

    public function catalogItem(): BelongsTo
    {
        return $this->belongsTo(ShoppingCatalogItem::class, 'catalog_item_id');
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(ShoppingUnit::class, 'unit_id');
    }
}
