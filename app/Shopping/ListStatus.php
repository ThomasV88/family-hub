<?php

namespace App\Shopping;

enum ListStatus: string
{
    case Open = 'open';
    case Locked = 'locked';
    case Done = 'done';
}
