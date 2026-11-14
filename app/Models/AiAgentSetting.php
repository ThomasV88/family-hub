<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Admin-owned configuration of one AI agent: enabled, order and model. Written only by Query classes. */
class AiAgentSetting extends Model
{
    protected $table = 'ai_agent_settings';

    protected $primaryKey = 'agent';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    /** @var list<string> */
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'position' => 'integer',
            'updated_at' => 'datetime',
        ];
    }
}
