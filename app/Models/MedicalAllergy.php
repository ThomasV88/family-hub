<?php

namespace App\Models;

use Database\Factories\MedicalAllergyFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * An allergy of one medical subject. Only App\Medical\Queries may read or write this model. Random v4 uuids, no $with
 * and no $appends: nothing loads implicitly.
 */
class MedicalAllergy extends Model
{
    /** @use HasFactory<MedicalAllergyFactory> */
    use HasFactory, HasUuids;

    protected $table = 'medical_allergies';

    /** @var list<string> */
    protected $guarded = ['*'];

    public function newUniqueId(): string
    {
        return (string) Str::uuid();
    }

    protected function casts(): array
    {
        return ['verified_at' => 'datetime'];
    }
}
