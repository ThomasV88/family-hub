<?php

namespace App\Audit;

use App\Access\Module;
use App\Models\AuditEntry;
use App\Models\User;
use InvalidArgumentException;

/** Writes content-free audit rows. Meta is allowlisted per event; anything else throws and nothing is written. */
class AuditLogger
{
    public function record(AuditEvent $event, ?User $actor, array $meta = [], ?User $target = null, ?Module $module = null): void
    {
        $meta = $this->validated($event, $meta);

        AuditEntry::create([
            'event' => $event->value,
            'actor_id' => $actor?->id,
            'actor_label' => $actor ? mb_substr($actor->name, 0, 120) : null,
            'target_user_id' => $target?->id,
            'target_label' => $target ? mb_substr($target->name, 0, 120) : null,
            'module' => $module?->value,
            'ip' => request()->ip(),
            'meta' => $meta === [] ? null : $meta,
        ]);
    }

    /** @return array<string, scalar|null> */
    private function validated(AuditEvent $event, array $meta): array
    {
        $allowed = $event->metaKeys();
        foreach ($meta as $key => $value) {
            if (! in_array($key, $allowed, true)) {
                throw new InvalidArgumentException("Audit meta key '{$key}' is not allowed for {$event->value}.");
            }
            if (! is_scalar($value) && $value !== null) {
                throw new InvalidArgumentException("Audit meta '{$key}' must be a scalar.");
            }
            if (is_string($value) && mb_strlen($value) > 120) {
                throw new InvalidArgumentException("Audit meta '{$key}' is longer than 120 characters.");
            }
        }

        return $meta;
    }
}
