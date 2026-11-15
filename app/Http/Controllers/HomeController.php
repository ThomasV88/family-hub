<?php

namespace App\Http\Controllers;

use App\Access\AccessGate;
use App\Access\AccessLevel;
use App\Access\Module;
use App\Finance\HomeSummary;
use App\Models\SystemHeartbeat;
use App\Models\Task;
use App\Models\User;
use App\Wishlist\Occasions\UpcomingOccasions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class HomeController extends Controller
{
    private const DUE_SOON_LIMIT = 5;

    public function __invoke(Request $request, AccessGate $gate, UpcomingOccasions $occasions, HomeSummary $finance): Response
    {
        $latest = SystemHeartbeat::query()->orderByDesc('recorded_at')->first();
        $user = $request->user();

        $props = [
            'heartbeat' => [
                'last_recorded_at' => $latest?->recorded_at->utc()->toIso8601String(),
                'last_source' => $latest?->source,
                'count_24h' => SystemHeartbeat::where('recorded_at', '>=', now()->subDay())->count(),
                'expected_24h' => 1440,
            ],
            // Lazy: only evaluated when the Overview is rendered; null without To-do access.
            'dueSoon' => fn () => $user && $gate->allows($user, Module::Todo, AccessLevel::View)
                ? $this->dueSoon($user)
                : null,
            // Lazy and reservation-blind; null without wishlist view access.
            'occasions' => fn () => $user && $gate->allows($user, Module::Wishlist, AccessLevel::View)
                ? $occasions->strip()
                : null,
        ];

        // Present only with finance access and at least one visible recurring item; the key is absent otherwise.
        $card = $user ? $finance->forUser($user) : null;
        if ($card !== null) {
            $props['financeCard'] = $card;
        }

        return Inertia::render('Home', $props);
    }

    /** @return array<string, mixed> */
    private function dueSoon(User $user): array
    {
        $timezone = config('family.timezone');
        $now = now($timezone);
        $today = $now->toDateString();
        $time = $now->format('H:i:s');

        $matching = fn (): Builder => Task::query()
            ->visibleTo($user)
            ->open()
            ->where('tasks.assignee_id', $user->id)
            ->whereNotNull('tasks.due_date');

        $total = $matching()->count();

        $items = $matching()
            ->orderByRaw(
                'CASE WHEN tasks.due_date < ? OR (tasks.due_date = ? AND tasks.due_time IS NOT NULL AND tasks.due_time < ?) THEN 0 ELSE 1 END',
                [$today, $today, $time],
            )
            ->orderBy('tasks.due_date')
            ->orderByRaw('tasks.due_time IS NULL')
            ->orderBy('tasks.due_time')
            ->orderBy('tasks.id')
            ->limit(self::DUE_SOON_LIMIT)
            ->get(['tasks.id', 'tasks.title', 'tasks.due_date', 'tasks.due_time', 'tasks.visibility'])
            ->map(fn (Task $t) => [
                'id' => $t->id,
                'title' => $t->title,
                'dueDate' => $t->due_date?->format('Y-m-d'),
                'dueTime' => $t->due_time ? substr((string) $t->due_time, 0, 5) : null,
                'visibility' => $t->visibility->value,
            ])
            ->values()
            ->all();

        return [
            'items' => $items,
            'more' => max(0, $total - self::DUE_SOON_LIMIT),
            'hasAnyTasks' => $total > 0 || Task::query()->visibleTo($user)->open()->exists(),
            'today' => $today,
            'timezone' => $timezone,
        ];
    }
}
