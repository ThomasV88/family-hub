<?php

namespace App\Medical\Ai;

enum LoginState: string
{
    case LoggedIn = 'logged_in';
    case LoggedOut = 'logged_out';
    case WrongMode = 'wrong_mode';
    case Unknown = 'unknown';
}
