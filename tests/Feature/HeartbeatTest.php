<?php

use App\Jobs\RecordHeartbeat;
use App\Models\SystemHeartbeat;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

afterEach(fn () => CarbonImmutable::setTestNow());

it('records a schedule heartbeat row with the current time', function () {
    $now = CarbonImmutable::parse('2026-11-13 10:00:00', 'UTC');
    CarbonImmutable::setTestNow($now);
    Illuminate\Support\Carbon::setTestNow($now);

    (new RecordHeartbeat('schedule'))->handle();

    $row = SystemHeartbeat::query()->sole();
    expect($row->source)->toBe('schedule');
    expect($row->recorded_at->equalTo($now))->toBeTrue();
});

it('prunes rows older than 7 days and keeps newer ones', function () {
    $now = CarbonImmutable::parse('2026-11-13 10:00:00', 'UTC');
    Illuminate\Support\Carbon::setTestNow($now);
    SystemHeartbeat::create(['source' => 'schedule', 'recorded_at' => $now->subDays(8)]);
    SystemHeartbeat::create(['source' => 'schedule', 'recorded_at' => $now->subDays(6)]);

    (new RecordHeartbeat('manual'))->handle();

    expect(SystemHeartbeat::count())->toBe(2);
    expect(SystemHeartbeat::where('recorded_at', '<', $now->subDays(7))->count())->toBe(0);
});

it('rejects an unknown heartbeat source', function () {
    new RecordHeartbeat('evil');
})->throws(InvalidArgumentException::class);

it('queues a manual heartbeat and redirects home with 303', function () {
    Queue::fake();

    $this->post('/heartbeat')->assertStatus(303)->assertRedirect(route('home'));

    Queue::assertPushed(RecordHeartbeat::class, fn ($job) => $job->source === 'manual');
});

it('throttles the 7th heartbeat request within a minute with 429', function () {
    Queue::fake();

    foreach (range(1, 6) as $i) {
        $this->post('/heartbeat')->assertStatus(303);
    }
    $this->post('/heartbeat')->assertStatus(429);
});

it('schedules RecordHeartbeat every minute', function () {
    $events = collect(app(Schedule::class)->events())
        ->filter(fn ($e) => str_contains((string) $e->description, 'record-heartbeat')
            || str_contains((string) $e->command, 'RecordHeartbeat'));

    expect($events)->toHaveCount(1);
    expect($events->first()->expression)->toBe('* * * * *');
});
