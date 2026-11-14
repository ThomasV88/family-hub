<?php

namespace App\Models;

use Database\Factories\MedicalVaccinationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * A vaccination of one medical subject. Only App\Medical\Queries may read or write this model. The bigint id is internal
 * (outbox and audit subject ids are bigint) and hidden; the public_id uuid is the only id that leaves the server. No
 * route key binding: controllers resolve the public id explicitly inside the visible person.
 */
class MedicalVaccination extends Model
{
    /** @use HasFactory<MedicalVaccinationFactory> */
    use HasFactory;

    protected $table = 'medical_vaccinations';

    /** @var list<string> */
    protected $guarded = ['*'];

    /** @var list<string> */
    protected $hidden = ['id'];

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            if (empty($model->public_id)) {
                $model->public_id = (string) Str::uuid();
            }
        });
    }

    protected function casts(): array
    {
        return ['verified_at' => 'datetime', 'given_on' => 'date', 'next_due_on' => 'date'];
    }
}
