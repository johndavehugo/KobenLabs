<?php

namespace App\Support;

/**
 * Human-readable file sizes.
 *
 * StoredFile::humanSize, the user storage quota readouts and the quota rejection
 * message all need the same formatting, so it lives here rather than being
 * written out three times.
 */
class Bytes
{
    private const UNITS = ['B', 'KB', 'MB', 'GB', 'TB'];

    public static function format(int|float $bytes): string
    {
        if ($bytes < 0) {
            $bytes = 0;
        }

        foreach (self::UNITS as $index => $unit) {
            if ($bytes < 1024) {
                return round($bytes, $unit === 'B' ? 0 : 1).' '.$unit;
            }

            $bytes /= 1024;
        }

        return round($bytes, 1).' PB';
    }
}
