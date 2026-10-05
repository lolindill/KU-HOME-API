<?php

namespace App\Services\ReportExcel\Data;

use App\Models\StockInventory;
use App\Services\ReportExcel\BaseReportData;

/**
 * 📦 SuppliesReportData — รายงานวัสดุสิ้นเปลือง (excel-reports spec §2.8 — minimal, ticket 11)
 *
 *    ⚠️ **เวอร์ชัน minimal ตามที่ owner defer** ("เอาไว้ก่อน" 2026-10-05):
 *    ข้อมูลจริงมีแค่ stock_inventories เดิม (item_name / unit / quantity) —
 *    - remaining = quantity (คงเหลือปัจจุบัน)
 *    - column ที่ไม่มีตารางรอง (item_code, category, carried_over, received, issued,
 *      reorder_point, max_stock, status) **เว้นว่าง** ตาม layout ชีต
 *    · filter category รับไว้แต่ยังไม่มีที่เก็บ → ไม่กรอง (จดหมายเหตุใน template)
 *    · movement ledger + item master = งานอนาคต (fog ใน map)
 */
class SuppliesReportData extends BaseReportData
{
    public function templateId(): string
    {
        return 'supplies-report';
    }

    public function filterRules(): array
    {
        return array_merge(
            $this->dateRangeRules('date_from', 'date_to'),
            ['category' => ['nullable', 'string', 'max:100']],
        );
    }

    public function rows(array $filters): iterable
    {
        return StockInventory::query()
            ->orderBy('item_name')
            ->get()
            ->map(fn (StockInventory $item) => [
                'item_code' => null,           // ไม่มีตารางรอง — เว้นว่าง
                'category' => null,            // เว้นว่าง (defer)
                'item_name' => $item->item_name,
                'unit' => $item->unit,
                'carried_over' => null,        // ยอดยกมา — ไม่มี movement ledger
                'received' => null,            // รับเข้า — ไม่มี movement ledger
                'issued' => null,              // เบิกจ่าย — ไม่มี movement ledger
                'remaining' => (int) $item->quantity,
                'reorder_point' => null,       // defer
                'max_stock' => null,           // defer
                'status' => null,              // derive ได้เมื่อมี reorder_point จริง
            ]);
    }

    public function summary(array $filters): array
    {
        $rows = iterator_to_array($this->rows($filters));

        if ($rows === []) {
            return [];
        }

        return [
            '_label' => 'Grand Total',
            'remaining' => array_sum(array_column($rows, 'remaining')),
        ];
    }
}
