<?php

namespace App\Models;

use App\Shopping\ListStatus;
use App\Shopping\SourceKind;
use Database\Factories\ShoppingListFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Status, lock and finish columns, creator, source and reminder columns are set by services with forceFill.
 */
class ShoppingList extends Model
{
    /** @use HasFactory<ShoppingListFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = ['name', 'store_id', 'responsible_id'];

    protected function casts(): array
    {
        return [
            'status' => ListStatus::class,
            'source_kind' => SourceKind::class,
            'locked_at' => 'immutable_datetime',
            'done_at' => 'immutable_datetime',
            'remind_at' => 'immutable_datetime',
            'reminder_enqueued_at' => 'immutable_datetime',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    public function responsible(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_id');
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(ShoppingStore::class, 'store_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(ShoppingItem::class, 'list_id')->orderBy('position')->orderBy('id');
    }

    public function members(): HasMany
    {
        return $this->hasMany(ShoppingListMember::class, 'list_id');
    }

    public function memberUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'shopping_list_members', 'list_id', 'user_id')
            ->withPivot('role', 'created_at');
    }
}
