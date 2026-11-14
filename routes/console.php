<?php

use App\Jobs\RecordHeartbeat;
use App\Medical\Ai\CheckLoginJob;
use Illuminate\Foundation\Inspiring;
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
Schedule::command('finance:prune-imports')->daily()->name('finance-prune-imports');
Schedule::command('medical:prune-orphans')->daily()->name('medical-prune-orphans');
Schedule::command('medical:enqueue-reminders')->hourly()->withoutOverlapping()->name('medical-enqueue-reminders');

// AI extraction maintenance (08-14): login health, stuck runs, 12-month call history.
Schedule::job(new CheckLoginJob)->everyFiveMinutes()->name('ai-check-login');
Schedule::command('ai:reap-stuck')->everyFiveMinutes()->withoutOverlapping()->name('ai-reap-stuck');
Schedule::command('ai:resume-waiting')->everyMinute()->withoutOverlapping()->name('ai-resume-waiting');
Schedule::command('ai:prune-calls')->daily()->name('ai-prune-calls');
