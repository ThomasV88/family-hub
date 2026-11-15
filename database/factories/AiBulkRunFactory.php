<?php

namespace Database\Factories;

use App\Models\AiBulkRun;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Rows only. Default state is active.
 *
 * @extends Factory<AiBulkRun>
 */
class AiBulkRunFactory extends Factory
{
    protected $model = AiBulkRun::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'started_by' => 1, 'scope' => 'no_proposal', 'daily_cap' => 20, 'status' => 'active',
            'dry_run_id' => (string) Str::uuid(), 'eligible_total' => 10, 'skipped_count' => 0,
        ];
    }

    public function active(): static
    {
        return $this->state(fn () => ['status' => 'active']);
    }

    public function paused(): static
    {
        return $this->state(fn () => ['status' => 'paused', 'paused_at' => now()]);
    }

    public function cancelled(): static
    {
        return $this->state(fn () => ['status' => 'cancelled', 'finished_at' => now()]);
    }

    public function completed(): static
    {
        return $this->state(fn () => ['status' => 'completed', 'finished_at' => now()]);
    }
}
