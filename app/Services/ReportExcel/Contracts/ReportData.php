<?php

namespace App\Services\ReportExcel\Contracts;

/**
 * 📊 (05/10/26) excel-reports spec §1.2 — contract ต่อรายงาน (Hybrid renderer)
 *
 *    Data class ทำ query + normalize เป็นแถว **เท่านั้น** — ห้ามวาง cell/styling เอง
 *    (ห้าม PHP hard-code cell ใน Data class — โครงหน้าทั้งหมดเป็นหน้าที่ของ ExcelReportRenderer)
 *
 *    1 class ต่อ 1 รายงาน — 15 classes อยู่ App\Services\ReportExcel\Data\*
 */
interface ReportData
{
    /**
     * จับคู่ template JSON (ชื่อไฟล์ใน resources/report-templates/ ไม่มี .json)
     */
    public function templateId(): string;

    /**
     * Laravel validation rules ต่อรายงาน (รวมเพดานช่วงวันที่ ≤ 1 ปี)
     * — controller validate ด้วยชุดนี้ก่อนเรียก renderer
     */
    public function filterRules(): array;

    /**
     * คอลัมน์ของตาราง — dynamic ได้ (เช่น extra-bed ขยายคอลัมน์ตามคืนใน filter range)
     * แต่ละตัว: ['key' => string, 'label_th' => string, 'type' => string]
     * (type = template schema เช่ money_baht | date | integer | string | enum | boolean)
     */
    public function columns(array $filters): array;

    /**
     * แถวข้อมูล — iterable ของ array keyed ด้วย column key
     * แถวพิเศษ: ['_section' => 'ชื่อ section'] = แถว section label (engine เขียน bold ไม่ merge)
     * ค่า date ส่งเป็น ISO-8601 — engine format พ.ศ. ตอน render
     */
    public function rows(array $filters): iterable;

    /**
     * แถวรวมท้ายตาราง — array keyed ด้วย column key (คีย์ '_label' = ข้อความช่องแรก, default 'รวม')
     * รายงานที่ไม่มีแถวรวมคืน []
     */
    public function summary(array $filters): array;
}
