<?php

namespace App\Models;

use Database\Factories\MedicalContactFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * An emergency contact of one medical subject. Only App\Medical\Queries may read or write this model. Random v4 uuids,
 * no $with and no $appends: nothing loads implicitly.
 */
class MedicalContact extends Model
{
    /** @use HasFactory<MedicalContactFactory> */
    use HasFactory, HasUuids;

    protected $table = 'medical_contacts';

    /** @var list<string> */
    protected $guarded = ['*'];

    public function newUniqueId(): string
    {
        return (string) Str::uuid();
    }
}
