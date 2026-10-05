<?php

namespace Tests\Feature;

use App\Models\AdditionalCharge;
use App\Models\Booking;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/**
 * 📊 (05/10/26) excel-reports spec §7 Seam 1 — HTTP feature tests สำหรับ 15 รายงาน Excel
 *
 *    - Role matrix: unauthenticated (401), user (403), admin (200 ครบ 15 ใบ),
 *      staff (200 ครบ 15 ใบ), housekeeping (200 เฉพาะ 2 ใบแม่บ้าน + 403 ที่เหลือ 13 ใบ)
 *    - Validation 422: ช่วงวันที่เกิน 366 วัน, ขาด required date param
 *    - Stream headers: Content-Type เป๊ะ + Content-Disposition มี filename*=UTF-8''
 *    - 404 สำหรับ slug ที่ไม่มีจริง (/api/v1/reports/nope/export)
 *    - ข้อมูลจริง 1 ใบ (additional-charges/export): โหลดไฟล์ผ่าน IOFactory และ assert ค่าใน cell
 */
class ReportExportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // 🚦 throttle:10,1 ของ route ไม่ใช่สิ่งที่ suite นี้ทดสอบ —
        //    role matrix ยิง 15 requests ต่อเคส จึงต้อง bypass ไม่งั้นโดน 429 เอง
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    /**
     * Map ของ slug รายงานทั้ง 15 ฉบับและ query params ขั้นต่ำที่จำเป็นต่อใบ
     *
     * @return array<string, array<string, string>>
     */
    private function validFilterMap(): array
    {
        return [
            'check-in' => ['checkin_date' => '2026-10-05'],
            'check-out' => ['checkout_date' => '2026-10-05'],
            'daily-financial' => ['date' => '2026-10-05'],
            'deposit' => ['date' => '2026-10-05'],
            'occupancy' => ['date' => '2026-10-05'],
            'breakfast' => ['date' => '2026-10-05'],
            'erp-transfer' => ['date_from' => '2026-10-01', 'date_to' => '2026-10-05'],
            'room-status' => [],
            'extra-bed' => ['date_from' => '2026-10-01', 'date_to' => '2026-10-05'],
            'supplies' => ['date_from' => '2026-10-01', 'date_to' => '2026-10-05'],
            'out-of-service-room' => ['date_from' => '2026-10-01', 'date_to' => '2026-10-05'],
            'additional-charges' => ['date_from' => '2026-10-01', 'date_to' => '2026-10-05'],
            'manager' => ['date' => '2026-10-05'],
            'housekeeping' => ['date' => '2026-10-05'],
            'housekeeping-v2' => ['date' => '2026-10-05'],
        ];
    }

    // ==========================================
    // 1. Role Matrix
    // ==========================================

    public function test_unauthenticated_request_returns_401(): void
    {
        $response = $this->getJson('/api/v1/reports/room-status/export');

        $response->assertStatus(401);
    }

    public function test_user_role_returns_403(): void
    {
        $user = User::factory()->create(['role' => 'user']);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/reports/room-status/export');

        $response->assertStatus(403);
    }

    public function test_admin_can_export_all_15_reports(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        foreach ($this->validFilterMap() as $slug => $filters) {
            $queryString = http_build_query($filters);
            $url = "/api/v1/reports/{$slug}/export" . ($queryString ? "?{$queryString}" : '');

            $response = $this->actingAs($admin, 'sanctum')->getJson($url);

            $response->assertStatus(200);
        }
    }

    public function test_staff_can_export_all_15_reports(): void
    {
        $staff = User::factory()->create(['role' => 'staff']);

        foreach ($this->validFilterMap() as $slug => $filters) {
            $queryString = http_build_query($filters);
            $url = "/api/v1/reports/{$slug}/export" . ($queryString ? "?{$queryString}" : '');

            $response = $this->actingAs($staff, 'sanctum')->getJson($url);

            $response->assertStatus(200);
        }
    }

    public function test_housekeeping_can_only_export_housekeeping_reports(): void
    {
        $housekeeper = User::factory()->create(['role' => 'housekeeping']);

        $housekeepingSlugs = ['housekeeping', 'housekeeping-v2'];

        foreach ($this->validFilterMap() as $slug => $filters) {
            $queryString = http_build_query($filters);
            $url = "/api/v1/reports/{$slug}/export" . ($queryString ? "?{$queryString}" : '');

            $response = $this->actingAs($housekeeper, 'sanctum')->getJson($url);

            if (in_array($slug, $housekeepingSlugs, true)) {
                $response->assertStatus(200);
            } else {
                $response->assertStatus(403);
            }
        }
    }

    // ==========================================
    // 2. Validation 422
    // ==========================================

    public function test_validation_date_range_exceeding_366_days_returns_422(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin, 'sanctum')->getJson(
            '/api/v1/reports/extra-bed/export?date_from=2026-01-01&date_to=2027-06-01'
        );

        $response->assertStatus(422);
    }

    public function test_validation_missing_required_date_param_returns_422(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin, 'sanctum')->getJson(
            '/api/v1/reports/daily-financial/export'
        );

        $response->assertStatus(422);
    }

    // ==========================================
    // 3. Stream Headers
    // ==========================================

    public function test_stream_response_headers(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin, 'sanctum')->getJson('/api/v1/reports/daily-financial/export?date=2026-10-05');

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $disposition = $response->headers->get('Content-Disposition');
        $this->assertNotNull($disposition);
        $this->assertStringContainsString("filename*=UTF-8''", $disposition);
        $this->assertStringContainsString('attachment;', $disposition);
    }

    // ==========================================
    // 4. Unknown Slug (404)
    // ==========================================

    public function test_non_existent_report_slug_returns_404(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin, 'sanctum')->getJson('/api/v1/reports/nope/export');

        $response->assertStatus(404);
    }

    // ==========================================
    // 5. ข้อมูลจริง 1 ใบ — additional-charges/export
    // ==========================================

    public function test_additional_charges_export_contains_actual_database_records(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $booking = Booking::create([
            'id' => Str::uuid(),
            'user_id' => $admin->id,
            'confirmation' => 'BK-TEST-12345',
            'source' => 'online',
            'status' => 'confirmed',
            'total_amount' => 1500,
            'is_paid' => true,
        ]);

        AdditionalCharge::create([
            'id' => Str::uuid(),
            'booking_id' => $booking->id,
            'transaction_date' => '2026-10-03',
            'item_code' => 'DMG-TOWEL-01',
            'item_name' => 'ผ้าเช็ดตัวเปื้อนสี',
            'qty' => 2,
            'unit' => 'ผืน',
            'price' => 400,
            'charge_type' => AdditionalCharge::TYPE_DAMAGE,
            'recorded_by' => $admin->id,
        ]);

        $response = $this->actingAs($admin, 'sanctum')->getJson(
            '/api/v1/reports/additional-charges/export?date_from=2026-10-01&date_to=2026-10-05'
        );

        $response->assertStatus(200);

        // ดึง binary content จาก StreamedResponse
        ob_start();
        $response->sendContent();
        $content = ob_get_clean();

        $this->assertNotEmpty($content);
        $this->assertStringStartsWith('PK', $content);

        // บันทึกลง temporary file และโหลดผ่าน IOFactory::load()
        $tempFile = tempnam(sys_get_temp_dir(), 'add_charge_test_') . '.xlsx';
        file_put_contents($tempFile, $content);

        try {
            $spreadsheet = IOFactory::load($tempFile);
            $sheet = $spreadsheet->getActiveSheet();

            // ค้นหาค่าใน cell ของชีต
            $foundItemName = false;
            $foundPrice = false;
            $foundBookingNo = false;

            $highestRow = $sheet->getHighestRow();
            $highestColumn = $sheet->getHighestColumn();

            for ($row = 1; $row <= $highestRow; $row++) {
                for ($col = 'A'; $col <= $highestColumn; $col++) {
                    $cellValue = (string) $sheet->getCell("{$col}{$row}")->getValue();

                    if (str_contains($cellValue, 'ผ้าเช็ดตัวเปื้อนสี')) {
                        $foundItemName = true;
                    }
                    if ($cellValue === '400') {
                        $foundPrice = true;
                    }
                    if (str_contains($cellValue, 'BK-TEST-12345')) {
                        $foundBookingNo = true;
                    }
                }
            }

            $this->assertTrue($foundItemName, 'Expected to find item_name "ผ้าเช็ดตัวเปื้อนสี" in spreadsheet cells');
            $this->assertTrue($foundPrice, 'Expected to find price "400" in spreadsheet cells');
            $this->assertTrue($foundBookingNo, 'Expected to find booking confirmation "BK-TEST-12345" in spreadsheet cells');
        } finally {
            if (file_exists($tempFile)) {
                @unlink($tempFile);
            }
        }
    }
}
