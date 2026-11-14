<?php

namespace App\Models;

use Database\Factories\MedicalMedicationFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * A medication of one medical subject. Only App\Medical\Queries may read or write this model. Random v4 uuids, no $with
 * and no $appends: nothing loads implicitly.
 */
class MedicalMedication extends Model
{
    /** @use HasFactory<MedicalMedicationFactory> */
    use HasFactory, HasUuids;

    protected $table = 'medical_medications';

    /** @var list<string> */
    protected $guarded = ['*'];

    public function newUniqueId(): string
    {
        return (string) Str::uuid();
    }

    protected function casts(): array
    {
        return ['verified_at' => 'datetime', 'started_on' => 'date', 'ended_on' => 'date'];
    }
}
