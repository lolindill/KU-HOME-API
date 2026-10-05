<?php

namespace App\Services\ReportExcel;

use Carbon\Carbon;
use InvalidArgumentException;

/**
 * 📅 (05/10/26) excel-reports spec §5 — วันที่แสดงผลเป็น พ.ศ. `d/m/YYYY` (เช่น 14/09/2569)
 *
 *    DB เก็บ ISO-8601 ตลอด — format เป็น พ.ศ. เฉพาะตอน render เท่านั้น
 */
class ThaiDate
{
    /**
     * ISO date/datetime → "d/m/YYYY" พ.ศ. (ค.ศ. + 543)
     *
     * @param  mixed  $value  Carbon|string|null — null/ค่าว่าง คืน ''
     */
    public static function format($value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        try {
            $date = $value instanceof Carbon ? $value->copy() : Carbon::parse($value);
        } catch (\Exception $e) {
            throw new InvalidArgumentException("ค่าวันที่ไม่ถูกต้อง: {$value}", 0, $e);
        }

        return $date->format('d/m/').($date->format('Y') + 543);
    }

    /**
     * สร้างข้อความหัวไฟล์ "ข้อมูลวันที่ …" ตามช่วง filter
     * วันเดียว → "ข้อมูลวันที่ 14/09/2569" · ช่วง → "ข้อมูลวันที่ 14/09/2569 ถึง 20/09/2569"
     */
    public static function rangeLine(?string $from, ?string $to): string
    {
        if ($from !== null && $to !== null && $from !== $to) {
            return 'ข้อมูลวันที่ '.self::format($from).' ถึง '.self::format($to);
        }

        return 'ข้อมูลวันที่ '.self::format($from ?? $to);
    }

    /**
     * slug ช่วงวันที่สำหรับชื่อไฟล์ (เช่น 14-09-2569_ถึง_20-09-2569)
     */
    public static function rangeSlug(?string $from, ?string $to): string
    {
        if ($from !== null && $to !== null && $from !== $to) {
            return str_replace('/', '-', self::format($from)).'_ถึง_'.str_replace('/', '-', self::format($to));
        }

        return str_replace('/', '-', self::format($from ?? $to));
    }
}
