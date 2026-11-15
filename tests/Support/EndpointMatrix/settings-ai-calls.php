<?php

return [
    'settings.ai_calls' => ['method' => 'GET', 'uri' => '/settings/ai-calls', 'expect' => [
        'guest' => 302, 'admin' => 200, 'parent' => 403, 'child' => 403,
        'grandparent' => 403, 'guestPreset' => 403, 'noGrants' => 403,
    ]],
    'settings.ai_calls.check' => ['method' => 'POST', 'uri' => '/settings/ai-calls/check', 'expect' => [
        'guest' => 302, 'admin' => 302, 'parent' => 403, 'child' => 403,
        'grandparent' => 403, 'guestPreset' => 403, 'noGrants' => 403,
    ]],
];
