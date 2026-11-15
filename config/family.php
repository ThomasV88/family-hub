<?php

return [
    // The family's wall-clock zone: due dates and times are interpreted in it. app.timezone stays UTC.
    'timezone' => env('FAMILY_TIMEZONE', 'Europe/Bratislava'),

    // Staging only (staging:seed is refused outside staging, local and testing): one shared password, at least 16
    // characters, for the synthetic @example.test accounts, so the reseed on every staging deploy keeps the same
    // login. Unset or shorter = random passwords nobody knows. Lives in the server's app.env, never in git.
    'staging_seed_password' => env('STAGING_SEED_PASSWORD'),
];
