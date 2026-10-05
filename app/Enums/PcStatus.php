<?php

namespace App\Enums;

enum PcStatus: string
{
    case Online = 'online';
    case Offline = 'offline';
    case Maintenance = 'maintenance';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    /**
     * Maintenance PCs are deliberately exempt from the stale sweep, so an
     * administrator taking a machine offline does not have to keep logging in
     * to hold the status.
     */
    public function isAutoOfflinable(): bool
    {
        return $this === self::Online;
    }

    /**
     * Tailwind classes for the PC status badges used across both panels.
     */
    public function color(): string
    {
        return match ($this) {
            self::Online => 'success',
            self::Offline => 'gray',
            self::Maintenance => 'danger',
        };
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
