<?php

namespace App\Services\ReportExcel;

use App\Services\ReportExcel\Contracts\ReportData;
use Illuminate\Validation\Rule;

/**
 * 📊 (05/10/26) excel-reports spec §1.2 — base ร่วมของ ReportData 15 classes
 *
 *    ให้สิ่งที่ทุกรายงานใช้เหมือนกัน:
 *    - อ่าน template JSON ของตัวเอง (cache ต่อ process) จาก resources/report-templates/
 *    - columns() default = คอลัมน์ของ template (รายงาน dynamic อย่าง extra-bed override เอง)
 *    - dateRangeRules() — เพดานช่วงวันที่ ≤ 1 ปี (config reporting.max_export_range_days)
 *
 *    Data class ห้าม query ผ่าน base นี้ — แต่ละ class จัดการ query ของตัวเอง
 */
abstract class BaseReportData implements ReportData
{
    /** @var array<string,array> template cache ต่อ process */
    private static array $templateCache = [];

    public function columns(array $filters): array
    {
        return $this->templateColumns();
    }

    public function summary(array $filters): array
    {
        return [];
    }

    /**
     * โครง template JSON เต็ม (id/name_th/filters/columns/notes …)
     */
    protected function template(): array
    {
        $id = $this->templateId();

        if (! isset(self::$templateCache[$id])) {
            $path = resource_path('report-templates/'.$id.'.json');

            if (! is_file($path)) {
                throw new \RuntimeException("ไม่พบ template JSON ของรายงาน {$id} ({$path})");
            }

            self::$templateCache[$id] = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        }

        return self::$templateCache[$id];
    }

    /**
     * คอลัมน์ของ template — แต่ละตัว ['key','label_th','type', ...]
     */
    protected function templateColumns(): array
    {
        return array_map(
            fn (array $col) => [
                'key' => $col['key'],
                'label_th' => $col['label_th'],
                'type' => $col['type'] ?? 'string',
            ],
            $this->template()['columns'] ?? []
        );
    }

    /**
     * filters ของ template (เอาไว้ build หัวไฟล์/validate enum)
     */
    protected function templateFilters(): array
    {
        return $this->template()['filters'] ?? [];
    }

    /**
     * ชื่อรายงานไทย (หัวไฟล์/ชื่อชีต/ชื่อไฟล์ .xlsx)
     */
    protected function nameTh(): string
    {
        return $this->template()['name_th'] ?? $this->templateId();
    }

    /**
     * rules ช่วงวันที่มาตรฐาน — required ทั้งคู่ + to ≥ from + เพดาน ≤ 1 ปี
     * (spec §4 — คุมขนาดไฟล์ ~1.6 KB/cell ของ phpspreadsheet)
     *
     * @return array<string, array>
     */
    protected function dateRangeRules(string $fromKey = 'date_from', string $toKey = 'date_to'): array
    {
        $max = (int) config('reporting.max_export_range_days', 366);

        return [
            $fromKey => ['required', 'date'],
            $toKey => [
                'required',
                'date',
                'after_or_equal:'.$fromKey,
                function (string $attribute, mixed $value, \Closure $fail) use ($fromKey, $max) {
                    $from = \Carbon\Carbon::parse(request()->input($fromKey));
                    $to = \Carbon\Carbon::parse($value);

                    if ($from->startOfDay()->diffInDays($to->startOfDay()) > $max) {
                        $fail("ช่วงวันที่ของรายงานยาวเกิน {$max} วัน (ไม่เกิน 1 ปีต่อการ export) ค่ะ 📅");
                    }
                },
            ],
        ];
    }

    /**
     * rules วันเดียว (รายงานประจำวัน)
     */
    protected function singleDateRules(string $key = 'date'): array
    {
        return [$key => ['required', 'date']];
    }

    /**
     * rules enum จาก template filter (เช่น mode ของ manager-report)
     * nullable + ไม่ส่ง = ผ่าน (ค่า default จัดการใน Data class)
     *
     * @param  string|null  $default  ค่า default เมื่อไม่ส่ง (ใส่ใน Rule::in เป็นข้อแรก)
     */
    protected function enumFilterRules(string $key, array $options, ?string $default = null): array
    {
        $allowed = $default !== null ? array_unique([$default, ...$options]) : $options;

        return [$key => ['nullable', 'string', Rule::in($allowed)]];
    }
}
