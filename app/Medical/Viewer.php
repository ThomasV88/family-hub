<?php

namespace App\Medical;

use App\Access\AccessGate;
use App\Access\AccessLevel;
use App\Access\Module;
use App\Models\User;

/**
 * Who is looking at medical data. Built only for users with Health access, so a query class can never run for a user
 * without it. There is deliberately no admin branch: visibility comes from subject membership and own record only.
 */
final readonly class Viewer
{
    public function __construct(public User $user, public AccessLevel $level) {}

    public static function for(User $user): self
    {
        $level = app(AccessGate::class)->level($user, Module::Health);
        abort_if($level === AccessLevel::None, 403);

        return new self($user, $level);
    }

    public function canEdit(): bool
    {
        return $this->level === AccessLevel::Edit;
    }

    public function userId(): int
    {
        return (int) $this->user->getKey();
    }
}
