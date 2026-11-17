<?php

use App\Access\AccessLevel;
use App\Access\Module;
use App\Models\User;
use App\Security\SecondFactorPolicy;

it('never applies the second-factor requirement to a child', function () {
    $policy = app(SecondFactorPolicy::class);
    $policy->setRequired(true);

    $child = User::factory()->child()->withGrant(Module::Health, AccessLevel::Edit)->create();
    $member = User::factory()->withGrant(Module::Health, AccessLevel::Edit)->create();

    expect($policy->appliesTo($child))->toBeFalse()
        ->and($policy->mustEnrol($child))->toBeFalse()
        ->and($policy->appliesTo($member))->toBeTrue()
        ->and($policy->pendingCount())->toBe(1);
});
