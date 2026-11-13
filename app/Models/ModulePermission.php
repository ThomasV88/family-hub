<?php

namespace App\Models;

use App\Access\AccessLevel;
use App\Access\Module;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ModulePermission extends Model
{
    protected $fillable = ['user_id', 'module', 'level'];

    protected function casts(): array
    {
        return [
            'module' => Module::class,
            'level' => AccessLevel::class,
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
