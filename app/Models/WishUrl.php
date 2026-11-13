<?php

namespace App\Models;

use Database\Factories\WishUrlFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WishUrl extends Model
{
    /** @use HasFactory<WishUrlFactory> */
    use HasFactory;

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = ['url', 'label', 'position'];

    protected function casts(): array
    {
        return ['position' => 'integer'];
    }

    public function wish(): BelongsTo
    {
        return $this->belongsTo(Wish::class);
    }
}
