<?php

namespace App\Enums;

enum ClassSessionStatus: string
{
    case Active = 'active';
    case Completed = 'completed';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
