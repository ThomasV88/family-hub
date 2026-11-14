<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Medical\Ai\AgentHealth;
use App\Medical\Ai\ModelId;
use App\Medical\Queries\AdminAiBulkQuery;
use App\Medical\Queries\AiAgentSettingsQuery;
use App\Support\SettingsSections;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Inertia\Inertia;
use Inertia\Response;

/** Admin AI agent settings: enable, order and model per agent plus login health. No credential is accepted or shown. */
final class AiAgentsController extends Controller
{
    public function index(Request $request, AiAgentSettingsQuery $settings, AgentHealth $health, AdminAiBulkQuery $bulk): Response
    {
        return Inertia::render('Settings/AiAgents', [
            'sections' => SettingsSections::for($request->user()),
            'agents' => $this->agents($settings, $health),
            'providers' => $settings->enabledKeys(),
            'checkRoute' => 'settings.ai_calls.check',
            'bulk' => $bulk->panel(),
            'bulkConfig' => [
                'defaultCap' => (int) config('ai.bulk.default_cap'),
                'maxCap' => (int) config('ai.bulk.max_cap'),
                'startHour' => (int) config('ai.bulk.start_hour'),
                'timezone' => (string) config('ai.local_timezone'),
                'validMinutes' => (int) config('ai.bulk.dry_run_ttl_minutes'),
            ],
        ]);
    }

    /** Counts-only panel for polling. */
    public function bulk(AdminAiBulkQuery $bulk): JsonResponse
    {
        return $this->json($bulk->panel());
    }

    public function bulkDryRun(Request $request, AdminAiBulkQuery $bulk): JsonResponse
    {
        $valid = $request->validate([
            'scope' => ['required', 'string', 'in:no_proposal,last_failed,all'],
            'cap' => ['required', 'integer', 'min:1', 'max:'.(int) config('ai.bulk.max_cap')],
        ]);

        return $this->json($bulk->dryRun($request->user(), $valid['scope'], (int) $valid['cap']));
    }

    public function bulkStart(Request $request, AdminAiBulkQuery $bulk): JsonResponse
    {
        $valid = $request->validate([
            'token' => ['required', 'uuid'],
            'acknowledged' => ['accepted'],
            // The provider keys the confirm dialog named; the server compares them with the enabled set.
            'providers' => ['required', 'array', 'min:1', 'max:5'],
            'providers.*' => ['string', 'in:codex,copilot'],
        ]);

        $result = $bulk->start($request->user(), $valid['token'], true, array_values($valid['providers']));

        return match ($result['result']) {
            'ok' => $this->json(['panel' => $result['panel']], 201),
            'active' => $this->json(['reason' => 'active', 'panel' => $result['panel']], 409),
            'stale' => $this->json(['reason' => 'stale'], 409),
            'providers_changed' => $this->json(['reason' => 'providers_changed', 'providers' => $result['providers'] ?? []], 409),
            default => $this->json(['reason' => 'expired'], 410),
        };
    }

    public function bulkPause(Request $request, AdminAiBulkQuery $bulk, string $run): JsonResponse
    {
        return $this->control($bulk, $bulk->pause($request->user(), $run));
    }

    public function bulkResume(Request $request, AdminAiBulkQuery $bulk, string $run): JsonResponse
    {
        return $this->control($bulk, $bulk->resume($request->user(), $run));
    }

    public function bulkCancel(Request $request, AdminAiBulkQuery $bulk, string $run): JsonResponse
    {
        return $this->control($bulk, $bulk->cancel($request->user(), $run));
    }

    /** @param array{result: string, panel?: array<string, mixed>} $result */
    private function control(AdminAiBulkQuery $bulk, array $result): JsonResponse
    {
        abort_if($result['result'] === 'missing', 404);

        return $result['result'] === 'ok'
            ? $this->json(['panel' => $result['panel']])
            : $this->json(['reason' => 'conflict', 'panel' => $bulk->panel()], 409);
    }

    public function update(Request $request, AiAgentSettingsQuery $settings, AgentHealth $health, string $agent): JsonResponse
    {
        $this->known($agent);

        $data = $request->all();
        $hasModel = array_key_exists('model', $data);
        if ($hasModel && is_string($data['model']) && trim($data['model']) === '') {
            $data['model'] = null;
        }
        $rules = [
            'enabled' => ['sometimes', 'boolean'],
            'model' => ['sometimes', 'nullable', 'string', 'max:60', 'regex:'.ModelId::PATTERN],
        ];
        if (Validator::make($data, $rules)->fails()) {
            return $this->json(['reason' => 'invalid_model', ...$this->body($settings, $health)], 422);
        }

        if (array_key_exists('enabled', $data)) {
            $result = $settings->setEnabled($request->user(), $agent, filter_var($data['enabled'], FILTER_VALIDATE_BOOLEAN));
            if (! $result['ok']) {
                return $this->json(['reason' => $result['reason'], ...$this->body($settings, $health)], 422);
            }
        }
        if ($hasModel) {
            $result = $settings->setModel($request->user(), $agent, $data['model']);
            if (! $result['ok']) {
                return $this->json(['reason' => $result['reason'], ...$this->body($settings, $health)], 422);
            }
        }

        return $this->json($this->body($settings, $health));
    }

    public function move(Request $request, AiAgentSettingsQuery $settings, AgentHealth $health, string $agent): JsonResponse
    {
        $this->known($agent);

        $valid = $request->validate([
            'direction' => ['required', 'in:up,down'],
            'expectedPosition' => ['sometimes', 'nullable', 'integer'],
        ]);
        $expected = isset($valid['expectedPosition']) ? (int) $valid['expectedPosition'] : null;

        $result = $settings->move($request->user(), $agent, $valid['direction'], $expected);
        if (! $result['ok']) {
            return $this->json(['reason' => $result['reason'], ...$this->body($settings, $health)], 409);
        }

        return $this->json($this->body($settings, $health));
    }

    private function known(string $agent): void
    {
        abort_unless(in_array($agent, array_map('strval', (array) config('ai.agents', [])), true), 404);
    }

    /** @param array<string, mixed> $payload */
    private function json(array $payload, int $status = 200): JsonResponse
    {
        return response()->json($payload, $status)->header('Cache-Control', 'private, no-store');
    }

    /** @return array{agents: list<array<string, mixed>>} */
    private function body(AiAgentSettingsQuery $settings, AgentHealth $health): array
    {
        return ['agents' => $this->agents($settings, $health)];
    }

    /** @return list<array<string, mixed>> */
    private function agents(AiAgentSettingsQuery $settings, AgentHealth $health): array
    {
        $out = [];
        foreach ($settings->all() as $row) {
            $snapshot = $health->snapshot($row['agent']);
            $out[] = [
                'key' => $row['agent'],
                'enabled' => $row['enabled'],
                'position' => $row['position'],
                'model' => $row['model'],
                'health' => [
                    'state' => $snapshot['state'],
                    'checkedAt' => $snapshot['checkedAt']?->toIso8601String(),
                ],
                'cooldownUntil' => $snapshot['cooldownUntil']?->toIso8601String(),
            ];
        }

        return $out;
    }
}
