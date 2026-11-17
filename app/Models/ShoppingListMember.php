<?php

namespace App\Models;

use App\Shopping\MemberRole;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShoppingListMember extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = ['list_id', 'user_id', 'role', 'created_at'];

    protected function casts(): array
    {
        return [
            'role' => MemberRole::class,
            'created_at' => 'immutable_datetime',
        ];
    }

    public function list(): BelongsTo
    {
        return $this->belongsTo(ShoppingList::class, 'list_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
