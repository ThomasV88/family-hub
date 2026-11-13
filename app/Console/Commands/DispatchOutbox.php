<?php

namespace App\Console\Commands;

use App\Jobs\SendOutboxItem;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DispatchOutbox extends Command
{
    protected $signature = 'outbox:dispatch';

    protected $description = 'Queue a send job for every due outbox row';

    private const BATCH = 100;

    /** Well above SendOutboxItem::$timeout (300 s), so a slow send is never mistaken for a dead worker. */
    public const STUCK_MINUTES = 30;

    public function handle(): int
    {
        $now = now()->toIso8601String();

        $stuckBefore = now()->subMinutes(self::STUCK_MINUTES)->toIso8601String();

        // Stuck rows that already used up their attempts are failed, never retried forever.
        DB::update(
            "update notification_outbox set status = 'failed', last_error = 'stuck', updated_at = ? where status in ('queued', 'sending') and updated_at < ? and attempts >= ?",
            [$now, $stuckBefore, SendOutboxItem::MAX_ATTEMPTS],
        );
        // The others (worker died) go back to pending.
        DB::update(
            "update notification_outbox set status = 'pending', updated_at = ? where status in ('queued', 'sending') and updated_at < ? and attempts < ?",
            [$now, $stuckBefore, SendOutboxItem::MAX_ATTEMPTS],
        );

        $ids = DB::select(
            "update notification_outbox set status = 'queued', updated_at = ? where id in (
                select id from notification_outbox where status = 'pending' and available_at <= ?
                order by available_at, id limit ".self::BATCH.' for update skip locked
            ) returning id',
            [$now, $now],
        );

        foreach ($ids as $row) {
            SendOutboxItem::dispatch((int) $row->id);
        }

        $this->info('Dispatched '.count($ids).' outbox rows.');

        return 0;
    }
}
