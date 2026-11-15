<?php

use App\Models\FinanceCategory;

$denied = [
    'guest' => 302, 'admin' => 403, 'parent' => 303, 'child' => 403,
    'grandparent' => 403, 'guestPreset' => 403, 'noGrants' => 403,
];

// A custom expense category, created per call (destroy removes it).
$custom = fn (array $users): array => ['category' => FinanceCategory::factory()->create()->id];

$other = fn (): string => FinanceCategory::query()->where('default_key', 'other')->firstOrFail()->id;

return [
    'finance.categories.index' => [
        'method' => 'GET',
        'uri' => '/finance/categories',
        'expect' => [
            'guest' => 302, 'admin' => 403, 'parent' => 200, 'child' => 403,
            'grandparent' => 403, 'guestPreset' => 403, 'noGrants' => 403,
        ],
    ],
    'finance.categories.store' => [
        'method' => 'POST',
        'uri' => '/finance/categories',
        'data' => ['name' => 'Matrix pets', 'kind' => 'expense'],
        'expect' => $denied,
    ],
    'finance.categories.update' => [
        'method' => 'PATCH',
        'uri' => '/finance/categories/{category}',
        'params' => $custom,
        'data' => ['name' => 'Matrix renamed'],
        'expect' => $denied,
    ],
    'finance.categories.destroy' => [
        'method' => 'DELETE',
        'uri' => '/finance/categories/{category}',
        'params' => $custom,
        'data' => fn (array $users): array => ['move_to' => $other()],
        'expect' => $denied,
    ],
];
