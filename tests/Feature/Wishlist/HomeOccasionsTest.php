<?php

use App\Access\Preset;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonImmutable;

afterEach(fn () => Carbon::setTestNow());

function homeProps(User $viewer, array $headers = [])
{
    return test()->actingAs($viewer)->get('/', $headers);
}

it('gives a member with wishlist view the occasions list', function () {
    Carbon::setTestNow(CarbonImmutable::parse('2027-03-01 08:00', 'Europe/Bratislava'));
    $parent = User::factory()->withPreset(Preset::Parent)->create(['birth_date' => '1980-03-05']);

    $occasions = homeProps($parent)->assertOk()->viewData('page')['props']['occasions'];

    expect($occasions)->toBeArray()->not->toBeEmpty()
        ->and($occasions[0]['date'])->toBe('2027-03-05')
        ->and(json_encode($occasions))->not->toContain('1980');
});

it('gives an empty list when nothing falls in the next 60 days', function () {
    Carbon::setTestNow(CarbonImmutable::parse('2027-03-01 08:00', 'Europe/Bratislava'));
    $parent = User::factory()->withPreset(Preset::Parent)->create(['birth_date' => '1980-09-05']);

    expect(homeProps($parent)->viewData('page')['props']['occasions'])->toBe([]);
});

it('gives null to a member without wishlist access', function () {
    $guest = User::factory()->withPreset(Preset::Guest)->create();

    expect(homeProps($guest)->assertOk()->viewData('page')['props']['occasions'])->toBeNull();
});

it('does not evaluate the occasions prop on a partial reload of another prop', function () {
    $parent = User::factory()->withPreset(Preset::Parent)->create();
    $version = homeProps($parent)->viewData('page')['version'];

    $response = $this->actingAs($parent)->get('/', [
        'X-Inertia' => 'true', 'X-Inertia-Version' => $version,
        'X-Inertia-Partial-Component' => 'Home', 'X-Inertia-Partial-Data' => 'heartbeat',
    ])->assertOk();

    expect($response->json('props'))->not->toHaveKey('occasions')->toHaveKey('heartbeat');
});
