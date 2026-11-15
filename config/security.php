<?php

use App\Security\ClampedSeconds;

return [
    // These two are the defaults; an admin can override them in Settings (App\Security\SecurityTimings).
    // Sensitive-module idle window: after this long without a Health/Finance request the member must confirm again.
    // Clamped to 60..86400; a malformed value falls back to 3600 (App\Security\ClampedSeconds).
    'idle_seconds' => ClampedSeconds::from(env('SECURITY_IDLE_SECONDS'), 3600, 60, 86400),

    // Window in which a security action (change password, manage factors) accepts the last proof of identity.
    // Clamped to 60..3600; a malformed value falls back to 3600.
    'confirm_window_seconds' => ClampedSeconds::from(env('SECURITY_CONFIRM_WINDOW_SECONDS'), 3600, 60, 3600),

    // How long a half-finished sign-in (password passed, second factor pending) stays valid.
    'challenge_ttl_seconds' => (int) env('SECURITY_CHALLENGE_TTL_SECONDS', 600),

    'passkeys_enabled' => (bool) env('SECURITY_PASSKEYS_ENABLED', true),
];
