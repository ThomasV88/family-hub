<?php

$signedIn = fn () => [
    'guest' => 302, 'admin' => 200, 'parent' => 200, 'child' => 200,
    'grandparent' => 200, 'guestPreset' => 200, 'noGrants' => 200,
];

return [
    'manual.index' => ['method' => 'GET', 'uri' => '/manual', 'expect' => $signedIn()],
];
