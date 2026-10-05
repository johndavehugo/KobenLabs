<?php

namespace App\Enums;

enum GroupRole: string
{
    case Admin = 'admin';
    case Member = 'member';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    /**
     * Group-scoped role. Deliberately independent of UserRole: a student can be
     * the admin of a group, and an administrator can be a plain member.
     */
    public function canManageGroup(): bool
    {
        return $this === self::Admin;
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
