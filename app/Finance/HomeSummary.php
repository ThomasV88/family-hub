<?php

namespace App\Finance;

use App\Access\AccessGate;
use App\Access\AccessLevel;
use App\Access\Module;
use App\Finance\Queries\RecurringQuery;
use App\Models\User;

/** The Home "Still to pay" card: counts only, on visible accounts only, null when it does not apply. */
class HomeSummary
{
    public function __construct(
        private readonly AccessGate $gate,
        private readonly RecurringQuery $recurring,
    ) {}

    /** @return array{paymentsCount: int, overdueCount: int}|null */
    public function forUser(User $user): ?array
    {
        if (! $this->gate->allows($user, Module::Finance, AccessLevel::View)) {
            return null;
        }

        $counts = $this->recurring->homeCounts(Viewer::for($user));
        if (! $counts['hasRecurring']) {
            return null;
        }

        return ['paymentsCount' => $counts['paymentsCount'], 'overdueCount' => $counts['overdueCount']];
    }
}
