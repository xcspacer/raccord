<?php

namespace App\Support;

use App\Enums\Role;
use App\Models\User;

/**
 * How long a staff session lasts, by role (decision D3): technicians stay signed in on their phone
 * (12 h of inactivity, remembered for 30 days), supervisors and design office 8 h / 7 days,
 * administrators 2 h of inactivity, never remembered, and signed out 12 h after signing in whatever
 * their activity. Someone without a role gets the strictest rules.
 */
final readonly class SessionPolicy
{
    public function __construct(
        public int $idleMinutes,
        public ?int $rememberDays,
        public ?int $absoluteHours,
    ) {}

    public static function for(User $user): self
    {
        return match (Role::tryFrom((string) $user->getRoleNames()->first())) {
            Role::Technician => new self(idleMinutes: 12 * 60, rememberDays: 30, absoluteHours: null),
            Role::Supervisor, Role::DesignOffice => new self(idleMinutes: 8 * 60, rememberDays: 7, absoluteHours: null),
            default => new self(idleMinutes: 2 * 60, rememberDays: null, absoluteHours: 12),
        };
    }

    public function remembers(): bool
    {
        return $this->rememberDays !== null;
    }

    public function rememberMinutes(): int
    {
        return ($this->rememberDays ?? 0) * 24 * 60;
    }

    /**
     * Whether a session last used at $lastSeenAt and opened at $signedInAt (Unix timestamps) is over.
     */
    public function hasExpired(int $lastSeenAt, int $signedInAt, int $now): bool
    {
        if ($now - $lastSeenAt > $this->idleMinutes * 60) {
            return true;
        }

        return $this->absoluteHours !== null && $now - $signedInAt > $this->absoluteHours * 3600;
    }
}
