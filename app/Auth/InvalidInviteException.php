<?php

namespace App\Auth;

use RuntimeException;

/** The invitation is expired, used, revoked or its email already has an account (message key: auth.error.invite_invalid). */
class InvalidInviteException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('auth.error.invite_invalid');
    }
}
