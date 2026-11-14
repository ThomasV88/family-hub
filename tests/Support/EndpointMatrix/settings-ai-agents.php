<?php

use App\Medical\Queries\AdminAiBulkQuery;
use App\Models\AiBulkRun;
use Illuminate\Support\Facades\DB;

/** The open bulk run for a control row, created or moved to the status the row needs. */
$bulkRun = function (string $status): Closure {
    return function (array $users) use ($status): array {
        $run = DB::table('ai_bulk_runs')->whereIn('status', ['active', 'paused'])->first();
        $id = $run?->id ?? AiBulkRun::factory()->create()->id;
        DB::table('ai_bulk_runs')->where('id', $id)->update(['status' => $status, 'paused_at' => $status === 'paused' ? now() : null]);

        return ['run' => $id];
    };
};

$nonAdmins = ['parent' => 403, 'child' => 403, 'grandparent' => 403, 'guestPreset' => 403, 'noGrants' => 403];

return [
    'settings.ai_agents' => [
        'method' => 'GET',
        'uri' => '/settings/ai-agents',
        'expect' => ['guest' => 302, 'admin' => 200] + $nonAdmins,
    ],
    'settings.ai_agents.update' => [
        'method' => 'PATCH',
        'uri' => '/settings/ai-agents/codex',
        'data' => ['model' => 'gpt-5.6-sol'],
        'expect' => ['guest' => 302, 'admin' => 200] + $nonAdmins,
    ],
    'settings.ai_agents.move' => [
        'method' => 'POST',
        'uri' => '/settings/ai-agents/codex/move',
        'data' => ['direction' => 'down'],
        'expect' => ['guest' => 302, 'admin' => 200] + $nonAdmins,
    ],
    'settings.ai_agents.bulk' => [
        'method' => 'GET',
        'uri' => '/settings/ai-agents/bulk',
        'expect' => ['guest' => 302, 'admin' => 200] + $nonAdmins,
    ],
    'settings.ai_agents.bulk.dry_run' => [
        'method' => 'POST',
        'uri' => '/settings/ai-agents/bulk/dry-run',
        'data' => ['scope' => 'all', 'cap' => 5],
        'expect' => ['guest' => 302, 'admin' => 200] + $nonAdmins,
    ],
    'settings.ai_agents.bulk.start' => [
        'method' => 'POST',
        'uri' => '/settings/ai-agents/bulk',
        // A fresh dry-run token of the admin, so the success role really starts a run.
        'data' => fn (array $users) => [
            'token' => app(AdminAiBulkQuery::class)->dryRun($users['admin'], 'all', 5)['token'],
            'acknowledged' => true,
            'providers' => ['codex'],
        ],
        'expect' => ['guest' => 302, 'admin' => 201] + $nonAdmins,
    ],
    'settings.ai_agents.bulk.pause' => [
        'method' => 'POST',
        'uri' => '/settings/ai-agents/bulk/{run}/pause',
        'params' => $bulkRun('active'),
        'expect' => ['guest' => 302, 'admin' => 200] + $nonAdmins,
    ],
    'settings.ai_agents.bulk.resume' => [
        'method' => 'POST',
        'uri' => '/settings/ai-agents/bulk/{run}/resume',
        'params' => $bulkRun('paused'),
        'expect' => ['guest' => 302, 'admin' => 200] + $nonAdmins,
    ],
    'settings.ai_agents.bulk.cancel' => [
        'method' => 'POST',
        'uri' => '/settings/ai-agents/bulk/{run}/cancel',
        'params' => $bulkRun('active'),
        'expect' => ['guest' => 302, 'admin' => 200] + $nonAdmins,
    ],
];
