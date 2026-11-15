<?php

namespace App\Models;

use Database\Factories\MedicalAiExtractionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * One AI run over one document: its status and, when ready, the proposed entries. A proposal never touches the record
 * tables. Only App\Medical\Queries may read or write this model.
 */
class MedicalAiExtraction extends Model
{
    /** @use HasFactory<MedicalAiExtractionFactory> */
    use HasFactory, HasUuids;

    protected $table = 'medical_ai_extractions';

    /** @var list<string> */
    protected $guarded = ['*'];

    public function newUniqueId(): string
    {
        return (string) Str::uuid();
    }

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'schema_version' => 'integer',
            'prompt_version' => 'integer',
            'entry_count' => 'integer',
            'pages_read' => 'integer',
            'pages_total' => 'integer',
            'cancel_requested_at' => 'datetime',
            'queued_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }
}
