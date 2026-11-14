<?php

namespace App\Medical\Queries;

use App\Audit\AuditEvent;
use App\Audit\AuditLogger;
use App\Medical\Ai\ModelId;
use App\Models\AiAgentSetting;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Admin-only agent settings and the worker's read side. Content-free: agent keys, booleans, positions and validated model ids.
 */
final class AiAgentSettingsQuery
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** @return list<array{agent: string, enabled: bool, position: int, model: ?string}> */
    public function all(): array
    {
        return $this->rows(AiAgentSetting::query()->get()->all());
    }

    /** @return list<string> */
    public function enabledKeys(): array
    {
        return array_values(array_map(
            fn (array $a) => $a['agent'],
            array_filter($this->all(), fn (array $a) => $a['enabled']),
        ));
    }

    public function model(string $agent): ?string
    {
        foreach ($this->all() as $row) {
            if ($row['agent'] === $agent) {
                return $row['model'];
            }
        }

        return null;
    }

    /** @return array{ok: bool, reason: ?string, agents: list<array{agent: string, enabled: bool, position: int, model: ?string}>} */
    public function setEnabled(User $admin, string $agent, bool $enabled): array
    {
        return $this->change($admin, $agent, function (array $rows) use ($admin, $agent, $enabled) {
            if ($rows[$agent]['enabled'] === $enabled) {
                return null;
            }
            if (! $enabled) {
                $others = array_filter($rows, fn (array $r, string $k) => $k !== $agent && $r['enabled'], ARRAY_FILTER_USE_BOTH);
                if ($others === []) {
                    return 'last_enabled';
                }
            }
            $this->auditChange($admin, $agent, 'enabled', $enabled ? 'on' : 'off');
            $this->update($admin, $agent, ['enabled' => $enabled]);

            return null;
        });
    }

    /** @return array{ok: bool, reason: ?string, agents: list<array{agent: string, enabled: bool, position: int, model: ?string}>} */
    public function setModel(User $admin, string $agent, ?string $model): array
    {
        $model = $model === null ? null : trim($model);
        if ($model === '') {
            $model = null;
        }

        return $this->change($admin, $agent, function (array $rows) use ($admin, $agent, $model) {
            if ($model !== null && ! ModelId::isValid($model)) {
                return 'invalid_model';
            }
            if ($rows[$agent]['model'] === $model) {
                return null;
            }
            $this->auditChange($admin, $agent, 'model', $model ?? 'default');
            $this->update($admin, $agent, ['model' => $model]);

            return null;
        });
    }

    /** @return array{ok: bool, reason: ?string, agents: list<array{agent: string, enabled: bool, position: int, model: ?string}>} */
    public function move(User $admin, string $agent, string $direction, ?int $expectedPosition = null): array
    {
        return $this->change($admin, $agent, function (array $rows) use ($admin, $agent, $direction, $expectedPosition) {
            $current = $rows[$agent]['position'];
            if ($expectedPosition !== null && $expectedPosition !== $current) {
                return 'stale';
            }
            $ordered = array_values($rows);
            usort($ordered, fn (array $a, array $b) => $a['position'] <=> $b['position']);
            $index = array_search($agent, array_column($ordered, 'agent'), true);
            $neighbour = $ordered[$index + ($direction === 'up' ? -1 : 1)] ?? null;
            if ($neighbour === null) {
                return null;
            }
            $this->auditChange($admin, $agent, 'position', (string) $neighbour['position']);
            // The unique constraint is deferred, so the swap is checked at commit.
            $this->update($admin, $agent, ['position' => $neighbour['position']]);
            $this->update($admin, $neighbour['agent'], ['position' => $current]);

            return null;
        });
    }

    /**
     * @param  callable(array<string, array{agent: string, enabled: bool, position: int, model: ?string}>): ?string  $apply
     * @return array{ok: bool, reason: ?string, agents: list<array{agent: string, enabled: bool, position: int, model: ?string}>}
     */
    private function change(User $admin, string $agent, callable $apply): array
    {
        $reason = DB::transaction(function () use ($agent, $apply) {
            $stored = AiAgentSetting::query()->lockForUpdate()->get()->all();
            $rows = [];
            foreach ($this->rows($stored) as $row) {
                $rows[$row['agent']] = $row;
            }
            if (! isset($rows[$agent])) {
                return 'unknown';
            }
            // A missing row (defaults only) is created first so the later updates have something to change.
            $have = array_map(fn (AiAgentSetting $s) => (string) $s->agent, $stored);
            foreach ($rows as $key => $row) {
                if (! in_array($key, $have, true)) {
                    AiAgentSetting::query()->toBase()->insert([
                        'agent' => $key, 'enabled' => $row['enabled'], 'position' => $row['position'], 'model' => $row['model'],
                    ]);
                }
            }

            return $apply($rows);
        });

        return ['ok' => $reason === null, 'reason' => $reason, 'agents' => $this->all()];
    }

    private function auditChange(User $admin, string $agent, string $field, string $value): void
    {
        $this->audit->record(AuditEvent::AiAgentSettingChanged, $admin, ['agent' => $agent, 'field' => $field, 'value' => $value]);
    }

    /** @param array<string, mixed> $columns */
    private function update(User $admin, string $agent, array $columns): void
    {
        AiAgentSetting::query()->toBase()->where('agent', $agent)->update($columns + ['updated_by' => $admin->id, 'updated_at' => now()]);
    }

    /**
     * Stored rows merged with the defaults of known agents, ordered by position.
     *
     * @param  list<AiAgentSetting>  $stored
     * @return list<array{agent: string, enabled: bool, position: int, model: ?string}>
     */
    private function rows(array $stored): array
    {
        $byKey = [];
        foreach ($stored as $s) {
            $byKey[(string) $s->agent] = $s;
        }
        $rows = [];
        foreach ((array) config('ai.agents', []) as $i => $key) {
            $key = (string) $key;
            $s = $byKey[$key] ?? null;
            $model = $s?->model;
            $rows[] = [
                'agent' => $key,
                'enabled' => $s !== null ? (bool) $s->enabled : $i === 0,
                'position' => $s !== null ? (int) $s->position : $i + 1,
                'model' => is_string($model) && ModelId::isValid($model) ? $model : null,
            ];
        }
        usort($rows, fn (array $a, array $b) => $a['position'] <=> $b['position']);

        return $rows;
    }
}
