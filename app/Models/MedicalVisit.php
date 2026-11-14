<?php

namespace App\Models;

use Database\Factories\MedicalVisitFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * A doctor visit of one medical subject. Only App\Medical\Queries may read or write this model. Random v4 uuids, no
 * $with and no $appends: nothing loads implicitly.
 */
class MedicalVisit extends Model
{
    /** @use HasFactory<MedicalVisitFactory> */
    use HasFactory, HasUuids;

    protected $table = 'medical_visits';

    /** @var list<string> */
    protected $guarded = ['*'];

    public function newUniqueId(): string
    {
        return (string) Str::uuid();
    }

    protected function casts(): array
    {
        return [
            'visited_on' => 'date:Y-m-d',
            'verified_at' => 'datetime',
        ];
    }
}
