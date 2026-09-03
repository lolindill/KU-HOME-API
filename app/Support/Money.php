<?php

namespace App\Support;

class Money
{
    /**
     * Convert integer satang to 2-decimal-places baht string.
     *
     * Uses pure integer arithmetic to avoid floating-point precision artifacts (e.g. 0.1 + 0.2).
     */
    public static function satangToBaht(int $satang): string
    {
        $isNegative = $satang < 0;
        $abs = abs($satang);
        $baht = intdiv($abs, 100);
        $rem = $abs % 100;

        return sprintf('%s%d.%02d', $isNegative ? '-' : '', $baht, $rem);
    }

    /**
     * Convert baht value (string decimal, float, or integer) to integer satang.
     *
     * String input parses without float drift (e.g. "1000.00" -> 100000 satang).
     */
    public static function bahtToSatang(string|float|int $baht): int
    {
        if (is_string($baht)) {
            $trimmed = trim($baht);
            $isNegative = str_starts_with($trimmed, '-');
            $clean = ltrim($trimmed, '-+');

            $parts = explode('.', $clean, 2);
            $whole = (int) $parts[0];
            $fraction = isset($parts[1]) ? substr(str_pad($parts[1], 2, '0'), 0, 2) : '00';
            $satang = ($whole * 100) + (int) $fraction;

            return $isNegative ? -$satang : $satang;
        }

        return (int) round(((float) $baht) * 100);
    }
}
