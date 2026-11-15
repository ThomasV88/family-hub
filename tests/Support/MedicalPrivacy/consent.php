<?php

// AI consent area: no seeds of its own (the route takes only the person id).
return [
    'payloads' => [
        'health.people.ai_consent.update' => fn (array $ctx): array => ['value' => true],
    ],
];
