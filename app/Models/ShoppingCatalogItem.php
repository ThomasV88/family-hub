<?php

namespace App\Models;

use App\Shopping\NameKey;
use Database\Factories\ShoppingCatalogItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShoppingCatalogItem extends Model
{
    /** @use HasFactory<ShoppingCatalogItemFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = ['name', 'default_unit_id'];

    protected function casts(): array
    {
        return ['image_version' => 'integer'];
    }

    protected static function booted(): void
    {
        static::saving(function (self $item) {
            $item->name_key = NameKey::from((string) $item->name);
        });
    }

    public function defaultUnit(): BelongsTo
    {
        return $this->belongsTo(ShoppingUnit::class, 'default_unit_id');
    }
}
