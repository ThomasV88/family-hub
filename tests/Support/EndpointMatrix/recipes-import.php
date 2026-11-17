<?php

use App\Models\RecipeImport;
use Illuminate\Support\Facades\Queue;

// The parent owns a failed import; the other editors see it as unknown (404). No AI agent is runnable here, so a start
// by an editor ends in the 409 "unavailable" answer (the gate and the outcomes are covered by ImportHttpTest).
$own = function (array $users) {
    Queue::fake();

    return ['import' => RecipeImport::factory()->failed()->create(['user_id' => $users['parent']->id])->id];
};

$gateOnly = fn (int $editor) => ['guest' => 302, 'admin' => 403, 'parent' => $editor, 'child' => $editor, 'grandparent' => $editor, 'guestPreset' => 403, 'noGrants' => 403];
$ownerOnly = fn (int $owner) => ['guest' => 302, 'admin' => 403, 'parent' => $owner, 'child' => 404, 'grandparent' => 404, 'guestPreset' => 403, 'noGrants' => 403];

return [
    'recipes.imports.notice' => ['method' => 'POST', 'uri' => '/recipes/imports/notice', 'data' => ['providers' => ['codex']], 'expect' => $gateOnly(204)],
    'recipes.imports.store' => [
        'method' => 'POST', 'uri' => '/recipes/imports',
        'params' => function () {
            Queue::fake();

            return [];
        },
        'data' => ['url' => 'https://recipes.example/matrix'], 'expect' => $gateOnly(409),
    ],
    'recipes.imports.show' => ['method' => 'GET', 'uri' => '/recipes/imports/{import}', 'params' => $own, 'expect' => $ownerOnly(200)],
    'recipes.imports.cancel' => ['method' => 'POST', 'uri' => '/recipes/imports/{import}/cancel', 'params' => $own, 'expect' => $ownerOnly(204)],
    'recipes.imports.destroy' => ['method' => 'DELETE', 'uri' => '/recipes/imports/{import}', 'params' => $own, 'expect' => $ownerOnly(204)],
];
