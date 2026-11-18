<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/** One uploaded document inside a batch: its place, state, adjustments and choices (Phase 15). */
class MedicalUploadBatchItem extends Model
{
    use HasUuids;

    protected $table = 'medical_upload_batch_items';

    /** @var list<string> */
    protected $guarded = ['*'];

    public function newUniqueId(): string
    {
        return (string) Str::uuid();
    }

    protected function casts(): array
    {
        return [
            'adjustments' => 'array',
            'choices' => 'array',
            'position' => 'integer',
            'rescan_count' => 'integer',
            'rescan_requested_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<MedicalUploadBatch, $this> */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(MedicalUploadBatch::class, 'batch_id');
    }
}
