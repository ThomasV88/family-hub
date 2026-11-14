<?php

namespace App\Models;

use Database\Factories\MedicalSubjectFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * A person with a medical record; not necessarily an app user. Only App\Medical\Queries may read or write this model.
 * Ids are random v4 uuids so they carry no timestamp. No $with, no $appends: nothing loads implicitly.
 */
class MedicalSubject extends Model
{
    /** @use HasFactory<MedicalSubjectFactory> */
    use HasFactory, HasUuids;

    protected $table = 'medical_subjects';

    /** @var list<string> */
    protected $guarded = ['*'];

    public function newUniqueId(): string
    {
        return (string) Str::uuid();
    }

    protected function casts(): array
    {
        return [
            'birth_date' => 'date:Y-m-d',
            'self_access_enabled' => 'boolean',
            'ai_consent' => 'boolean',
            'ai_consent_changed_at' => 'datetime',
        ];
    }
}
