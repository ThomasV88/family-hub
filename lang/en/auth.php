<?php

// Values are i18n keys, not prose: Fortify reads trans('auth.failed') and the Vue pages
// translate the returned key with vue-i18n. One generic key covers every failure cause so
// the message never reveals whether an email exists.
return [
    'failed' => 'auth.error.failed',
    'password' => 'auth.error.failed',
    'throttle' => 'auth.error.throttle',
];
