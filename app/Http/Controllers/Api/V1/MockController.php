<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Carbon\Carbon;

class MockController extends Controller
{
    /**
     * 🚧 DRAFT / TESTING — Mock sold-out intervals ราย room type สำหรับ frontend test
     *
     * ไม่อ่านข้อมูลจาก DB — วันที่คำนวณสัมพันธ์กับ Carbon::today() (ไม่มีวันหมดอายุ):
     * - Window: start_date = today, end_date = today+30
     * - Superior: 2 intervals ([today+5, today+8], [today+15, today+18])
     * - Deluxe: 1 interval ([today+10, today+12])
     * - Suite: 0 intervals (intervals: [])
     */
    public function availabilityRanges()
    {
        $today = Carbon::today();

        return response()->json([
            'status' => 'success',
            'message' => 'Availability ranges fetched successfully',
            'start_date' => $today->toDateString(),
            'end_date' => $today->copy()->addDays(30)->toDateString(),
            'room_types' => [
                [
                    'room_type_id' => '00000000-0000-4000-8000-000000000001',
                    'name_en' => 'Superior',
                    'name_th' => 'ห้องซูพีเรียร์',
                    'intervals' => [
                        [
                            'start_date' => $today->copy()->addDays(5)->toDateString(),
                            'end_date' => $today->copy()->addDays(8)->toDateString(),
                        ],
                        [
                            'start_date' => $today->copy()->addDays(15)->toDateString(),
                            'end_date' => $today->copy()->addDays(18)->toDateString(),
                        ],
                    ],
                ],
                [
                    'room_type_id' => '00000000-0000-4000-8000-000000000002',
                    'name_en' => 'Deluxe',
                    'name_th' => 'ห้องดีลักซ์',
                    'intervals' => [
                        [
                            'start_date' => $today->copy()->addDays(10)->toDateString(),
                            'end_date' => $today->copy()->addDays(12)->toDateString(),
                        ],
                    ],
                ],
                [
                    'room_type_id' => '00000000-0000-4000-8000-000000000003',
                    'name_en' => 'Suite',
                    'name_th' => 'ห้องสวีท',
                    'intervals' => [],
                ],
            ],
        ]);
    }
}
