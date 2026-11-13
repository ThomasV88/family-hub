<?php

namespace App\Jobs;

use App\Models\OutboxItem;
use App\Models\PushSubscription;
use App\Notifications\NotificationPreferences;
use App\Notifications\Outbox\OutboxHandler;
use App\Notifications\Outbox\OutboxHandlers;
use App\Notifications\Outbox\OutboxStatus;
use App\Push\PushConfig;
use App\Push\PushResult;
use App\Push\PushSender;
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

    /**
     * Hard kill after this many seconds (10 devices at 15 s each plus slack). DispatchOutbox::STUCK_MINUTES
     * must stay well above it, otherwise a slow send would be taken for a dead one and sent twice.
     */
    public $timeout = 300;

    public const MAX_ATTEMPTS = 5;

    /** Seconds until the next attempt, indexed by the attempt that just failed minus one. */
    private const BACKOFF = [60, 300, 900, 3600];

    public function __construct(public int $outboxId) {}

    public function handle(OutboxHandlers $handlers, NotificationPreferences $preferences, ?PushSender $sender = null): void
    {
        // Atomic claim: a duplicate dispatch or a second worker updates zero rows and stops.
        $claimed = OutboxItem::query()
            ->whereKey($this->outboxId)
            ->where('status', OutboxStatus::Queued->value)
            ->where('attempts', '<', self::MAX_ATTEMPTS)
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
                || ! $preferences->enabled($item->user, $item->channel)) {
                $this->finish($item, OutboxStatus::Skipped);

                return;
            }

            if ($item->channel === 'push') {
                $this->sendPush($item, $handler, $sender ?? app(PushSender::class));

                return;
            }

            if ($item->channel !== 'mail') {
                $this->finish($item, OutboxStatus::Failed, 'unsupported_channel');

                return;
            }

            $handler->sendMail($item, $item->user);
        } catch (Throwable $e) {
            $this->recordFailure($item, class_basename($e));

            return;
        }

        // The mail is out: a failure while marking it must never be routed to recordFailure (resend).
        $this->finishAfterSend($item);
    }

    /**
     * Marks a row sent after the message left the server. Retries only the status update; if the database
     * stays unavailable the row remains 'sending' and DispatchOutbox later retries it (documented
     * at-least-once risk: delivery can repeat only when the database fails right after a successful send).
     */
    private function finishAfterSend(OutboxItem $item): void
    {
        for ($try = 1; $try <= 3; $try++) {
            try {
                $this->finish($item, OutboxStatus::Sent);

                return;
            } catch (Throwable) {
                usleep(200_000 * $try);
            }
        }
    }

    private function sendPush(OutboxItem $item, OutboxHandler $handler, PushSender $sender): void
    {
        if (! PushConfig::configured()) {
            $this->finish($item, OutboxStatus::Skipped, 'push_not_configured');

            return;
        }

        $subscriptions = PushSubscription::query()->where('user_id', $item->user_id)->get();
        if ($subscriptions->isEmpty()) {
            $this->finish($item, OutboxStatus::Skipped, 'no_subscription');

            return;
        }

        $base = $handler->pushMessage($item, $item->user);
        // Title and target only: nothing else may reach a lock screen.
        $message = ['title' => $base['title'], 'url' => $base['url'], 'tag' => substr(sha1($item->dedupe_key), 0, 16)];

        $succeeded = 0;
        $failed = 0;
        foreach ($subscriptions as $subscription) {
            $result = $sender->send($subscription, $message);
            if ($result === PushResult::Success) {
                $succeeded++;
                $this->bestEffort(fn () => $subscription->forceFill(['last_success_at' => now()])->save());
            } elseif ($result === PushResult::Expired) {
                $this->bestEffort(fn () => $subscription->delete());
            } else {
                $failed++;
            }
        }

        if ($succeeded > 0) {
            $this->finishAfterSend($item);
        } elseif ($failed > 0) {
            $this->recordFailure($item, 'push_failed');
        } else {
            $this->finish($item, OutboxStatus::Skipped, 'no_subscription');
        }
    }

    /** Bookkeeping after a delivered push must not turn the delivery into a failure that is retried. */
    private function bestEffort(callable $work): void
    {
        try {
            $work();
        } catch (Throwable) {
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
