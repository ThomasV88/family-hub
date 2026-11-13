<?php

namespace App\Finance\Recurring;

enum BillStatus: string
{
    case Upcoming = 'upcoming';
    case Paid = 'paid';
    case Overdue = 'overdue';
    case PaidDifferently = 'paid_differently';
}
