<?php

namespace App\Services\ReportExcel\Data;

use App\Models\AdditionalCharge;
use App\Services\ReportExcel\BaseReportData;

/**
 * 💸 AdditionalChargesReportData — รายการค่าปรับ/ขออุปกรณ์เพิ่มเติม (excel-reports spec §2.6)
 *
 *    แถว = additional_charges ที่ transaction_date อยู่ในช่วง date_range
 *    · filter item_type: ทุกประเภท | ค่าเสียหาย (damage) | ค่ายืม (rental) | บริการเสริม (addon — 15/10/26 ticket 13)
 *    · 💳 (15/10/26, ticket 13) ยอดบวกยอดจริงที่ booking แล้ว (outstanding/is_paid) —
 *      แถวยัง query ทาง booking_id เหมือนเดิม (charge ต่อห้องก็มี booking_id เสมอ)
 */
class AdditionalChargesReportData extends BaseReportData
{
    public function templateId(): string
    {
        return 'additional-charges-report';
    }

    public function filterRules(): array
    {
        return array_merge(
            $this->dateRangeRules('date_from', 'date_to'),
            $this->enumFilterRules('item_type', ['ทุกประเภท', 'ค่าเสียหาย', 'ค่ายืม', 'บริการเสริม'], 'ทุกประเภท'),
        );
    }

    public function rows(array $filters): iterable
    {
        $itemType = $filters['item_type'] ?? 'ทุกประเภท';

        return AdditionalCharge::query()
            ->with(['booking', 'recorder'])
            ->whereDate('transaction_date', '>=', $filters['date_from'])
            ->whereDate('transaction_date', '<=', $filters['date_to'])
            ->when($itemType === 'ค่าเสียหาย', fn ($q) => $q->where('charge_type', AdditionalCharge::TYPE_DAMAGE))
            ->when($itemType === 'ค่ายืม', fn ($q) => $q->where('charge_type', AdditionalCharge::TYPE_RENTAL))
            ->when($itemType === 'บริการเสริม', fn ($q) => $q->where('charge_type', AdditionalCharge::TYPE_ADDON))
            ->orderBy('transaction_date')
            ->get()
            ->map(fn (AdditionalCharge $charge) => [
                'transaction_date' => $charge->transaction_date->toDateString(),
                'item_code' => $charge->item_code,
                'item_name' => $charge->item_name,
                'qty' => $charge->qty,
                'unit' => $charge->unit,
                'price' => $charge->price,
                'booking_no' => $charge->booking?->confirmation,
            ]);
    }

    public function summary(array $filters): array
    {
        $rows = iterator_to_array($this->rows($filters));

        if ($rows === []) {
            return [];
        }

        return [
            '_label' => 'รวม '.count($rows).' รายการ',
            'price' => array_sum(array_column($rows, 'price')),
        ];
    }
}
