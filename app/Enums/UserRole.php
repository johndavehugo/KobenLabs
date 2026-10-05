<?php

namespace App\Enums;

enum UserRole: string
{
    case Student = 'student';
    case Teacher = 'teacher';
    case Admin = 'admin';

    public function label(): string
    {
        return match ($this) {
            self::Student => 'Student',
            self::Teacher => 'Teacher',
            self::Admin => 'Administrator',
        };
    }

    /**
     * Teacher and administrator share every staff capability in the legacy app.
     */
    public function canManageLab(): bool
    {
        return $this === self::Teacher || $this === self::Admin;
    }

    public function defaultStorageQuotaMb(): int
    {
        return match ($this) {
            self::Student => config('lab.storage.student_quota_mb'),
            self::Teacher => config('lab.storage.teacher_quota_mb'),
            self::Admin => config('lab.storage.admin_quota_mb'),
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
