<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PushSubscription extends Model
{
    /** @var list<string> */
    protected $guarded = [];

    /** Keys never leave the server in JSON. */
    protected $hidden = ['endpoint', 'p256dh', 'auth'];

    protected function casts(): array
    {
        return [
            'p256dh' => 'encrypted',
            'auth' => 'encrypted',
            'last_success_at' => 'immutable_datetime',
        ];
    }

    public static function hashEndpoint(string $endpoint): string
    {
        return hash('sha256', $endpoint);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
