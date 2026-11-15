<?php

namespace App\Console\Commands;

use App\Models\PushSubscription;
use App\Models\Task;
use App\Notifications\NotificationPreferences;
use App\Notifications\Outbox\OutboxWriter;
use App\Tasks\ReminderRecipients;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class EnqueueReminders extends Command
{
    protected $signature = 'reminders:enqueue';

    protected $description = 'Write due task reminders to the notification outbox (safe to run twice)';

    private const BATCH = 200;

    public function handle(ReminderRecipients $recipients, NotificationPreferences $preferences, OutboxWriter $outbox): int
    {
        $count = DB::transaction(function () use ($recipients, $preferences, $outbox): int {
            $tasks = Task::query()
                ->whereNotNull('remind_at')->where('remind_at', '<=', now())
                ->whereNull('completed_at')->whereNull('skipped_at')
                ->orderBy('remind_at')->limit(self::BATCH)
                ->lock('for update skip locked')
                ->get();

            $inserted = 0;
            foreach ($tasks as $task) {
                foreach ($recipients->for($task) as $user) {
                    $at = $task->remind_at->timestamp;
                    $channels = [];
                    if ($preferences->enabled($user, 'mail')) {
                        $channels[] = 'mail';
                    }
                    if ($preferences->enabled($user, 'push') && PushSubscription::query()->where('user_id', $user->id)->exists()) {
                        $channels[] = 'push';
                    }
                    foreach ($channels as $channel) {
                        $inserted += $outbox->enqueue(
                            'task_reminder', $user, $channel, 'task', $task->id,
                            "task_reminder:{$task->id}:{$at}:{$user->id}:{$channel}",
                            ['task_id' => $task->id, 'remind_at' => $at],
                        ) ? 1 : 0;
                    }
                }
                // Consumed. A query update bypasses the observer.
                Task::query()->whereKey($task->id)->update(['remind_at' => null]);
            }

            return $inserted;
        });

        $this->info("Enqueued {$count} reminders.");

        return 0;
    }
}
