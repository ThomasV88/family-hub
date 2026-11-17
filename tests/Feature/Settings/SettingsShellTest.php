<?php

use App\Access\AccessLevel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

it('redirects guests to login', function () {
    $this->get('/settings')->assertRedirect('/login');
    $this->get('/settings/appearance')->assertRedirect('/login');
});

it('shows a member the notifications, security and appearance sections', function () {
    $this->actingAs(User::factory()->create())->get('/settings')->assertOk()->assertInertia(fn (Assert $p) => $p
        ->component('Settings/Index')
        ->has('sections', 3)
        ->where('sections.0.key', 'notifications')
        ->where('sections.0.href', '/settings/notifications')
        ->where('sections.0.labelKey', 'notifications.nav')
        ->where('sections.1.key', 'security')
        ->where('sections.1.href', '/settings/security')
        ->where('sections.1.labelKey', 'settings.section.security')
        ->where('sections.1.icon', 'shield-check')
        ->where('sections.2.key', 'appearance')
        ->where('sections.2.href', '/settings/appearance')
        ->where('sections.2.labelKey', 'settings.section.appearance'));
});

it('also lists Shopping for a member with Shopping edit', function () {
    $user = User::factory()->create();
    $user->modulePermissions()->updateOrCreate(['module' => 'shopping'], ['level' => AccessLevel::Edit]);

    $this->actingAs($user)->get('/settings')->assertOk()->assertInertia(fn (Assert $p) => $p
        ->has('sections', 4)->where('sections.0.key', 'shopping'));
});

it('renders the appearance page with the sections', function () {
    $this->actingAs(User::factory()->admin()->create())->get('/settings/appearance')->assertOk()->assertInertia(fn (Assert $p) => $p
        ->component('Settings/Appearance')->has('sections'));
});
