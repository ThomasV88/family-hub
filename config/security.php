<?php

return [
    // Sensitive-module idle window: after this long without a Health/Finance request the member must confirm again.
    'idle_seconds' => (int) env('SECURITY_IDLE_SECONDS', 900),

    // Window in which a security action (change password, manage factors) accepts the last proof of identity.
    'confirm_window_seconds' => (int) env('SECURITY_CONFIRM_WINDOW_SECONDS', 600),

    // How long a half-finished sign-in (password passed, second factor pending) stays valid.
    'challenge_ttl_seconds' => (int) env('SECURITY_CHALLENGE_TTL_SECONDS', 600),

    'passkeys_enabled' => (bool) env('SECURITY_PASSKEYS_ENABLED', true),
];
