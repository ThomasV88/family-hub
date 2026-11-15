<?php

namespace App\Console\Commands;

use App\Medical\Reminders\VaccinationReminderPlanner;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class EnqueueVaccinationReminders extends Command
{
    protected $signature = 'medical:enqueue-reminders {--force : Ignore the reminder hour}';

    protected $description = 'Write vaccination due reminders to the notification outbox (hourly, runs at the family reminder hour, safe to run twice)';

    public function handle(VaccinationReminderPlanner $planner): int
    {
        $now = CarbonImmutable::now()->setTimezone((string) config('family.timezone'));
        if (! $this->option('force') && $now->hour !== (int) config('medical.reminder_hour')) {
            $this->info('Not the reminder hour, nothing to do.');

            return 0;
        }

        $this->info('Enqueued '.$planner->run($now->format('Y-m-d')).' vaccination reminders.');

        return 0;
    }
}
