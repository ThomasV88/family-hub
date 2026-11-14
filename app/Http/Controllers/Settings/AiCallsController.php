<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Medical\Ai\AgentHealth;
use App\Medical\Ai\CheckLoginJob;
use App\Medical\Queries\AdminAiCallQuery;
use App\Support\SettingsSections;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Admin AI call log and login health: names, agent, model, status and times only. No credential is accepted or shown. */
class AiCallsController extends Controller
{
    public function index(Request $request, AdminAiCallQuery $query, AgentHealth $health): Response
    {
        $filters = $query->normalise($request->query());
        $page = (int) $request->query('page', 1);
        $snapshot = $health->snapshot('codex');

        return Inertia::render('Settings/AiCalls', [
            'sections' => SettingsSections::for($request->user()),
            'login' => [
                'agent' => 'codex',
                'state' => $snapshot['state'],
                'checkedAt' => $snapshot['checkedAt']?->toIso8601String(),
            ],
            'calls' => $query->page($filters, $page),
            'filters' => $filters,
            'options' => $query->options(),
        ]);
    }

    public function check(): RedirectResponse
    {
        CheckLoginJob::dispatch();

        return back()->with('status', 'ai-calls.check-queued');
    }
}
