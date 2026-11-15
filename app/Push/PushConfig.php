<?php

namespace App\Push;

class PushConfig
{
    /** Configured means subject, public key and private key are all non-empty. */
    public static function configured(): bool
    {
        return filled(config('push.subject')) && filled(config('push.public_key')) && filled(config('push.private_key'));
    }
}
