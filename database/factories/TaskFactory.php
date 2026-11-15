<?php

namespace Database\Factories;

use App\Models\Task;
use App\Models\User;
use App\Tasks\TaskStatus;
use App\Tasks\TaskVisibility;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Task>
 */
class TaskFactory extends Factory
{
    protected $model = Task::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'title' => fake()->sentence(3),
            'status' => TaskStatus::Todo,
            'visibility' => TaskVisibility::Family,
            'creator_id' => User::factory(),
            'timezone' => config('family.timezone'),
        ];
    }

    public function personal(): static
    {
        return $this->state(['visibility' => TaskVisibility::Personal]);
    }

    public function family(): static
    {
        return $this->state(['visibility' => TaskVisibility::Family]);
    }

    /** @param  iterable<User>  $users */
    public function selected(iterable $users): static
    {
        $ids = collect($users)->map(fn (User $u) => $u->id)->all();

        return $this->state(['visibility' => TaskVisibility::Selected])
            ->afterCreating(fn (Task $task) => $task->members()->syncWithoutDetaching($ids));
    }

    public function dueOn(string $date, ?string $time = null): static
    {
        return $this->state(['due_date' => $date, 'due_time' => $time]);
    }

    public function done(?CarbonInterface $at = null): static
    {
        return $this->state(fn () => [
            'status' => TaskStatus::Done,
            'completed_at' => $at ?? now(),
        ]);
    }

    public function recurring(string $rule, string $mode = 'schedule'): static
    {
        return $this->state(fn (array $attrs) => [
            'series_id' => (string) Str::uuid(),
            'recurrence_rule' => $rule,
            'recurrence_mode' => $mode,
            'due_date' => $attrs['due_date'] ?? now(config('family.timezone'))->toDateString(),
        ]);
    }
}
