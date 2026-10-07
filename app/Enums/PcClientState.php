<?php

namespace App\Enums;

/**
 * Whether an enrolled Lab Client may still post frames.
 *
 * Revoked is a state rather than a deleted row on purpose: a machine that was
 * legitimately enrolled and later decommissioned should leave evidence that it
 * existed, and the token hash should stay on record so it can never be replayed.
 */
enum PcClientState: string
{
    case Active = 'active';
    case Revoked = 'revoked';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    public function isActive(): bool
    {
        return $this === self::Active;
    }

    /**
     * Filament colour tokens, matching PcStatus.
     */
    public function color(): string
    {
        return match ($this) {
            self::Active => 'success',
            self::Revoked => 'danger',
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
