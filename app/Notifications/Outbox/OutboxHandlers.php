<?php

namespace App\Notifications\Outbox;

use App\Medical\Reminders\VaccinationDueHandler;
use InvalidArgumentException;

/** Registry kind => handler class. A new kind (for example a wishlist occasion) only adds a line here. */
class OutboxHandlers
{
    /** @var array<string, class-string<OutboxHandler>> */
    private const HANDLERS = [
        'task_reminder' => TaskReminderHandler::class,
        'wish_occasion' => WishOccasionHandler::class,
        'vaccination_due' => VaccinationDueHandler::class,
    ];

    public function for(string $kind): OutboxHandler
    {
        $class = self::HANDLERS[$kind] ?? throw new InvalidArgumentException('Unknown outbox kind.');

        return app($class);
    }
}
