<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Login health of one AI agent (for example codex): a state and times, nothing else. */
class AiAgentStatus extends Model
{
    protected $table = 'ai_agent_status';

    protected $primaryKey = 'agent';

    public $incrementing = false;

    protected $keyType = 'string';

    /** @var list<string> */
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'checked_at' => 'datetime',
            'last_success_at' => 'datetime',
            'last_auth_failure_at' => 'datetime',
        ];
    }
}
