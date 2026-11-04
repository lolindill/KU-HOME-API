<?php

namespace App\Services\ReportExcel\Data;

use App\Models\StockInventory;
use App\Services\ReportExcel\BaseReportData;
use Carbon\Carbon;

/**
 * 📦 SuppliesReportData — รายงานวัสดุสิ้นเปลือง (excel-reports spec §2.8 — REQ-051/052)
 *
 *    - คำนวณ carried_over (ยอดยกมา), received (รับเข้า), issued (เบิกจ่าย), remaining (คงเหลือ)
 *      จาก master stock และ stock_movements
 *    - แสดง item_code, category, reorder_point, max_stock
 *    - สถานะ: 'ปกติ' (ok) / 'สั่งซื้อ' (low หรือ out) ตาม spec template supplies-report
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
        $from = ! empty($filters['date_from']) ? Carbon::parse($filters['date_from'])->startOfDay() : null;
        $to = ! empty($filters['date_to']) ? Carbon::parse($filters['date_to'])->endOfDay() : null;

        $query = StockInventory::query()->with('movements');

        if (! empty($filters['category']) && ! in_array($filters['category'], ['ทั้งหมด', 'all'], true)) {
            $query->where('category', $filters['category']);
        }

        return $query->orderBy('item_name')
            ->get()
            ->map(function (StockInventory $item) use ($from, $to) {
                $movements = $item->movements;

                // กรอง movement ตามช่วงวันที่
                $periodMovements = $movements->filter(function ($m) use ($from, $to) {
                    $createdAt = Carbon::parse($m->created_at);
                    if ($from && $createdAt->lt($from)) {
                        return false;
                    }
                    if ($to && $createdAt->gt($to)) {
                        return false;
                    }

                    return true;
                });

                $received = (int) $periodMovements->where('delta', '>', 0)->sum('delta');
                $issued = (int) abs($periodMovements->where('delta', '<', 0)->sum('delta'));

                // คำนวณยอดยกมา (carried_over) ก่อนจุดเริ่มต้น $from
                if ($from) {
                    $deltaSinceFrom = (int) $movements->filter(fn ($m) => Carbon::parse($m->created_at)->gte($from))->sum('delta');
                    $carriedOver = max(0, (int) $item->quantity - $deltaSinceFrom);
                } else {
                    $carriedOver = max(0, (int) $item->quantity - $received + $issued);
                }

                $remaining = (int) $item->quantity;

                // สถานะตาม template options: ["ปกติ", "สั่งซื้อ"]
                $statusText = $item->status === 'ok' ? 'ปกติ' : 'สั่งซื้อ';

                return [
                    'item_code' => $item->item_code,
                    'category' => $item->category,
                    'item_name' => $item->item_name,
                    'unit' => $item->unit,
                    'carried_over' => $carriedOver,
                    'received' => $received,
                    'issued' => $issued,
                    'remaining' => $remaining,
                    'reorder_point' => $item->reorder_point,
                    'max_stock' => $item->max_stock,
                    'status' => $statusText,
                ];
            });
    }

    public function summary(array $filters): array
    {
        $rows = iterator_to_array($this->rows($filters));

        if ($rows === []) {
            return [];
        }

        return [
            '_label' => 'Grand Total',
            'carried_over' => array_sum(array_column($rows, 'carried_over')),
            'received' => array_sum(array_column($rows, 'received')),
            'issued' => array_sum(array_column($rows, 'issued')),
            'remaining' => array_sum(array_column($rows, 'remaining')),
        ];
    }
}
