<?php

namespace App\Access;

use App\Audit\AuditEvent;
use App\Audit\AuditLogger;
use App\Models\ModulePermission;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The only path that changes a member's module grants. One transaction: every changed module is
 * written together with its audit row, so a failed audit write rolls the grant back.
 */
class PermissionService
{
    public function __construct(private AccessGate $gate, private AuditLogger $audit) {}

    /**
     * @param  array<string, string>  $levels  module value => 'none'|'view'|'edit', all four modules required
     * @return list<array{module: string, from: string, to: string}>
     */
    public function save(User $actor, User $member, array $levels): array
    {
        $target = [];
        foreach (Module::cases() as $module) {
            $level = isset($levels[$module->value]) && is_string($levels[$module->value])
                ? AccessLevel::fromLabel($levels[$module->value])
                : null;
            if ($level === null) {
                throw new InvalidArgumentException("Missing or unknown level for module {$module->value}.");
            }
            $target[$module->value] = $level;
        }
        if (array_diff_key($levels, $target) !== []) {
            throw new InvalidArgumentException('Unknown module in permission payload.');
        }

        $changes = DB::transaction(function () use ($actor, $member, $target) {
            $changes = [];
            foreach (Module::cases() as $module) {
                $from = $this->gate->level($member, $module);
                $to = $target[$module->value];
                if ($from === $to) {
                    continue;
                }

                ModulePermission::query()->updateOrCreate(
                    ['user_id' => $member->getKey(), 'module' => $module->value],
                    ['level' => $to],
                );
                $this->audit->record(
                    AuditEvent::PermissionChanged,
                    $actor,
                    ['from' => $from->label(), 'to' => $to->label()],
                    $member,
                    $module,
                );
                $changes[] = ['module' => $module->value, 'from' => $from->label(), 'to' => $to->label()];
            }

            return $changes;
        });

        // The next request must see the new grants, never a cached copy.
        $this->gate->forget($member);

        return $changes;
    }
}
