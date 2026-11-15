<?php

namespace App\Access;

use App\Models\ModulePermission;
use App\Models\User;

/**
 * The single deny-by-default permission layer. Bound with `scoped`, so the
 * per-instance cache never outlives one request. Admin status never implies
 * module access (no global before-hook).
 */
class AccessGate
{
    /** @var array<int, array<string, AccessLevel>> */
    private array $cache = [];

    public function level(User $user, Module $module): AccessLevel
    {
        return $this->load($user)[$module->value] ?? AccessLevel::None;
    }

    public function allows(?User $user, Module $module, AccessLevel $required): bool
    {
        if ($user === null || $required === AccessLevel::None) {
            return false;
        }

        return $this->level($user, $module)->value >= $required->value;
    }

    public function requireAccess(?User $user, Module $module, AccessLevel $required): void
    {
        abort_unless($this->allows($user, $module, $required), 403);
    }

    public function isAdmin(?User $user): bool
    {
        return $user !== null && (bool) $user->is_admin;
    }

    /** @return array<string, string> module value => 'none'|'view'|'edit' */
    public function levels(User $user): array
    {
        $levels = [];
        foreach (Module::cases() as $module) {
            $levels[$module->value] = $this->level($user, $module)->label();
        }

        return $levels;
    }

    public function forget(User $user): void
    {
        unset($this->cache[$user->getKey()]);
    }

    /** @return array<string, AccessLevel> */
    private function load(User $user): array
    {
        return $this->cache[$user->getKey()] ??= ModulePermission::query()
            ->where('user_id', $user->getKey())
            ->get()
            ->mapWithKeys(fn (ModulePermission $p) => [$p->module->value => $p->level])
            ->all();
    }
}
