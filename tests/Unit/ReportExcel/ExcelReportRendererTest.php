<?php

namespace Tests\Unit\ReportExcel;

use App\Services\ReportExcel\BaseReportData;
use App\Services\ReportExcel\ExcelReportRenderer;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\TestCase;

/**
 * 📊 (05/10/26) excel-reports spec §7 Seam 2 — ทดสอบ ExcelReportRenderer แบบ Unit test
 *
 *    - ตรวจสอบโครงสร้าง xlsx: ชื่อรายงาน, บรรทัด filter, header row, mock data, number format, วันที่ พ.ศ.
 *    - ตรวจสอบ section row bold ไม่ merge (getMergeCells ว่างเปล่า)
 *    - ตรวจสอบ summary row ท้ายตาราง
 *    - ตรวจสอบ print setup: A4 Landscape, freezePane A4, rowsToRepeatAtTop, autoFilter
 *    - ตรวจสอบชื่อชีต sanitized
 *    - ตรวจสอบ streamResponse: Content-Type + Content-Disposition (RFC 5987 UTF-8 filename)
 */
class ExcelReportRendererTest extends TestCase
{
    private ExcelReportRenderer $renderer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->renderer = new ExcelReportRenderer;
    }

    /**
     * ✅ Seam 2: ทดสอบการ render ไฟล์ Excel ครบถ้วนตาม spec §1.2, §5 และ assert ด้วย IOFactory::load()
     */
    public function test_renders_excel_report_matching_spec_and_template(): void
    {
        $report = new StubCheckInReportData;
        $filters = [
            'checkin_date' => '2026-09-11',
            'room_type' => 'Superior',
        ];

        // 1. เรนเดอร์เป็น Spreadsheet ใน memory
        $spreadsheet = $this->renderer->render($report, $filters);

        // ตรวจสอบบน Spreadsheet instance ก่อนบันทึก
        $this->assertSame('A4', $spreadsheet->getActiveSheet()->getFreezePane());
        $this->assertSame([3, 3], $spreadsheet->getActiveSheet()->getPageSetup()->getRowsToRepeatAtTop());
        $this->assertSame(PageSetup::PAPERSIZE_A4, $spreadsheet->getActiveSheet()->getPageSetup()->getPaperSize());
        $this->assertSame(PageSetup::ORIENTATION_LANDSCAPE, $spreadsheet->getActiveSheet()->getPageSetup()->getOrientation());
        $this->assertSame('A3:M7', $spreadsheet->getActiveSheet()->getAutoFilter()->getRange());
        $this->assertEmpty($spreadsheet->getActiveSheet()->getMergeCells());

        // 2. บันทึกลง temporary file และโหลดกลับมาผ่าน IOFactory::load() เพื่อ assert เสมือนจริง
        $tempFile = tempnam(sys_get_temp_dir(), 'excel_report_test_').'.xlsx';
        $writer = new Xlsx($spreadsheet);
        $writer->save($tempFile);

        try {
            $loaded = IOFactory::load($tempFile);
            $sheet = $loaded->getActiveSheet();

            // 🏷️ ชื่อชีต (tab)
            $this->assertSame('รายงานห้องเข้าพัก', $sheet->getTitle());

            // 📝 แถว 1: ชื่อรายงาน (template name_th) — bold ขนาด 14
            $this->assertSame('รายงานห้องเข้าพัก', $sheet->getCell('A1')->getValue());
            $this->assertTrue($sheet->getStyle('A1')->getFont()->getBold());
            $this->assertEquals(14, $sheet->getStyle('A1')->getFont()->getSize());

            // 📝 แถว 2: บรรทัด filter/ช่วงวันที่ — bold, มี "ข้อมูลวันที่" + ปี พ.ศ. 2569 และ filter เพิ่มเติม
            $filterLine = (string) $sheet->getCell('A2')->getValue();
            $this->assertStringContainsString('ข้อมูลวันที่', $filterLine);
            $this->assertStringContainsString('/2569', $filterLine);
            $this->assertStringContainsString('11/09/2569', $filterLine);
            $this->assertStringContainsString('ประเภทห้องพัก: Superior', $filterLine);
            $this->assertTrue($sheet->getStyle('A2')->getFont()->getBold());

            // 📋 แถว 3: Header row ตรงกับ label_th ของ check-in-report template
            $expectedHeaders = [
                'A' => 'บุ๊กกิ้ง',
                'B' => 'เลขห้อง',
                'C' => 'โอนระหว่างหน่วยงาน',
                'D' => 'ชื่อผู้พัก',
                'E' => 'ประเภทของห้องพัก',
                'F' => 'วันที่เช็คอิน',
                'G' => 'วันที่เช็คเอ้าท์',
                'H' => 'จำนวนคืน',
                'I' => 'ราคาเต็ม',
                'J' => 'ชำระแล้ว',
                'K' => 'ค้างชำระ',
                'L' => 'คำขอพิเศษ (ถ้ามี)',
                'M' => 'ข้อมูล Early Check in / Extra bed (ถ้ามี)',
            ];
            foreach ($expectedHeaders as $colLetter => $expectedLabel) {
                $this->assertSame($expectedLabel, $sheet->getCell("{$colLetter}3")->getValue());
            }
            $this->assertTrue($sheet->getStyle('A3')->getFont()->getBold());
            $this->assertTrue($sheet->getStyle('M3')->getFont()->getBold());

            // 📊 แถว 4: Section แรก (_section = 'Fully Paid') — bold และไม่มีการ merge cell
            $this->assertSame('Fully Paid', $sheet->getCell('A4')->getValue());
            $this->assertTrue($sheet->getStyle('A4')->getFont()->getBold());

            // 📊 แถว 5: แถวข้อมูลแรก (B001)
            $this->assertSame('B001', $sheet->getCell('A5')->getValue());
            $this->assertSame('501', (string) $sheet->getCell('B5')->getValue());
            $this->assertSame('FALSE', $sheet->getCell('C5')->getValue());
            $this->assertSame('คุณมานิตย์ คำสวย', $sheet->getCell('D5')->getValue());
            $this->assertSame('Superior', $sheet->getCell('E5')->getValue());
            $this->assertSame('11/09/2569', $sheet->getCell('F5')->getValue()); // วันที่ render เป็น string พ.ศ.
            $this->assertSame('12/09/2569', $sheet->getCell('G5')->getValue());
            $this->assertEquals(1, $sheet->getCell('H5')->getValue());

            // ตรวจสอบค่าตัวเลข money_baht และ number format '#,##0'
            $this->assertEquals(1000, $sheet->getCell('I5')->getValue());
            $this->assertEquals(1000, $sheet->getCell('J5')->getValue());
            $this->assertEquals(0, $sheet->getCell('K5')->getValue());
            $this->assertSame('#,##0', $sheet->getStyle('I5')->getNumberFormat()->getFormatCode());
            $this->assertSame('#,##0', $sheet->getStyle('J5')->getNumberFormat()->getFormatCode());
            $this->assertSame('#,##0', $sheet->getStyle('K5')->getNumberFormat()->getFormatCode());

            // 📊 แถว 6: Section ที่สอง (_section = 'deposit/ยังไม่ได้ชำระ') — bold ธรรมดา
            $this->assertSame('deposit/ยังไม่ได้ชำระ', $sheet->getCell('A6')->getValue());
            $this->assertTrue($sheet->getStyle('A6')->getFont()->getBold());

            // 📊 แถว 7: แถวข้อมูลที่สอง (B002)
            $this->assertSame('B002', $sheet->getCell('A7')->getValue());
            $this->assertSame('716', (string) $sheet->getCell('B7')->getValue());
            $this->assertSame('TRUE', $sheet->getCell('C7')->getValue());
            $this->assertEquals(1000, $sheet->getCell('I7')->getValue());
            $this->assertEquals(0, $sheet->getCell('J7')->getValue());
            $this->assertEquals(1000, $sheet->getCell('K7')->getValue());
            $this->assertSame('ขอกาน้ำ', $sheet->getCell('L7')->getValue());
            $this->assertSame('Extra bed 1 เตียง', $sheet->getCell('M7')->getValue());

            // 🧮 แถว 8: แถว summary ท้ายตาราง — แถวเดียว bold และตรงคอลัมน์
            $this->assertSame('รวมทั้งหมด', $sheet->getCell('A8')->getValue());
            $this->assertEquals(2000, $sheet->getCell('I8')->getValue());
            $this->assertEquals(1000, $sheet->getCell('J8')->getValue());
            $this->assertEquals(1000, $sheet->getCell('K8')->getValue());
            $this->assertTrue($sheet->getStyle('A8')->getFont()->getBold());
            $this->assertTrue($sheet->getStyle('I8')->getFont()->getBold());
            $this->assertSame('#,##0', $sheet->getStyle('I8')->getNumberFormat()->getFormatCode());

            // 🚫 ตรวจสอบว่าไม่มี merged cell ทั้งชีต (Minimal flat table)
            $this->assertEmpty($sheet->getMergeCells());

            // 🖨️ ตรวจสอบ Print setup และ Page setup
            $this->assertSame(PageSetup::PAPERSIZE_A4, $sheet->getPageSetup()->getPaperSize());
            $this->assertSame(PageSetup::ORIENTATION_LANDSCAPE, $sheet->getPageSetup()->getOrientation());
            $this->assertSame([3, 3], $sheet->getPageSetup()->getRowsToRepeatAtTop());

            // 🔍 ตรวจสอบ AutoFilter ครอบตั้งแต่ header ถึงแถวข้อมูลสุดท้าย (ไม่รวม summary แถว 8)
            $this->assertSame('A3:M7', $sheet->getAutoFilter()->getRange());
        } finally {
            if (file_exists($tempFile)) {
                @unlink($tempFile);
            }
        }
    }

    /**
     * ✅ ทดสอบการ sanitize ชื่อชีต (tab title) ตามข้อจำกัดของ Excel
     */
    public function test_sanitizes_sheet_title_properly(): void
    {
        // 1. อักขระต้องห้าม : \ / ? * [ ] ต้องถูกแทนที่ด้วย '-'
        $dirtyTitle = 'รายงาน:เข้าพัก/ออก\\ทดสอบ?ดอกจัน*ก้ามปู[1]';
        $sanitized = $this->renderer->sanitizeSheetTitle($dirtyTitle);
        $this->assertStringNotContainsString(':', $sanitized);
        $this->assertStringNotContainsString('/', $sanitized);
        $this->assertStringNotContainsString('\\', $sanitized);
        $this->assertStringNotContainsString('?', $sanitized);
        $this->assertStringNotContainsString('*', $sanitized);
        $this->assertStringNotContainsString('[', $sanitized);
        $this->assertStringNotContainsString(']', $sanitized);

        // 2. ความยาวต้องไม่เกิน 31 ตัวอักษร
        $longTitle = 'รายงานห้องเข้าพักที่มีขนาดยาวมากๆเกินสามสิบเอ็ดตัวอักษรแน่นอน';
        $sanitizedLong = $this->renderer->sanitizeSheetTitle($longTitle);
        $this->assertLessThanOrEqual(31, mb_strlen($sanitizedLong));

        // 3. ป้องกันชื่อว่างเปล่า
        $emptyTitle = ':::';
        $sanitizedEmpty = $this->renderer->sanitizeSheetTitle($emptyTitle);
        $this->assertNotEmpty($sanitizedEmpty);
    }

    /**
     * ✅ ทดสอบ filename() ทั้งกรณีวันเดียว, ช่วงวันที่ และไม่มีวันที่
     */
    public function test_filename_generation(): void
    {
        $report = new StubCheckInReportData;

        // 1. วันเดียว (checkin_date)
        $fn1 = $this->renderer->filename($report, ['checkin_date' => '2026-09-11']);
        $this->assertSame('รายงานห้องเข้าพัก (11-09-2569).xlsx', $fn1);

        // 2. ช่วงวันที่
        $fn2 = $this->renderer->filename($report, [
            'date_from' => '2026-09-14',
            'date_to' => '2026-09-20',
        ]);
        $this->assertSame('รายงานห้องเข้าพัก (14-09-2569_ถึง_20-09-2569).xlsx', $fn2);

        // 3. กรณีไม่ส่ง filter วันที่
        $fn3 = $this->renderer->filename($report, []);
        $this->assertSame('รายงานห้องเข้าพัก.xlsx', $fn3);
    }

    /**
     * ✅ ทดสอบ streamResponse() ตรวจสอบ Header Content-Type, Content-Disposition RFC 5987 และ Binary Stream
     */
    public function test_stream_response_headers_and_content(): void
    {
        $report = new StubCheckInReportData;
        $filters = ['checkin_date' => '2026-09-11'];

        $response = $this->renderer->streamResponse($report, $filters);

        $this->assertInstanceOf(StreamedResponse::class, $response);
        $this->assertSame(200, $response->getStatusCode());

        // Header Content-Type สำหรับ xlsx
        $contentType = $response->headers->get('Content-Type');
        $this->assertSame('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $contentType);

        // Header Content-Disposition ตาม RFC 5987
        $disposition = $response->headers->get('Content-Disposition');
        $this->assertNotNull($disposition);
        $this->assertStringContainsString('attachment;', $disposition);
        $this->assertStringContainsString('filename=report.xlsx', $disposition);
        $this->assertStringContainsString("filename*=UTF-8''", $disposition);

        $expectedFilename = 'รายงานห้องเข้าพัก (11-09-2569).xlsx';
        $this->assertStringContainsString(rawurlencode($expectedFilename), $disposition);

        // ทดสอบการ stream binary content ออกมา (ต้องเป็น zip archive ที่ขึ้นต้นด้วย PK)
        ob_start();
        $response->sendContent();
        $output = ob_get_clean();

        $this->assertNotEmpty($output);
        $this->assertStringStartsWith('PK', $output);
    }
}

/**
 * 🧪 Stub ReportData สำหรับ Check-in report (ใช้ template check-in-report.json)
 */
class StubCheckInReportData extends BaseReportData
{
    public function templateId(): string
    {
        return 'check-in-report';
    }

    public function filterRules(): array
    {
        return [];
    }

    public function rows(array $filters): iterable
    {
        return [
            ['_section' => 'Fully Paid'],
            [
                'booking_no' => 'B001',
                'room_no' => '501',
                'is_inter_unit_transfer' => false,
                'guest_name' => 'คุณมานิตย์ คำสวย',
                'room_type' => 'Superior',
                'checkin_date' => '2026-09-11',
                'checkout_date' => '2026-09-12',
                'nights' => 1,
                'full_price' => 1000,
                'paid_amount' => 1000,
                'outstanding_amount' => 0,
                'special_request' => null,
                'early_checkin_extra_bed_note' => null,
            ],
            ['_section' => 'deposit/ยังไม่ได้ชำระ'],
            [
                'booking_no' => 'B002',
                'room_no' => '716',
                'is_inter_unit_transfer' => true,
                'guest_name' => 'คุณพงศกร สีเหลือง',
                'room_type' => 'Deluxe',
                'checkin_date' => '2026-09-11',
                'checkout_date' => '2026-09-12',
                'nights' => 1,
                'full_price' => 1000,
                'paid_amount' => 0,
                'outstanding_amount' => 1000,
                'special_request' => 'ขอกาน้ำ',
                'early_checkin_extra_bed_note' => 'Extra bed 1 เตียง',
            ],
        ];
    }

    public function summary(array $filters): array
    {
        return [
            '_label' => 'รวมทั้งหมด',
            'full_price' => 2000,
            'paid_amount' => 1000,
            'outstanding_amount' => 1000,
        ];
    }
}
