<?php

namespace App\Enums;

enum TextSize: string
{
    case Normal = 'normal';
    case Large = 'large';
    case ExtraLarge = 'extra-large';

    public function label(): string
    {
        return match ($this) {
            self::Normal => 'Normal',
            self::Large => 'Large',
            self::ExtraLarge => 'Extra large',
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
