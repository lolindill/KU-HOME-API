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
}
