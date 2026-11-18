<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/** One resumable upload batch of one person (Phase 15). Only App\Medical\Queries may read or write this model. */
class MedicalUploadBatch extends Model
{
    use HasUuids;

    protected $table = 'medical_upload_batches';

    /** @var list<string> */
    protected $guarded = ['*'];

    public function newUniqueId(): string
    {
        return (string) Str::uuid();
    }

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /** @return HasMany<MedicalUploadBatchItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(MedicalUploadBatchItem::class, 'batch_id');
    }
}
