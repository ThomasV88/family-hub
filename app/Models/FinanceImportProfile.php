<?php

namespace App\Models;

use Database\Factories\FinanceImportProfileFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A saved generic column mapping. Carries no account id and no money data. Only App\Finance\Queries may use it.
 */
class FinanceImportProfile extends Model
{
    /** @use HasFactory<FinanceImportProfileFactory> */
    use HasFactory, HasUuids;

    protected $table = 'finance_import_profiles';

    /** @var list<string> */
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['settings' => 'array'];
    }
}
