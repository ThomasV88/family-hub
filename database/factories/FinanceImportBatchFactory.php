<?php

namespace Database\Factories;

use App\Models\FinanceAccount;
use App\Models\FinanceImportBatch;
use App\Models\FinanceImportRow;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FinanceImportBatch>
 */
class FinanceImportBatchFactory extends Factory
{
    protected $model = FinanceImportBatch::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'account_id' => FinanceAccount::factory(),
            'user_id' => User::factory(),
            'status' => 'draft',
            'profile_snapshot' => FinanceImportProfileFactory::skSettings(),
            'row_count' => 0,
            'added_count' => 0,
            'matched_count' => 0,
        ];
    }

    public function forAccount(FinanceAccount $account, User $user): static
    {
        return $this->state(fn () => ['account_id' => $account->id, 'user_id' => $user->id]);
    }

    public function committed(): static
    {
        return $this->state(fn () => ['status' => 'committed', 'committed_at' => now(), 'kept_count' => 0]);
    }

    public function undone(): static
    {
        return $this->state(fn () => ['status' => 'undone', 'committed_at' => now()->subHour(), 'undone_at' => now(), 'kept_count' => 0]);
    }

    /** Adds $count staged 'new' rows (expenses of 1.00 + n, described ZxCanaryFin n). */
    public function withRows(int $count, string $description = 'ZxCanaryFin row'): static
    {
        return $this->afterCreating(function (FinanceImportBatch $batch) use ($count, $description) {
            for ($i = 1; $i <= $count; $i++) {
                (new FinanceImportRow)->forceFill([
                    'batch_id' => $batch->id,
                    'row_no' => $i + 4,
                    'booked_on' => '2026-01-'.sprintf('%02d', min($i, 28)),
                    'amount_minor' => -(100 + $i),
                    'kind' => 'expense',
                    'description' => $description.' '.$i,
                    'state' => 'new',
                    'include' => true,
                ])->save();
            }
            $batch->forceFill(['row_count' => $count])->save();
        });
    }
}
