<?php

namespace App\Recurrence;

enum RecurrenceMode: string
{
    case Schedule = 'schedule';
    case Completion = 'completion';
}
