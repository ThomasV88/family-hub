<?php

use App\Models\SystemHeartbeat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

it('renders the Home Inertia component with the app name', function () {
    $this->get('/')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Home')
            ->where('app.name', 'Family Hub')
        );
});

it('shows empty heartbeat props when no rows exist', function () {
    $this->get('/')->assertInertia(fn (Assert $page) => $page
        ->where('heartbeat.last_recorded_at', null)
        ->where('heartbeat.last_source', null)
        ->where('heartbeat.count_24h', 0)
        ->where('heartbeat.expected_24h', 1440)
    );
});

it('counts only heartbeats of the last 24 hours and reports the newest', function () {
    $newest = now()->subMinutes(1)->startOfSecond();
    SystemHeartbeat::create(['source' => 'schedule', 'recorded_at' => now()->subHours(5)]);
    SystemHeartbeat::create(['source' => 'schedule', 'recorded_at' => now()->subHours(2)]);
    SystemHeartbeat::create(['source' => 'manual', 'recorded_at' => $newest]);
    SystemHeartbeat::create(['source' => 'schedule', 'recorded_at' => now()->subDays(2)]);

    $this->get('/')->assertInertia(fn (Assert $page) => $page
        ->where('heartbeat.count_24h', 3)
        ->where('heartbeat.last_source', 'manual')
        ->where('heartbeat.last_recorded_at', $newest->utc()->toIso8601String())
    );
});
