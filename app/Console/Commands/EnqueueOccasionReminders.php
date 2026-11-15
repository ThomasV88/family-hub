<?php

namespace App\Console\Commands;

use App\Models\PushSubscription;
use App\Models\User;
use App\Notifications\NotificationPreferences;
use App\Notifications\Outbox\OutboxWriter;
use App\Wishlist\Occasions\OccasionCalendar;
use App\Wishlist\Occasions\OccasionGivers;
use App\Wishlist\Occasions\ReminderLeadDays;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class EnqueueOccasionReminders extends Command
{
    protected $signature = 'occasions:enqueue';

    protected $description = 'Write gift reminders for upcoming occasions to the notification outbox (hourly, safe to run twice)';

    public function handle(OccasionCalendar $calendar, OccasionGivers $givers, ReminderLeadDays $leads, NotificationPreferences $preferences, OutboxWriter $outbox): int
    {
        $now = CarbonImmutable::now()->setTimezone((string) config('family.timezone'));
        if ($now->hour < (int) config('wishlist.reminder_hour')) {
            $this->info('Before reminder hour, nothing to do.');

            return 0;
        }

        /** @var array<int, list<int>> $leadCache */
        $leadCache = [];
        /** @var array<int, list<string>> $channelCache */
        $channelCache = [];
        $inserted = 0;

        foreach ($calendar->upcoming($now->startOfDay(), max(config('wishlist.allowed_lead_days')) + 1) as $occurrence) {
            $date = $occurrence->date->format('Y-m-d');
            foreach ($givers->for($occurrence) as $user) {
                $days = $leadCache[$user->id] ??= $leads->for($user);
                $channels = $channelCache[$user->id] ??= $this->channels($user, $preferences);

                foreach ($days as $lead) {
                    // Fire on D-lead, one day of grace after an outage, never after D.
                    if ($occurrence->daysAway > $lead || $occurrence->daysAway < $lead - 1 || $occurrence->daysAway < 0) {
                        continue;
                    }
                    foreach ($channels as $channel) {
                        $inserted += $outbox->enqueue(
                            'wish_occasion', $user, $channel, $occurrence->subjectType, $occurrence->subjectId,
                            "wish_occasion:{$occurrence->type}:{$occurrence->subjectId}:{$date}:{$lead}:{$user->id}:{$channel}",
                            ['type' => $occurrence->subjectType, 'subject_id' => $occurrence->subjectId, 'date' => $date, 'lead' => $lead],
                        ) ? 1 : 0;
                    }
                }
            }
        }

        $this->info("Enqueued {$inserted} gift reminders.");

        return 0;
    }

    /** @return list<string> */
    private function channels(User $user, NotificationPreferences $preferences): array
    {
        $channels = [];
        if ($preferences->enabled($user, 'mail')) {
            $channels[] = 'mail';
        }
        if ($preferences->enabled($user, 'push') && PushSubscription::query()->where('user_id', $user->id)->exists()) {
            $channels[] = 'push';
        }

        return $channels;
    }
}
