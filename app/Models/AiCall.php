<?php

namespace App\Models;

use Database\Factories\AiCallFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One content-free call record: ids, enums and times only. Written by Query classes in later plans, never from client
 * input. The database guards its lifecycle (see the ai_calls migration).
 */
class AiCall extends Model
{
    /** @use HasFactory<AiCallFactory> */
    use HasFactory;

    protected $table = 'ai_calls';

    public $timestamps = false;

    /** @var list<string> */
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'attempt' => 'integer',
            'duration_ms' => 'integer',
            'queued_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }
}
