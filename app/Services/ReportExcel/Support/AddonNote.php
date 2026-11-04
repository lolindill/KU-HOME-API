<?php

namespace App\Services\ReportExcel\Support;

use App\Models\BookingRoom;

/**
 * 📊 (05/10/26) — note addon สำหรับคอลัมน์ "ข้อมูล Early Check in / Extra bed (ถ้ามี)"
 * ใช้ร่วมกันระหว่าง check-in / check-out report (ชีต: "Extra bed 1 เตียง")
 */
class AddonNote
{
    public static function make(BookingRoom $br): ?string
    {
        $parts = [];
        $addon = $br->addon;

        if ($addon && $addon->extra_beds_max > 0) {
            $parts[] = "Extra bed {$addon->extra_beds_max} เตียง";
        }

        if ($addon && ($addon->early_hours ?? 0) > 0) {
            $parts[] = "Early check-in {$addon->early_hours} ชม.";
        }

        return $parts !== [] ? implode(' · ', $parts) : null;
    }
}
