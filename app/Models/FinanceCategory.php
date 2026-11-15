<?php

namespace App\Models;

use Database\Factories\FinanceCategoryFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/** Family-wide, non-sensitive category list. Attributes are set with forceFill. */
class FinanceCategory extends Model
{
    /** @use HasFactory<FinanceCategoryFactory> */
    use HasFactory, HasUuids;

    protected $table = 'finance_categories';

    /** @var list<string> */
    protected $guarded = ['*'];
}
