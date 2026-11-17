<?php

namespace App\Shopping;

enum ItemState: string
{
    case Pending = 'pending';
    case Bought = 'bought';
    case Unavailable = 'unavailable';
}
