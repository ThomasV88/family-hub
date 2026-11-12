<?php

use Illuminate\Foundation\Inspiring;
use App\Jobs\RecordHeartbeat;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::job(new RecordHeartbeat('schedule'))->everyMinute()->name('record-heartbeat')->withoutOverlapping();
