<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

it('redirects guests to login', function () {
    $this->get('/settings')->assertRedirect('/login');
    $this->get('/settings/appearance')->assertRedirect('/login');
});

it('shows a member only the appearance section', function () {
    $this->actingAs(User::factory()->create())->get('/settings')->assertOk()->assertInertia(fn (Assert $p) => $p
        ->component('Settings/Index')
        ->has('sections', 1)
        ->where('sections.0.key', 'appearance')
        ->where('sections.0.href', '/settings/appearance')
        ->where('sections.0.labelKey', 'settings.section.appearance'));
});

it('renders the appearance page with the sections', function () {
    $this->actingAs(User::factory()->admin()->create())->get('/settings/appearance')->assertOk()->assertInertia(fn (Assert $p) => $p
        ->component('Settings/Appearance')->has('sections'));
});
