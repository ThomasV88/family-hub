<?php

namespace App\Security;

use App\Access\AccessGate;
use App\Access\AccessLevel;
use App\Access\Module;
use App\Models\User;

/** Who must have a second factor: only when the admin switch is on, and only members with Health or Finances access. */
class SecondFactorPolicy
{
    public const SETTING = 'require_second_factor';

    public function __construct(private readonly AppSettings $settings, private readonly AccessGate $gate) {}

    public function required(): bool
    {
        return (bool) $this->settings->get(self::SETTING, false);
    }

    public function setRequired(bool $on): void
    {
        $this->settings->set(self::SETTING, $on);
    }

    public function appliesTo(User $user): bool
    {
        foreach ([Module::Finance, Module::Health] as $module) {
            if ($this->gate->level($user, $module)->value >= AccessLevel::View->value) {
                return true;
            }
        }

        return false;
    }

    public function mustEnrol(User $user): bool
    {
        return $this->required() && $this->appliesTo($user) && ! $user->hasSecondFactor();
    }

    /** Members who would have to enrol if the requirement were on (independent of the switch). */
    public function pendingCount(): int
    {
        return User::query()->get()->filter(fn (User $u) => $this->appliesTo($u) && ! $u->hasSecondFactor())->count();
    }
}
