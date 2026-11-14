<?php

namespace App\Models;

use Database\Factories\MedicalDocumentFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * A stored document (PDF, image or HEIC) of one medical subject, optionally attached to one of that subject's visits.
 * Only App\Medical\Queries may read or write this model. Storage details never leave through a model dump.
 */
class MedicalDocument extends Model
{
    /** @use HasFactory<MedicalDocumentFactory> */
    use HasFactory, HasUuids;

    protected $table = 'medical_documents';

    /** @var list<string> */
    protected $guarded = ['*'];

    /** @var list<string> */
    protected $hidden = ['path', 'thumb_path', 'sha256', 'uploaded_by'];

    public function newUniqueId(): string
    {
        return (string) Str::uuid();
    }

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'verified_at' => 'datetime',
        ];
    }
}
