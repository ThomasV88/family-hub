<?php

namespace App\Jobs;

use App\Models\OutboxItem;
use App\Notifications\NotificationPreferences;
use App\Notifications\Outbox\OutboxHandlers;
use App\Notifications\Outbox\OutboxStatus;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Sends one outbox row. Retry state lives in the row (visible, survives queue flushes), so the
 * queue itself never retries: $tries = 1.
 */
class SendOutboxItem implements ShouldQueue
{
    use Queueable;

    public $tries = 1;

    public const MAX_ATTEMPTS = 5;

    /** Seconds until the next attempt, indexed by the attempt that just failed minus one. */
    private const BACKOFF = [60, 300, 900, 3600];

    public function __construct(public int $outboxId) {}

    public function handle(OutboxHandlers $handlers, NotificationPreferences $preferences): void
    {
        // Atomic claim: a duplicate dispatch or a second worker updates zero rows and stops.
        $claimed = OutboxItem::query()
            ->whereKey($this->outboxId)
            ->where('status', OutboxStatus::Queued->value)
            ->update([
                'status' => OutboxStatus::Sending->value,
                'attempts' => DB::raw('attempts + 1'),
                'updated_at' => now(),
            ]);
        if ($claimed === 0) {
            return;
        }

        $item = OutboxItem::query()->with('user')->find($this->outboxId);
        if ($item === null || $item->user === null) {
            return;
        }

        try {
            $handler = $handlers->for($item->kind);

            if (! $handler->stillRelevant($item, $item->user)
                || ($item->channel === 'mail' && ! $preferences->enabled($item->user, 'mail'))) {
                $this->finish($item, OutboxStatus::Skipped);

                return;
            }

            if ($item->channel !== 'mail') {
                $this->finish($item, OutboxStatus::Failed, 'unsupported_channel');

                return;
            }

            $handler->sendMail($item, $item->user);
            $this->finish($item, OutboxStatus::Sent);
        } catch (Throwable $e) {
            $this->recordFailure($item, class_basename($e));
        }
    }

    private function finish(OutboxItem $item, OutboxStatus $status, ?string $error = null): void
    {
        $item->forceFill([
            'status' => $status,
            'sent_at' => $status === OutboxStatus::Sent ? now() : null,
            'last_error' => $error,
        ])->save();
    }

    private function recordFailure(OutboxItem $item, string $error): void
    {
        if ($item->attempts >= self::MAX_ATTEMPTS) {
            $this->finish($item, OutboxStatus::Failed, $error);

            return;
        }

        $item->forceFill([
            'status' => OutboxStatus::Pending,
            'available_at' => now()->addSeconds(self::BACKOFF[$item->attempts - 1]),
            'last_error' => $error,
        ])->save();
    }
}
