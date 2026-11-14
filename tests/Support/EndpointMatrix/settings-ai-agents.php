<?php

$nonAdmins = ['parent' => 403, 'child' => 403, 'grandparent' => 403, 'guestPreset' => 403, 'noGrants' => 403];

return [
    'settings.ai_agents' => [
        'method' => 'GET',
        'uri' => '/settings/ai-agents',
        'expect' => ['guest' => 302, 'admin' => 200] + $nonAdmins,
    ],
    'settings.ai_agents.update' => [
        'method' => 'PATCH',
        'uri' => '/settings/ai-agents/codex',
        'data' => ['model' => 'gpt-5.6-sol'],
        'expect' => ['guest' => 302, 'admin' => 200] + $nonAdmins,
    ],
    'settings.ai_agents.move' => [
        'method' => 'POST',
        'uri' => '/settings/ai-agents/codex/move',
        'data' => ['direction' => 'down'],
        'expect' => ['guest' => 302, 'admin' => 200] + $nonAdmins,
    ],
];
