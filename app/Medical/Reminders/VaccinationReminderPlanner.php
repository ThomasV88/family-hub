<?php

namespace App\Medical\Reminders;

use App\Access\AccessGate;
use App\Access\AccessLevel;
use App\Access\Module;
use App\Medical\Queries\SystemQuery;
use App\Models\PushSubscription;
use App\Models\User;
use App\Notifications\NotificationPreferences;
use App\Notifications\Outbox\OutboxWriter;
use Carbon\CarbonImmutable;

/**
 * Writes vaccination due reminders to the outbox. Recipients are only members who can edit that person. Payloads
 * carry ids and dates, never names or vaccine data.
 */
class VaccinationReminderPlanner
{
    public function __construct(
        private readonly SystemQuery $system,
        private readonly AccessGate $gate,
        private readonly NotificationPreferences $preferences,
        private readonly OutboxWriter $outbox,
    ) {}

    /** @param  string  $today  family-timezone date, Y-m-d */
    public function run(string $today): int
    {
        /** @var list<int> $leads */
        $leads = array_values(array_map('intval', (array) config('medical.vaccination_lead_days')));
        if ($leads === []) {
            return 0;
        }

        $start = CarbonImmutable::createFromFormat('Y-m-d', $today, 'UTC')->startOfDay();
        $to = $start->addDays(max($leads) + 1)->format('Y-m-d');
        $inserted = 0;
        /** @var array<int, ?User> $users */
        $users = [];
        /** @var array<int, list<string>> $channelCache */
        $channelCache = [];

        foreach ($this->system->dueVaccinations($today, $to) as $vaccination) {
            $date = $vaccination['next_due_on'];
            $daysAway = (int) $start->diffInDays(CarbonImmutable::createFromFormat('Y-m-d', $date, 'UTC')->startOfDay(), false);
            if ($daysAway < 0) {
                continue;
            }

            foreach ($this->system->reminderCandidateIds($vaccination['subject_id']) as $userId) {
                $user = array_key_exists($userId, $users) ? $users[$userId] : ($users[$userId] = User::query()->find($userId));
                if ($user === null) {
                    continue;
                }
                // Long-lived process: never trust a cached grant.
                $this->gate->forget($user);
                if (! $this->gate->allows($user, Module::Health, AccessLevel::Edit)) {
                    continue;
                }
                $channels = $channelCache[$userId] ??= $this->channels($user);

                foreach ($leads as $lead) {
                    // Fire on D-lead, one day of grace after an outage, never after D.
                    if ($daysAway > $lead || $daysAway < $lead - 1) {
                        continue;
                    }
                    foreach ($channels as $channel) {
                        $inserted += $this->outbox->enqueue(
                            'vaccination_due', $user, $channel, 'vaccination', $vaccination['id'],
                            "vaccination_due:{$vaccination['id']}:{$date}:{$lead}:{$userId}:{$channel}",
                            ['person' => $vaccination['subject_id'], 'vaccination' => $vaccination['public_id'], 'date' => $date, 'lead' => $lead],
                        ) ? 1 : 0;
                    }
                }
            }
        }

        return $inserted;
    }

    /** @return list<string> */
    private function channels(User $user): array
    {
        $channels = [];
        if ($this->preferences->enabled($user, 'mail')) {
            $channels[] = 'mail';
        }
        if ($this->preferences->enabled($user, 'push') && PushSubscription::query()->where('user_id', $user->id)->exists()) {
            $channels[] = 'push';
        }

        return $channels;
    }
}
