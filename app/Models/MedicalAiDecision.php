<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The reviewer's decision for one entry of a proposal. Composite key (extraction_id, entry_key), so it is read through
 * the query builder in practice. Only App\Medical\Queries may read or write this model.
 */
class MedicalAiDecision extends Model
{
    protected $table = 'medical_ai_decisions';

    public $incrementing = false;

    public $timestamps = false;

    /** @var list<string> */
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['edited' => 'boolean', 'decided_at' => 'datetime'];
    }
}
