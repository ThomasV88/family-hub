<?php

namespace App\Tasks;

enum TaskVisibility: string
{
    case Personal = 'personal';
    case Family = 'family';
    case Selected = 'selected';
}
