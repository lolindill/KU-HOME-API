<?php

namespace App\Services\ReportExcel\Data;

use App\Models\BookingRoom;
use App\Models\Room;
use App\Models\RoomStatePeriod;
use App\Services\ReportExcel\BaseReportData;
use Carbon\Carbon;

/**
 * 📊 ManagerReportData — รายงานผู้จัดการ (excel-reports spec §3.3, ticket 06)
 *
 *    Matrix **20 metrics (แถว) × 6 คอลัมน์ช่วงเวลา** ตามชีต · mode 3 โหมด:
 *    - Complete = Day / Month-to-Date / Year-to-Date + LY ครบ 6
 *    - Week     = แทน MTD ด้วย Week-to-Date (สัปดาห์เริ่มวันจันทร์)
 *    - Month    = แทน MTD ด้วยช่วง From-TO ที่เลือก (date_from/date_to)
 *
 *    นิยามรายคืน (แถว 9–14 = breakdown ต่อคืนของวันที่ filter — Day = คืนเดียว ไม่สะสม):
 *    - Room Occupied   = BR checked_in/checked_out ครอบคลุมคืนนั้น
 *    - Comfirmed       = booking paid+confirmed ครอบคลุมคืนนั้น (BR ยังไม่เช็คอิน)
 *    - Provisional     = booking draft+pending+verify_error (ไม่เพิ่ม storage)
 *    - Unsold          = Total − สามกลุ่มแรก · Complimentary = flag is_complimentary (ticket 09)
 *    - OOO ย้อนหลัง    = derive จาก room_state_periods kind=maintenance (แมป room-state-periods)
 *    - ค่ารวมช่วง (MTD/YTD/LY): แถวตัวนับเหตุการณ์ (Persons/Arrival/Depart/No Show) = ผลรวม
 *      · แถวสถานะห้อง + % = เฉลี่ยต่อคืน
 *    - LY ตอนไม่มีข้อมูลปีก่อน = แสดง 0 (คอลัมน์คงที่ 6 คอลัมน์)
 */
class ManagerReportData extends BaseReportData
{
    private const STOCK_KEYS = [
        'total_rooms', 'rooms_occupied', 'confirmed', 'provisional', 'unsold_rooms',
        'unsold_rooms_plus_provisional', 'percent_occupied',
        'percent_occupied_plus_confirmed_provisional', 'complimentary_rooms',
        'out_of_order_rooms', 'total_rooms_minus_ooo', 'avail_rooms_ooo',
        'percent_occupied_minus_ooo',
    ];

    public function templateId(): string
    {
        return 'manager-report';
    }

    public function filterRules(): array
    {
        return array_merge(
            $this->singleDateRules('date'),
            $this->enumFilterRules('mode', ['Complete', 'Week', 'Month'], 'Complete'),
            [
                // From-TO ใช้เฉพาะ mode=Month — ผูกเพดานช่วงวันที่ ≤ 1 ปี เช่นกัน
                'date_from' => ['nullable', 'date', 'required_if:mode,Month'],
                'date_to' => [
                    'nullable', 'date', 'required_if:mode,Month',
                    function (string $attribute, mixed $value, \Closure $fail) {
                        if (request()->input('mode') !== 'Month' || ! $value) {
                            return;
                        }
                        $max = (int) config('reporting.max_export_range_days', 366);
                        $from = Carbon::parse((string) request()->input('date_from'))->startOfDay();
                        $to = Carbon::parse((string) $value)->startOfDay();

                        if ($to->lt($from)) {
                            $fail('วันที่สิ้นสุด (date_to) ต้องไม่ก่อนวันเริ่ม (date_from) ค่ะ 📅');
                        } elseif ($from->diffInDays($to) > $max) {
                            $fail("ช่วง From-TO ยาวเกิน {$max} วัน (ไม่เกิน 1 ปี) ค่ะ 📅");
                        }
                    },
                ],
            ],
        );
    }

    /**
     * คอลัมน์ = metric label + 6 ช่วงเวลา (label ของ MTD เปลี่ยนตาม mode — คีย์คงที่)
     */
    public function columns(array $filters): array
    {
        $mode = $filters['mode'] ?? 'Complete';

        [$mtdLabel, $lyMtdLabel] = match ($mode) {
            'Week' => ['Week-to-Date', 'LY Week-to-Date'],
            'Month' => ['From-TO', 'LY From-TO'],
            default => ['Month to Date', 'LY Month to Date'],
        };

        return [
            ['key' => 'metric', 'label_th' => 'รายการ', 'type' => 'string'],
            ['key' => 'day', 'label_th' => 'Day', 'type' => 'number'],
            ['key' => 'mtd', 'label_th' => $mtdLabel, 'type' => 'number'],
            ['key' => 'ytd', 'label_th' => 'Year to Date', 'type' => 'number'],
            ['key' => 'ly_day', 'label_th' => 'LY Same Date', 'type' => 'number'],
            ['key' => 'ly_mtd', 'label_th' => $lyMtdLabel, 'type' => 'number'],
            ['key' => 'ly_ytd', 'label_th' => 'LY Year to Date', 'type' => 'number'],
        ];
    }

    public function rows(array $filters): iterable
    {
        $date = Carbon::parse($filters['date'])->startOfDay();
        $mode = $filters['mode'] ?? 'Complete';

        // 6 ช่วงเวลา — [from(รวม), to(exclusive คืนสุดท้าย+1 คือคืนที่ครอบเช็คเอาท์)]
        // สถานะห้องคิด "ต่อคืน" ที่ D ∈ [from, to) · ตัวนับเหตุการณ์นับที่วัน D ∈ [from, to]
        $current = $this->periods($date, $mode, $filters);
        $lastYear = array_map(
            fn (array $p) => [Carbon::parse($p[0])->subYear(), Carbon::parse($p[1])->subYear()],
            $current
        );

        $nightsMax = max(array_map(
            fn (array $p) => Carbon::parse($p[0])->diffInDays(Carbon::parse($p[1])),
            array_merge($current, $lastYear)
        ));

        // โหลดข้อมูลครั้งเดียวสำหรับทุกคืนของทุกช่วง (กัน N คืน × query)
        $earliest = collect(array_merge($current, $lastYear))->map(fn ($p) => $p[0])->min();
        $latest = collect(array_merge($current, $lastYear))->map(fn ($p) => $p[1])->max();

        $spans = BookingRoom::query()
            ->with(['booking', 'roomType'])
            ->whereIn('booking_rooms.status', ['draft', 'confirmed', 'checked_in', 'checked_out', 'no_show'])
            ->whereDate('check_in', '<=', $latest)
            ->whereDate('check_out', '>=', $earliest)
            ->get();

        $periods_ooo = RoomStatePeriod::query()
            ->where('kind', RoomStatePeriod::KIND_MAINTENANCE)
            ->get();

        $totalRooms = Room::count();

        // คิดรายคืนครั้งเดียวต่อวันใด ๆ ที่ต้องใช้ (current + LY)
        $nightCache = [];
        $perNight = function (Carbon $night) use (&$nightCache, $spans, $periods_ooo, $totalRooms): array {
            $key = $night->toDateString();

            return $nightCache[$key] ??= $this->metricsForNight($night, $spans, $periods_ooo, $totalRooms);
        };

        $metrics = collect($this->template()['metrics'] ?? []);

        return $metrics->map(function (array $metric) use ($current, $lastYear, $perNight, $nightsMax) {
            $key = $metric['key'];

            $row = ['metric' => $metric['label_th']];

            foreach (['day', 'mtd', 'ytd'] as $i => $colKey) {
                $row[$colKey] = $this->aggregate($key, $current[$i], $perNight, $nightsMax);
            }
            foreach (['ly_day', 'ly_mtd', 'ly_ytd'] as $i => $colKey) {
                $row[$colKey] = $this->aggregate($key, $lastYear[$i], $perNight, $nightsMax);
            }

            return $row;
        })->all();
    }

    public function summary(array $filters): array
    {
        return [];
    }

    /**
     * ช่วงเวลา 3 คู่ [from, to) ของโหมดปัจจุบัน — to เป็น exclusive (คืนสุดท้าย = to − 1 วัน)
     *
     * @return array{0: array, 1: array, 2: array} [Day, MTD/Week/From-TO, YTD]
     */
    private function periods(Carbon $date, string $mode, array $filters): array
    {
        $day = [$date->copy(), $date->copy()->addDay()];

        $mtd = match ($mode) {
            'Week' => [$date->copy()->startOfWeek(Carbon::MONDAY), $date->copy()->addDay()],
            'Month' => [
                Carbon::parse($filters['date_from'])->startOfDay(),
                Carbon::parse($filters['date_to'])->startOfDay()->addDay(),
            ],
            default => [$date->copy()->startOfMonth(), $date->copy()->addDay()],
        };

        $ytd = [$date->copy()->startOfYear(), $date->copy()->addDay()];

        return [$day, $mtd, $ytd];
    }

    /**
     * 20 metrics ของ 1 คืน — key ตรงกับ template metrics
     *
     * @param  mixed  $spans  Collection<BookingRoom> ที่โหลดพร้อม booking
     * @param  mixed  $oooPeriods  Collection<RoomStatePeriod> kind=maintenance
     */
    private function metricsForNight(Carbon $night, $spans, $oooPeriods, int $totalRooms): array
    {
        $covers = fn ($br) => $br->check_in->lte($night) && $br->check_out->gt($night);

        $occupied = $spans->filter(fn ($br) => $br->status === 'checked_in' && $covers($br));
        $confirmed = $spans->filter(fn ($br) => $br->status === 'confirmed'
            && in_array($br->booking->status, ['paid', 'confirmed'], true)
            && $covers($br));
        $provisional = $spans->filter(fn ($br) => in_array($br->booking->status, ['draft', 'pending', 'verify_error'], true)
            && $covers($br));

        // Complimentary = BR ทั้งหมดของ booking ที่ flag (รวมสามกลุ่ม — tag-only ticket 09)
        $inHouse = $spans->filter(fn ($br) => in_array($br->booking->status, ['paid', 'confirmed', 'draft', 'pending', 'verify_error'], true)
            && ! in_array($br->status, ['no_show'], true)
            && $covers($br));
        $complimentary = $inHouse->filter(fn ($br) => $br->booking->is_complimentary)->count();

        $ooo = $oooPeriods->filter(fn ($p) => $p->start_date->lte($night)
            && ($p->end_date === null || $p->end_date->gt($night)))->count();

        $unsold = max(0, $totalRooms - $occupied->count() - $confirmed->count() - $provisional->count());

        $guests = fn ($collection) => (int) $collection->sum(fn ($br) => count($br->booking->guests ?? []));

        // ตัวนับเหตุการณ์รายวัน
        $arrivals = $spans->filter(fn ($br) => $br->check_in->isSameDay($night)
            && in_array($br->status, ['confirmed', 'checked_in', 'checked_out'], true));
        $departures = $spans->filter(fn ($br) => $br->check_out->isSameDay($night)
            && in_array($br->status, ['checked_in', 'checked_out'], true));
        $noShows = $spans->filter(fn ($br) => $br->status === 'no_show' && $br->check_in->isSameDay($night));

        $occupiedCount = $occupied->count();
        $totalMinusOoo = max(0, $totalRooms - $ooo);

        return [
            'total_persons' => $guests($inHouse),
            'total_rooms' => $totalRooms,
            'rooms_occupied' => $occupiedCount,
            'confirmed' => $confirmed->count(),
            'provisional' => $provisional->count(),
            'unsold_rooms' => $unsold,
            'unsold_rooms_plus_provisional' => $unsold + $provisional->count(),
            'percent_occupied' => $totalRooms > 0 ? round($occupiedCount * 100 / $totalRooms, 1) : 0,
            'percent_occupied_plus_confirmed_provisional' => $totalRooms > 0
                ? round(($occupiedCount + $confirmed->count() + $provisional->count()) * 100 / $totalRooms, 1)
                : 0,
            'complimentary_rooms' => $complimentary,
            'out_of_order_rooms' => $ooo,
            'total_rooms_minus_ooo' => $totalMinusOoo,
            'avail_rooms_ooo' => max(0, $unsold - $ooo),
            'percent_occupied_minus_ooo' => $totalMinusOoo > 0
                ? round($occupiedCount * 100 / $totalMinusOoo, 1)
                : 0,
            'arrival_rooms' => $arrivals->count(),
            'arrival_persons' => $guests($arrivals),
            'departure_rooms' => $departures->count(),
            'departure_persons' => $guests($departures),
            'no_show_rooms' => $noShows->count(),
            'no_show_persons' => $guests($noShows),
        ];
    }

    /**
     * รวมค่ารายคืนเป็นค่าคอลัมน์ — ตาม ticket 06:
     * แถวสถานะห้อง/% (STOCK_KEYS) = เฉลี่ยต่อคืน · ตัวนับเหตุการณ์ = ผลรวมทั้งช่วง
     */
    private function aggregate(string $metricKey, array $range, \Closure $perNight, int $nightsMax): int|float
    {
        [$from, $to] = $range;

        $values = [];
        for ($d = $from->copy(); $d->lt($to) && count($values) <= $nightsMax; $d->addDay()) {
            $values[] = $perNight($d)[$metricKey];
        }

        if ($values === []) {
            return 0;
        }

        if (in_array($metricKey, self::STOCK_KEYS, true)) {
            return round(array_sum($values) / count($values), 1);
        }

        return array_sum($values);
    }
}
