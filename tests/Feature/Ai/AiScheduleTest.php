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

it('schedules the waiting-run resumer every minute without overlapping', function () {
    $e = aiScheduled('ai-resume-waiting');
    expect($e->expression)->toBe('* * * * *')
        ->and($e->command)->toContain('ai:resume-waiting')
        ->and($e->withoutOverlapping)->toBeTrue();
});

it('schedules the bulk tick every five minutes without overlapping, beside the resumer', function () {
    $e = aiScheduled('ai-bulk-tick');
    expect($e->expression)->toBe('*/5 * * * *')
        ->and($e->command)->toContain('ai:bulk-tick')
        ->and($e->withoutOverlapping)->toBeTrue()
        ->and(aiScheduled('ai-resume-waiting')->withoutOverlapping)->toBeTrue();
});

it('gives the job a 660 second budget below the worker timeout', function () {
    expect(config('ai.timeouts.job'))->toBe(660);
});

it('schedules the call prune daily', function () {
    $e = aiScheduled('ai-prune-calls');
    expect($e->expression)->toBe('0 0 * * *')->and($e->command)->toContain('ai:prune-calls');
});

it('runs the scheduled login check on the ai-control queue of the database connection', function () {
    Queue::fake();
    aiScheduled('ai-check-login')->run(app());

    Queue::assertPushed(CheckLoginJob::class, fn (CheckLoginJob $j) => $j->connection === 'database' && $j->queue === 'ai-control');
});

it('schedules the recipe import reaper every five minutes without overlapping and the prune daily', function () {
    $reap = aiScheduled('recipes-reap-imports');
    $prune = aiScheduled('recipes-prune-imports');

    expect($reap->expression)->toBe('*/5 * * * *')->and($reap->command)->toContain('recipes:reap-imports')->and($reap->withoutOverlapping)->toBeTrue()
        ->and($prune->expression)->toBe('0 0 * * *')->and($prune->command)->toContain('recipes:prune-imports');
});

it('schedules the upload batch tick every minute and the expiry hourly, both without overlapping', function () {
    $tick = aiScheduled('ai-upload-batch-tick');
    $expire = aiScheduled('medical-expire-upload-batches');
    expect($tick->expression)->toBe('* * * * *')
        ->and($tick->command)->toContain('ai:upload-batch-tick')
        ->and($tick->withoutOverlapping)->toBeTrue()
        ->and($expire->expression)->toBe('0 * * * *')
        ->and($expire->command)->toContain('medical:expire-upload-batches')
        ->and($expire->withoutOverlapping)->toBeTrue();
});
