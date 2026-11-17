<?php

namespace App\Models;

use Database\Factories\ShoppingUnitFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/** Built-in units have a key and no names (labels come from lang); administrator units have three names and no key. */
class ShoppingUnit extends Model
{
    /** @use HasFactory<ShoppingUnitFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = ['name_en', 'name_sk', 'name_pl', 'position'];

    protected function casts(): array
    {
        return ['position' => 'integer'];
    }

    public function isBuiltIn(): bool
    {
        return $this->key !== null;
    }
}
