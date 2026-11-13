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

    public function handle(): int
    {
        $now = now()->toIso8601String();

        // Rows stuck in queued/sending (worker died) go back to pending.
        DB::update(
            "update notification_outbox set status = 'pending', updated_at = ? where status in ('queued', 'sending') and updated_at < ?",
            [$now, now()->subMinutes(10)->toIso8601String()],
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
