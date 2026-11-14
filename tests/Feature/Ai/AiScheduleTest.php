<?php

use App\Medical\Ai\CheckLoginJob;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Queue;

function aiScheduled(string $name): Event
{
    $event = collect(app(Schedule::class)->events())->first(fn (Event $e) => $e->description === $name);
    expect($event)->not->toBeNull("$name is not scheduled");

    return $event;
}

it('schedules the login check every five minutes', function () {
    expect(aiScheduled('ai-check-login')->expression)->toBe('*/5 * * * *');
});

it('schedules the stuck-run reaper every five minutes without overlapping', function () {
    $e = aiScheduled('ai-reap-stuck');
    expect($e->expression)->toBe('*/5 * * * *')
        ->and($e->command)->toContain('ai:reap-stuck')
        ->and($e->withoutOverlapping)->toBeTrue();
});

it('schedules the call prune daily', function () {
    $e = aiScheduled('ai-prune-calls');
    expect($e->expression)->toBe('0 0 * * *')->and($e->command)->toContain('ai:prune-calls');
});

it('runs the scheduled login check on the ai queue of the database connection', function () {
    Queue::fake();
    aiScheduled('ai-check-login')->run(app());

    Queue::assertPushed(CheckLoginJob::class, fn (CheckLoginJob $j) => $j->connection === 'database' && $j->queue === 'ai');
});
