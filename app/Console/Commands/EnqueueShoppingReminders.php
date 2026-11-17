<?php

namespace App\Console\Commands;

use App\Access\AccessGate;
use App\Access\AccessLevel;
use App\Access\Module;
use App\Models\PushSubscription;
use App\Models\ShoppingList;
use App\Notifications\NotificationPreferences;
use App\Notifications\Outbox\OutboxWriter;
use App\Shopping\ListStatus;
use App\Shopping\ResponsiblePerson;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class EnqueueShoppingReminders extends Command
{
    protected $signature = 'shopping:enqueue-reminders';

    protected $description = 'Write due shopping list reminders to the notification outbox as push (safe to run twice)';

    private const BATCH = 200;

    public function handle(ResponsiblePerson $responsible, NotificationPreferences $preferences, OutboxWriter $outbox, AccessGate $gate): int
    {
        $count = DB::transaction(function () use ($responsible, $preferences, $outbox, $gate): int {
            $lists = ShoppingList::query()
                ->whereNotNull('remind_at')->where('remind_at', '<=', now())
                ->where('remind_at', '>', now()->subHours((int) config('shopping.reminder_max_age_hours')))
                ->whereNull('reminder_enqueued_at')
                ->where('status', '<>', ListStatus::Done->value)
                ->orderBy('remind_at')->limit(self::BATCH)
                ->lock('for update skip locked')
                ->get();

            $inserted = 0;
            foreach ($lists as $list) {
                $user = $responsible->effective($list);
                $at = $list->remind_at->timestamp;
                if (! ($user->canSignIn()
                    && $gate->allows($user, Module::Shopping, AccessLevel::View)
                    && $preferences->enabled($user, 'push')
                    && PushSubscription::query()->where('user_id', $user->id)->exists())) {
                    // Nothing can be sent now (push off or no subscription): the reminder stays pending, so it still
                    // goes out if push is turned on before the cutoff. After the cutoff it is never fired.
                    continue;
                }

                $outbox->enqueue(
                    'shopping_reminder', $user, 'push', 'shopping_list', $list->id,
                    "shopping_reminder:{$list->id}:{$at}:{$user->id}:push",
                    ['list_id' => $list->id, 'remind_at' => $at],
                );
                $inserted++;
                // A query update bypasses observers and does not touch updated_at.
                ShoppingList::query()->whereKey($list->id)->update(['reminder_enqueued_at' => now()]);
            }

            return $inserted;
        });

        $this->info("Enqueued {$count} shopping reminders.");

        return 0;
    }
}
