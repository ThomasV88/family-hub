<?php

// VAPID identity for Web Push. Generated per environment with `php artisan push:vapid`
// and kept in the server app.env only; never commit values.
return [
    'subject' => env('VAPID_SUBJECT'),
    'public_key' => env('VAPID_PUBLIC_KEY'),
    'private_key' => env('VAPID_PRIVATE_KEY'),
];
