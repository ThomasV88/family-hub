<?php

namespace App\Audit;

use App\Models\AuditEntry;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/** Filtered, paginated read of the audit log. Filter values are validated by the caller and always bound. */
class AuditQuery
{
    public const PER_PAGE = 25;

    /** @param array{member?: ?int, event?: ?string, from?: ?string, to?: ?string} $filters */
    public function __construct(private readonly array $filters = []) {}

    public function builder(): Builder
    {
        $query = AuditEntry::query()->orderByDesc('occurred_at')->orderByDesc('id');

        if ($member = $this->filters['member'] ?? null) {
            $query->where(fn (Builder $q) => $q->where('actor_id', $member)->orWhere('target_user_id', $member));
        }

        if (($group = $this->filters['event'] ?? null) && in_array($group, AuditEvent::GROUPS, true)) {
            ['events' => $events, 'deniedModule' => $denied] = AuditEvent::eventsForGroup($group);
            $query->where(function (Builder $q) use ($events, $denied) {
                $q->whereIn('event', $events);
                if ($denied !== null) {
                    $q->orWhere(fn (Builder $d) => $d->where('event', AuditEvent::AccessDenied->value)->where('module', $denied));
                }
            });
        }

        $tz = config('app.timezone');
        if ($from = $this->filters['from'] ?? null) {
            $query->where('occurred_at', '>=', CarbonImmutable::parse($from, $tz)->startOfDay());
        }
        if ($to = $this->filters['to'] ?? null) {
            $query->where('occurred_at', '<=', CarbonImmutable::parse($to, $tz)->endOfDay());
        }

        return $query;
    }

    public function paginate(): LengthAwarePaginator
    {
        return $this->builder()->paginate(self::PER_PAGE)->withQueryString();
    }
}
