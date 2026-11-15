<?php

use App\Access\AccessLevel;
use App\Access\Module;
use App\Access\Preset;
use App\Models\User;
use App\Models\WishReminderSetting;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->withoutMiddleware(PreventRequestForgery::class);
    $this->user = User::factory()->withPreset(Preset::Parent)->create();
});

it('exposes the giver prefs prop only to members with wishlist edit', function () {
    $this->actingAs($this->user)->get('/settings/notifications')->assertInertia(fn (Assert $p) => $p
        ->where('giftReminders.lead_days', [14, 3])->where('giftReminders.allowed', [14, 7, 3, 1]));

    $viewer = User::factory()->withGrant(Module::Wishlist, AccessLevel::View)->create();
    $this->actingAs($viewer)->get('/settings/notifications')->assertInertia(fn (Assert $p) => $p->where('giftReminders', null));

    $none = User::factory()->create();
    $this->actingAs($none)->get('/settings/notifications')->assertInertia(fn (Assert $p) => $p->where('giftReminders', null));
});

it('stores lead days sorted descending with a toast and writes only the own row', function () {
    $other = User::factory()->withPreset(Preset::Parent)->create();

    $this->actingAs($this->user)->put('/settings/notifications/gifts', ['lead_days' => [1, 7], 'user_id' => $other->id])
        ->assertRedirect()->assertSessionHas('toast.key', 'notifications.saved');

    expect(WishReminderSetting::query()->find($this->user->id)->lead_days)->toBe([7, 1])
        ->and(WishReminderSetting::query()->find($other->id))->toBeNull();
});

it('accepts an empty list', function () {
    $this->actingAs($this->user)->put('/settings/notifications/gifts', ['lead_days' => []])->assertRedirect();

    expect(WishReminderSetting::query()->find($this->user->id)->lead_days)->toBe([]);
});

it('rejects values outside the allowed set, duplicates, too many entries and a missing field', function (array $payload) {
    $this->actingAs($this->user)->putJson('/settings/notifications/gifts', $payload)->assertStatus(422);

    expect(WishReminderSetting::query()->count())->toBe(0);
})->with([
    'outside' => [['lead_days' => [5]]],
    'duplicate' => [['lead_days' => [7, 7]]],
    'too many' => [['lead_days' => [14, 7, 3, 1, 1]]],
    'not a list' => [['lead_days' => 'x']],
    'missing' => [[]],
]);

it('forbids members without wishlist edit', function () {
    $viewer = User::factory()->withGrant(Module::Wishlist, AccessLevel::View)->create();

    $this->actingAs($viewer)->put('/settings/notifications/gifts', ['lead_days' => [7]])->assertForbidden();
});
