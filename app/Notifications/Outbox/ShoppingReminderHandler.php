<?php

namespace App\Notifications\Outbox;

use App\Access\AccessGate;
use App\Access\AccessLevel;
use App\Access\Module;
use App\Models\OutboxItem;
use App\Models\ShoppingList;
use App\Models\User;
use App\Shopping\ListStatus;
use App\Shopping\ResponsiblePerson;
use LogicException;

/** Push-only reminder for a shopping list; the payload holds ids and a timestamp, the text is built at send time. */
class ShoppingReminderHandler implements OutboxHandler
{
    public function __construct(
        private readonly ResponsiblePerson $responsible,
        private readonly AccessGate $gate,
    ) {}

    public function kind(): string
    {
        return 'shopping_reminder';
    }

    public function stillRelevant(OutboxItem $item, User $user): bool
    {
        $list = $this->list($item);
        $at = $item->payload['remind_at'] ?? null;

        return $list !== null
            && $list->status !== ListStatus::Done
            && is_int($at)
            && $list->remind_at?->timestamp === $at
            && $this->responsible->effective($list)->id === $user->id
            && $user->canSignIn()
            && $this->gate->allows($user, Module::Shopping, AccessLevel::View);
    }

    public function sendMail(OutboxItem $item, User $user): void
    {
        throw new LogicException('Shopping reminders are push only.');
    }

    public function pushMessage(OutboxItem $item, User $user): array
    {
        $list = $this->list($item) ?? throw new \RuntimeException('List missing.');
        $locale = $user->preferredLocale() ?? 'en';
        $name = mb_strimwidth($list->name, 0, 80, '…');
        $store = $list->store?->name;

        return [
            'title' => trans('notifications.shopping_reminder_title', [], $locale),
            'body' => $store === null || $store === ''
                ? trans('notifications.shopping_reminder_body_no_store', ['list' => $name], $locale)
                : trans('notifications.shopping_reminder_body', ['list' => $name, 'store' => mb_strimwidth($store, 0, 60, '…')], $locale),
            'url' => '/shopping/lists/'.$list->id,
        ];
    }

    private function list(OutboxItem $item): ?ShoppingList
    {
        return ShoppingList::query()->with('store')->find((int) ($item->payload['list_id'] ?? 0));
    }
}
