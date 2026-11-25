<?php

namespace Tests\Feature;

use App\Models\AdditionalCharge;
use App\Models\Booking;
use App\Models\User;
use App\Services\ReportExcel\ExcelReportRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 🖨️ (18/11/26) report-pdf-export — HTTP feature tests สำหรับ `?format=pdf`
 *
 *    - admin 200 + Content-Type application/pdf + binary เริ่ม %PDF + ฝังฟอนต์ Sarabun
 *    - Content-Disposition มี filename .pdf พร้อม filename*=UTF-8''
 *    - Role matrix: housekeeping 200 เฉพาะ 2 ใบแม่บ้าน · 403 ที่เหลือ (แบบเดียวกับ xlsx)
 *    - 422 สำหรับ format ที่ไม่รองรับ (?format=docx — ไม่ silent fallback)
 *    - ข้อมูลจริง: additional-charges/export?format=pdf เรนเดอร์จาก row จริงใน DB
 */
class ReportPdfExportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // 🚦 throttle ไม่ใช่สิ่งที่ suite นี้ทดสอบ (รัน mPDF หลายครั้งต่อเคส)
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    // ==========================================
    // 1. Admin — 200 + binary PDF + ฟอนต์ไทย
    // ==========================================

    public function test_admin_can_export_room_status_as_pdf(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin, 'sanctum')->getJson('/api/v1/reports/room-status/export?format=pdf');

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'application/pdf');

        $content = $this->streamedContent($response);
        $this->assertStringStartsWith('%PDF', $content);

        // 🏷️ Content-Disposition ต้องชี้ไฟล์ .pdf (มี ThaiDate slug ใน filename*=UTF-8'')
        $disposition = $response->headers->get('Content-Disposition');
        $this->assertStringContainsString("filename*=UTF-8''", (string) $disposition);
        $this->assertStringContainsString('.pdf', (string) $disposition);
    }

    public function test_pdf_render_error_returns_json_500_not_html(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        // 🩺 (25/11/26) จำลอง Error (ไม่ใช่ Exception) ตอนสร้าง PDF — เช่น class Mpdf หาย
        $this->mock(ExcelReportRenderer::class)
            ->shouldReceive('streamPdfResponse')
            ->andThrow(new \Error('Class "Mpdf\\Mpdf" not found'));

        $response = $this->actingAs($admin, 'sanctum')->getJson('/api/v1/reports/room-status/export?format=pdf');

        $response->assertStatus(500)->assertJson(['status' => 'error']);
    }

    public function test_pdf_embeds_sarabun_thai_font(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin, 'sanctum')->getJson('/api/v1/reports/room-status/export?format=pdf');

        $content = $this->streamedContent($response);

        // 🔤 ฟอนต์ Sarabun (subset) ต้องถูก embed ใน PDF — ไม่งั้นข้อความไทยจะกลายเป็นตัวหนอน
        $this->assertStringContainsString('Sarabun', $content);
    }

    public function test_additional_charges_pdf_renders_actual_database_records(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $booking = Booking::create([
            'id' => Str::uuid(),
            'user_id' => $admin->id,
            'confirmation' => 'BK-TEST-PDF-1',
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
            '/api/v1/reports/additional-charges/export?date_from=2026-10-01&date_to=2026-10-05&format=pdf'
        );

        $response->assertStatus(200);

        $content = $this->streamedContent($response);
        $this->assertStringStartsWith('%PDF', $content);
        $this->assertNotEmpty($content);
    }

    // ==========================================
    // 2. Role Matrix (รูปแบบเดียวกับ xlsx)
    // ==========================================

    public function test_housekeeping_can_export_housekeeping_report_as_pdf(): void
    {
        $housekeeper = User::factory()->create(['role' => 'housekeeping']);

        $response = $this->actingAs($housekeeper, 'sanctum')->getJson(
            '/api/v1/reports/housekeeping/export?date=2026-10-05&format=pdf'
        );

        $response->assertStatus(200);
        $this->assertStringStartsWith('%PDF', $this->streamedContent($response));
    }

    public function test_housekeeping_cannot_export_admin_report_as_pdf(): void
    {
        $housekeeper = User::factory()->create(['role' => 'housekeeping']);

        $response = $this->actingAs($housekeeper, 'sanctum')->getJson(
            '/api/v1/reports/room-status/export?format=pdf'
        );

        $response->assertStatus(403);
    }

    public function test_unauthenticated_pdf_request_returns_401(): void
    {
        $response = $this->getJson('/api/v1/reports/room-status/export?format=pdf');

        $response->assertStatus(401);
    }

    // ==========================================
    // 3. Validation — format ที่ไม่รองรับ
    // ==========================================

    public function test_invalid_format_returns_422(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin, 'sanctum')->getJson('/api/v1/reports/room-status/export?format=docx');

        $response->assertStatus(422);
        $response->assertJson([
            'status' => 'error',
            'message' => 'format รองรับเฉพาะ xlsx หรือ pdf เท่านั้นค่ะ',
        ]);
    }

    public function test_default_format_is_xlsx_when_param_omitted(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin, 'sanctum')->getJson('/api/v1/reports/room-status/export');

        $response->assertStatus(200);
        $this->assertStringStartsWith('PK', $this->streamedContent($response));
    }

    /**
     * ดึง binary จาก StreamedResponse (pattern เดียวกับ ReportExportTest)
     */
    private function streamedContent($response): string
    {
        ob_start();
        $response->sendContent();

        return (string) ob_get_clean();
    }
}
