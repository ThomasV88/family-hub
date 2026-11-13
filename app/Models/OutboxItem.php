<?php

namespace App\Models;

use App\Notifications\Outbox\OutboxStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OutboxItem extends Model
{
    protected $table = 'notification_outbox';

    /** @var list<string> */
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'status' => OutboxStatus::class,
            'attempts' => 'integer',
            'available_at' => 'immutable_datetime',
            'sent_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
