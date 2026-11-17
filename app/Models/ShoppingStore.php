<?php

namespace App\Models;

use App\Shopping\NameKey;
use Database\Factories\ShoppingStoreFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ShoppingStore extends Model
{
    /** @use HasFactory<ShoppingStoreFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = ['name'];

    protected function casts(): array
    {
        return ['image_version' => 'integer'];
    }

    protected static function booted(): void
    {
        static::saving(function (self $store) {
            $store->name_key = NameKey::from((string) $store->name);
        });
    }
}
