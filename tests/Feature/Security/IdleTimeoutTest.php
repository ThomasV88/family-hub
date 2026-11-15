<?php

use App\Access\Preset;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('slides only on sensitive requests and expires after one hour of silence', function () {
    $this->useRealSensitiveSession();
    $user = User::factory()->withPreset(Preset::Parent)->create();
    $this->actingAs($user);
    $this->travelTo(now());
    $t0 = now()->getTimestamp();

    $this->withSession(['sensitive.active_at' => $t0]);

    $this->travel(59 * 60 + 59)->seconds();
    $this->get('/finance')->assertOk();

    $this->travel(59 * 60 + 59)->seconds();
    $this->get('/finance')->assertOk();

    // Activity elsewhere does not extend the window.
    $this->travel(40 * 60)->seconds();
    $this->get('/todo')->assertOk();
    $this->travel(20 * 60 + 1)->seconds();
    $this->get('/finance')->assertRedirect('/confirm-access');
});
