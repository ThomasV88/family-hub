<?php

use Illuminate\Foundation\Inspiring;
use App\Jobs\RecordHeartbeat;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::job(new RecordHeartbeat('schedule'))->everyMinute()->name('record-heartbeat')->withoutOverlapping();
Schedule::command('reminders:enqueue')->everyMinute()->name('reminders-enqueue')->withoutOverlapping();
Schedule::command('outbox:dispatch')->everyMinute()->name('outbox-dispatch')->withoutOverlapping();
Schedule::command('outbox:prune')->daily()->name('outbox-prune');
Schedule::command('occasions:enqueue')->hourly()->name('occasions-enqueue')->withoutOverlapping();
Schedule::command('wishlist:prune')->daily()->name('wishlist-prune');
