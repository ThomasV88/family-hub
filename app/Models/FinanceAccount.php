<?php

namespace App\Models;

use Database\Factories\FinanceAccountFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Only App\Finance\Queries may read or write this model (arch and scan tests). Attributes are set with forceFill.
 */
class FinanceAccount extends Model
{
    /** @use HasFactory<FinanceAccountFactory> */
    use HasFactory, HasUuids;

    protected $table = 'finance_accounts';

    /** @var list<string> */
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'opening_balance_minor' => 'integer',
            'balance_as_of' => 'date:Y-m-d',
        ];
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'finance_account_members', 'account_id', 'user_id');
    }
}
