<?php

namespace App\Http\Controllers;

use App\Models\SystemHeartbeat;
use Inertia\Inertia;
use Inertia\Response;

class HomeController extends Controller
{
    public function __invoke(): Response
    {
        $latest = SystemHeartbeat::query()->orderByDesc('recorded_at')->first();

        return Inertia::render('Home', [
            'heartbeat' => [
                'last_recorded_at' => $latest?->recorded_at->utc()->toIso8601String(),
                'last_source' => $latest?->source,
                'count_24h' => SystemHeartbeat::where('recorded_at', '>=', now()->subDay())->count(),
                'expected_24h' => 1440,
            ],
        ]);
    }
}
