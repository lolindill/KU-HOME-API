<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\ReportExcel\Contracts\ReportData;
use App\Services\ReportExcel\Data\AdditionalChargesReportData;
use App\Services\ReportExcel\Data\BreakfastReportData;
use App\Services\ReportExcel\Data\CheckInReportData;
use App\Services\ReportExcel\Data\CheckOutReportData;
use App\Services\ReportExcel\Data\DailyFinancialReportData;
use App\Services\ReportExcel\Data\DepositReportData;
use App\Services\ReportExcel\Data\ErpTransferReportData;
use App\Services\ReportExcel\Data\ExtraBedReportData;
use App\Services\ReportExcel\Data\HousekeepingReportData;
use App\Services\ReportExcel\Data\HousekeepingReportV2Data;
use App\Services\ReportExcel\Data\ManagerReportData;
use App\Services\ReportExcel\Data\OccupancyReportData;
use App\Services\ReportExcel\Data\OutOfServiceRoomReportData;
use App\Services\ReportExcel\Data\RoomStatusReportData;
use App\Services\ReportExcel\Data\SuppliesReportData;
use App\Services\ReportExcel\ExcelReportRenderer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * 📊 (05/10/26) excel-reports spec §4 — API shell ของรายงาน 15 ฉบับ
 *
 *    - **route ต่อรายงาน (explicit 15 routes — owner เลือกแยก ไม่ใช้ generic {report-id})**
 *      ชี้ method บาง ๆ ต่อใบ แล้ว delegate เข้า ReportData ของรายงานนั้น
 *    - สิทธิ์ตามธรรมเนียม CheckRole ที่ route + **in-controller re-check** (defense-in-depth)
 *      · admin+staff ทุกใบ · housekeeping เฉพาะ 2 ใบแม่บ้าน
 *    - stream ไฟล์ทันที (synchronous — ไม่มีไฟล์ค้าง disk) · throttle 10,1 ที่ route
 *    - error contract: 422 validate (filterRules ต่อรายงาน) · 403 ไม่มีสิทธิ์ ·
 *      500 generic message + Log::error ตาม convention
 */
class ReportController extends Controller
{
    /**
     * registry slug → [ReportData class, สิทธิ์เสริมนอกเหนือ admin,staff]
     */
    private const REPORTS = [
        'check-in' => [CheckInReportData::class, []],
        'check-out' => [CheckOutReportData::class, []],
        'daily-financial' => [DailyFinancialReportData::class, []],
        'deposit' => [DepositReportData::class, []],
        'occupancy' => [OccupancyReportData::class, []],
        'breakfast' => [BreakfastReportData::class, []],
        'erp-transfer' => [ErpTransferReportData::class, []],
        'housekeeping' => [HousekeepingReportData::class, ['housekeeping']],
        'housekeeping-v2' => [HousekeepingReportV2Data::class, ['housekeeping']],
        'room-status' => [RoomStatusReportData::class, []],
        'extra-bed' => [ExtraBedReportData::class, []],
        'supplies' => [SuppliesReportData::class, []],
        'out-of-service-room' => [OutOfServiceRoomReportData::class, []],
        'additional-charges' => [AdditionalChargesReportData::class, []],
        'manager' => [ManagerReportData::class, []],
    ];

    // 📋 15 explicit endpoints — แต่ละ method = 1 route (ดู routes/api.php)

    public function checkIn(Request $request): StreamedResponse|JsonResponse
    {
        return $this->exportReport($request, 'check-in');
    }

    public function checkOut(Request $request): StreamedResponse|JsonResponse
    {
        return $this->exportReport($request, 'check-out');
    }

    public function dailyFinancial(Request $request): StreamedResponse|JsonResponse
    {
        return $this->exportReport($request, 'daily-financial');
    }

    public function deposit(Request $request): StreamedResponse|JsonResponse
    {
        return $this->exportReport($request, 'deposit');
    }

    public function occupancy(Request $request): StreamedResponse|JsonResponse
    {
        return $this->exportReport($request, 'occupancy');
    }

    public function breakfast(Request $request): StreamedResponse|JsonResponse
    {
        return $this->exportReport($request, 'breakfast');
    }

    public function erpTransfer(Request $request): StreamedResponse|JsonResponse
    {
        return $this->exportReport($request, 'erp-transfer');
    }

    public function housekeeping(Request $request): StreamedResponse|JsonResponse
    {
        return $this->exportReport($request, 'housekeeping');
    }

    public function housekeepingV2(Request $request): StreamedResponse|JsonResponse
    {
        return $this->exportReport($request, 'housekeeping-v2');
    }

    public function roomStatus(Request $request): StreamedResponse|JsonResponse
    {
        return $this->exportReport($request, 'room-status');
    }

    public function extraBed(Request $request): StreamedResponse|JsonResponse
    {
        return $this->exportReport($request, 'extra-bed');
    }

    public function supplies(Request $request): StreamedResponse|JsonResponse
    {
        return $this->exportReport($request, 'supplies');
    }

    public function outOfServiceRoom(Request $request): StreamedResponse|JsonResponse
    {
        return $this->exportReport($request, 'out-of-service-room');
    }

    public function additionalCharges(Request $request): StreamedResponse|JsonResponse
    {
        return $this->exportReport($request, 'additional-charges');
    }

    public function manager(Request $request): StreamedResponse|JsonResponse
    {
        return $this->exportReport($request, 'manager');
    }

    /**
     * flow กลาง: สิทธิ์ → validate filterRules ต่อรายงาน → stream ผ่าน engine
     */
    private function exportReport(Request $request, string $slug): StreamedResponse|JsonResponse
    {
        try {
            $config = self::REPORTS[$slug] ?? null;

            if ($config === null) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'ไม่พบรายงานที่ระบุค่ะ',
                ], 404);
            }

            // 🛡️ in-controller re-check (defense-in-depth — เสริมจาก CheckRole ที่ route)
            $user = $request->user('sanctum');
            $allowedRoles = array_unique(['admin', 'staff', ...$config[1]]);

            if (! $user || ! in_array($user->role, $allowedRoles, true)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'คุณไม่มีสิทธิ์เข้าถึงรายงานนี้ค่ะ',
                ], 403);
            }

            /** @var ReportData $report */
            $report = app($config[0]);

            // 🔍 validate filter ต่อรายงาน (รวมเพดานช่วงวันที่ ≤ 1 ปี) — 422 อัตโนมัติ
            $filters = $request->validate($report->filterRules());

            return app(ExcelReportRenderer::class)->streamResponse($report, $filters);
        } catch (\Exception $e) {
            Log::error("Report export failed [{$slug}]: ".$e->getMessage(), [
                'user_id' => optional($request->user('sanctum'))->id,
                'filters' => $request->query(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'เกิดข้อผิดพลาดในการสร้างรายงาน กรุณาลองใหม่อีกครั้งค่ะนายท่าน 😭',
            ], 500);
        }
    }
}
