<?php

namespace App\Medical\Reminders;

use App\Access\AccessGate;
use App\Access\AccessLevel;
use App\Access\Module;
use App\Medical\Queries\SystemQuery;
use App\Models\OutboxItem;
use App\Models\User;
use App\Notifications\Outbox\OutboxHandler;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Send-time side of vaccination reminders. stillRelevant answers with a boolean and never throws (a thrown refusal
 * would be retried five times by SendOutboxItem): it uses AccessGate::allows and boolean system queries, never the
 * request-time Viewer.
 */
class VaccinationDueHandler implements OutboxHandler
{
    public function __construct(
        private readonly SystemQuery $system,
        private readonly AccessGate $gate,
    ) {}

    public function kind(): string
    {
        return 'vaccination_due';
    }

    public function stillRelevant(OutboxItem $item, User $user): bool
    {
        try {
            $payload = $item->payload;
            $date = $payload['date'] ?? null;
            $lead = $payload['lead'] ?? null;
            $person = $payload['person'] ?? null;
            if (! is_string($date) || ! is_int($lead) || ! is_string($person)) {
                return false;
            }

            $due = $this->system->vaccinationDue((int) $item->subject_id);
            if ($due === null || $due['next_due_on'] !== $date || $due['subject_id'] !== $person) {
                return false;
            }
            if ($date < CarbonImmutable::now()->setTimezone((string) config('family.timezone'))->format('Y-m-d')) {
                return false;
            }
            if (! in_array($lead, array_map('intval', (array) config('medical.vaccination_lead_days')), true)) {
                return false;
            }
            if ($this->system->subjectDisplayName($person) === null) {
                return false;
            }

            // Long-lived process: never trust a cached grant.
            $this->gate->forget($user);

            return $this->gate->allows($user, Module::Health, AccessLevel::Edit)
                && $this->system->isEditor($person, $user->id);
        } catch (Throwable) {
            return false;
        }
    }

    public function sendMail(OutboxItem $item, User $user): void
    {
        [$person, $name] = $this->person($item);

        $user->notifyNow(new VaccinationDueNotification($person, $name));
    }

    public function pushMessage(OutboxItem $item, User $user): array
    {
        [$person, $name] = $this->person($item);

        return [
            'title' => mb_strimwidth(VaccinationDueNotification::title($name, $user->preferredLocale() ?? 'en'), 0, 120, '…'),
            'url' => VaccinationDueNotification::url($person),
        ];
    }

    /** @return array{0: string, 1: string} */
    private function person(OutboxItem $item): array
    {
        $person = $item->payload['person'] ?? null;
        $name = is_string($person) ? $this->system->subjectDisplayName($person) : null;

        return is_string($person) && $name !== null ? [$person, $name] : throw new \RuntimeException('Person missing.');
    }
}
