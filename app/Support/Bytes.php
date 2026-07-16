<?php

namespace App\Support;

class Bytes
{
    /** Humanize a byte count: 1536 -> "1.5 KB", 0 -> "0 B". */
    public static function human(int|float|null $bytes): string
    {
        $bytes = (float) ($bytes ?? 0);
        if ($bytes <= 0) {
            return '0 B';
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        $i = (int) floor(log($bytes, 1024));
        $i = max(0, min($i, count($units) - 1));
        $value = $bytes / (1024 ** $i);

        return ($i === 0 ? (string) (int) $value : number_format($value, $value < 10 ? 2 : 1)) . ' ' . $units[$i];
    }
}
