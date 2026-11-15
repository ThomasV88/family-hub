<?php

namespace App\Wishlist\Occasions;

use App\Access\AccessGate;
use App\Access\AccessLevel;
use App\Access\Module;
use App\Models\User;
use App\Models\WishOccasion;
use App\Wishlist\WishlistMembers;
use Carbon\CarbonImmutable;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Props for Wishlist/Occasions. A birth year only ever reaches the member, a designated manager or an admin
 * (birth_date); everyone else gets {month, day}.
 */
final class OccasionsPage
{
    public function __construct(
        private readonly OccasionCalendar $calendar,
        private readonly OccasionAccess $access,
        private readonly WishlistMembers $members,
        private readonly AccessGate $gate,
    ) {}

    public function render(User $viewer): Response
    {
        return Inertia::render('Wishlist/Occasions', $this->props($viewer));
    }

    /** @return array<string, mixed> */
    public function props(User $viewer): array
    {
        $today = CarbonImmutable::now((string) config('family.timezone'))->startOfDay();
        $members = $this->members->forViewer($viewer);
        $ids = array_map(fn (array $m) => $m['id'], $members);

        $users = User::query()->whereIn('id', $ids)->get(['id', 'birth_date'])->keyBy('id');
        $namedays = WishOccasion::query()->where('kind', WishOccasion::NAMEDAY)->whereIn('owner_id', $ids)->get()->keyBy('owner_id');

        $memberRows = array_map(function (array $m) use ($viewer, $users, $namedays) {
            $birth = $users[$m['id']]->birth_date ?? null;
            $nameday = $namedays[$m['id']] ?? null;
            $canEdit = $this->access->canEditDates($viewer, $m['id']);

            return [
                'id' => $m['id'],
                'name' => $m['name'],
                'is_viewer' => $m['is_viewer'],
                'birth' => $birth === null ? null : ['month' => (int) $birth->month, 'day' => (int) $birth->day],
                'birth_date' => $canEdit && $birth !== null ? $birth->format('Y-m-d') : null,
                'nameday' => $nameday === null ? null : [
                    'calendar' => $nameday->calendar, 'name' => $nameday->name, 'month' => $nameday->month, 'day' => $nameday->day,
                ],
                'can_edit_dates' => $canEdit,
            ];
        }, $members);

        $upcoming = array_map(fn (OccasionOccurrence $o) => [
            'key' => $o->subjectType.':'.$o->subjectId.':'.$o->date->format('Y-m-d'),
            'type' => $o->type,
            'owner' => $o->ownerId === null ? null : ['id' => $o->ownerId, 'name' => $o->ownerName],
            'title' => $o->title,
            'date' => $o->date->format('Y-m-d'),
            'days_away' => $o->daysAway,
            'occasion_id' => $o->occasionId,
        ], $this->calendar->upcoming($today, 365));

        $custom = WishOccasion::query()->where('kind', WishOccasion::CUSTOM)->with('owner:id,name')
            ->orderBy('month')->orderBy('day')->orderBy('id')->get()
            ->map(fn (WishOccasion $o) => [
                'id' => $o->id,
                'title' => $o->title,
                'month' => $o->month,
                'day' => $o->day,
                'year' => $o->year,
                'owner' => $o->owner === null ? null : ['id' => $o->owner->id, 'name' => $o->owner->name],
                'can_edit' => $this->access->canEditOccasion($viewer, $o),
            ])->values()->all();

        return [
            'upcoming' => $upcoming,
            'members' => $memberRows,
            'custom' => $custom,
            'can_add' => $this->gate->allows($viewer, Module::Wishlist, AccessLevel::Edit),
            'today' => $today->format('Y-m-d'),
        ];
    }
}
