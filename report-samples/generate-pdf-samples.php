<?php

/**
 * 📊 (18/11/26 — report-pdf-export) ตัวสร้างตัวอย่าง xlsx + pdf สำหรับเช็คฟอร์แมต
 *
 *    สร้าง 3 รายงาน (room-status · check-in · housekeeping) ทั้งสองฟอร์แมต
 *    ข้อมูลจำลอง INSERT ทั้งหมด "ใน transaction แล้ว ROLLBACK ทิ้ง" — DB ไม่เปลี่ยนแปลง
 *    ใช้จาก repo root: php report-samples/generate-pdf-samples.php
 *
 *    ⚠️ dev-only — report-samples/ ถูกตัดออกจาก main โดย scripts/sync-main.sh
 */

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

use App\Models\Booking;
use App\Models\BookingRoom;
use App\Models\HousekeepingTask;
use App\Models\Payment;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use App\Services\ReportExcel\Data\CheckInReportData;
use App\Services\ReportExcel\Data\HousekeepingReportData;
use App\Services\ReportExcel\Data\RoomStatusReportData;
use App\Services\ReportExcel\ExcelReportRenderer;
use App\Services\ReportExcel\ThaiMpdfWriter;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

$CHECKIN_DATE = '2026-11-17';

DB::beginTransaction();

try {
    $maid = User::where('email', 'housekeeping@kuhome.com')->first();
    $owner = User::where('email', 'admin@kuhome.com')->first();

    $types = RoomType::query()->get()->keyBy('name_en');
    $pickRoom = fn (string $type) => Room::where('room_type_id', $types[$type]->id)->orderBy('room_number')->first();
    $rooms = [
        'Superior' => $pickRoom('Superior'),
        'Deluxe' => $pickRoom('Deluxe'),
        'Suite' => $pickRoom('Suite'),
    ];

    $guests = fn (string $title, string $first, string $last, string $phone) => [[
        'title' => $title,
        'name' => $first.' '.$last,
        'firstName' => $first,
        'lastName' => $last,
        'email' => 'guest@example.com',
        'phone' => $phone,
        'nationality' => 'ไทย',
    ]];

    // 🏨 Booking 1 — ชำระเต็ม 3 คืน (เข้า section "Fully Paid" ของ check-in report)
    $b1 = Booking::create([
        'id' => Str::uuid(),
        'user_id' => $owner?->id,
        'confirmation' => 'BK-2569-00001',
        'source' => 'online',
        'status' => 'confirmed',
        'total_amount' => 3600,
        'is_paid' => true,
        'special_request' => 'ขอห้องใกล้ลิฟต์ค่ะ',
    ]);
    Payment::create([
        'booking_id' => $b1->id,
        'amount' => 3600,
        'status' => 'completed',
        'reference_number' => 'REF-2569-0001',
        'received_by' => $owner?->id,
    ]);
    $br1 = BookingRoom::create([
        'booking_id' => $b1->id,
        'room_type_id' => $rooms['Superior']->room_type_id,
        'room_id' => $rooms['Superior']->id,
        'check_in' => $CHECKIN_DATE,
        'check_out' => '2026-11-20',
        'guests' => $guests('นางสาว', 'สมใจ', 'ใจดี', '081-234-5678'),
        'status' => 'checked_in',
        'bed_preference' => 'king_size',
        'room_amount' => 3600,
        'discount_amount' => 0,
        'amount' => 3600,
    ]);

    // 🏨 Booking 2 — มัดจำ 2 ห้อง (เข้า section "deposit/ยังไม่ได้ชำระ")
    $b2 = Booking::create([
        'id' => Str::uuid(),
        'user_id' => $owner?->id,
        'confirmation' => 'BK-2569-00002',
        'source' => 'online',
        'status' => 'confirmed',
        'total_amount' => 16000,
        'is_paid' => false,
    ]);
    Payment::create([
        'booking_id' => $b2->id,
        'amount' => 4000,
        'status' => 'completed',
        'reference_number' => 'REF-2569-0002',
        'received_by' => $owner?->id,
    ]);
    BookingRoom::create([
        'booking_id' => $b2->id,
        'room_type_id' => $rooms['Deluxe']->room_type_id,
        'room_id' => $rooms['Deluxe']->id,
        'check_in' => $CHECKIN_DATE,
        'check_out' => '2026-11-24',
        'guests' => $guests('นาย', 'ประเสริฐ', 'มั่นคง', '089-876-5432'),
        'status' => 'checked_in',
        'room_amount' => 7000,
        'discount_amount' => 0,
        'amount' => 7000,
    ]);
    $br3 = BookingRoom::create([
        'booking_id' => $b2->id,
        'room_type_id' => $rooms['Suite']->room_type_id,
        'room_id' => $rooms['Suite']->id,
        'check_in' => $CHECKIN_DATE,
        'check_out' => '2026-11-24',
        'guests' => $guests('นาง', 'มาลี', 'รุ่งเรือง', '086-111-2233'),
        'status' => 'checked_in',
        'bed_preference' => 'twin',
        'room_amount' => 9000,
        'discount_amount' => 0,
        'amount' => 9000,
    ]);

    // 🧹 Housekeeping tasks — สถานะหลากหลาย + checklist บางห้อง
    $tasks = [
        [$rooms['Superior'], 'checkout', 'done', true, true, true, 'เก็บเรียบร้อย ผ้าห่มชุดใหม่'],
        [$rooms['Deluxe'], 'pre_checkin', 'in_progress', true, false, false, null],
        [$rooms['Suite'], 'daily', 'accepted', false, false, false, null],
    ];
    $taskStatuses = ['done' => '2026-11-18 09:30', 'in_progress' => null, 'accepted' => null];
    foreach ($tasks as [$room, $type, $status, $c1, $c2, $c3, $notes]) {
        HousekeepingTask::create([
            'room_id' => $room->id,
            'assigned_to' => $status === 'unassigned' ? null : $maid?->id,
            'task_type' => $type,
            'status' => $status,
            'notes' => $notes,
            'accepted_at' => $status === 'done' || $status === 'in_progress' || $status === 'accepted' ? '2026-11-18 08:00' : null,
            'completed_at' => $status === 'done' ? '2026-11-18 09:30' : null,
            'scheduled_for' => $CHECKIN_DATE,
            'cleaning_check_1' => $c1,
            'cleaning_check_2' => $c2,
            'cleaning_check_3' => $c3,
        ]);
    }

    // 📄 เรนเดอร์ 3 รายงาน × 2 ฟอร์แมต (spreadsheet เดียวแต่ writer ต่างกัน)
    $renderer = app(ExcelReportRenderer::class);
    $jobs = [
        ['room-status', new RoomStatusReportData, [], 'room-status-sample'],
        ['check-in', new CheckInReportData, ['checkin_date' => $CHECKIN_DATE], 'check-in-sample'],
        ['housekeeping', new HousekeepingReportData, ['date' => $CHECKIN_DATE], 'housekeeping-sample'],
    ];

    foreach ($jobs as [$slug, $report, $filters, $name]) {
        // 🖨️ ใช้ renderForPdf() จุดเดียวกับ HTTP path — ให้ <thead> ซ้ำหัวทุกหน้า PDF
        $spreadsheet = $renderer->renderForPdf($report, $filters);

        $xlsxPath = __DIR__."/{$name}.xlsx";
        (new Xlsx($spreadsheet))->save($xlsxPath);

        $pdfPath = __DIR__."/{$name}.pdf";
        $pdfWriter = new ThaiMpdfWriter($spreadsheet);
        $pdfWriter->setTempDir(storage_path('app'));
        $pdfWriter->save($pdfPath);

        echo sprintf(
            '✅ %-13s → %s (%.1f KB) + %s (%.1f KB)%s',
            $slug,
            basename($xlsxPath),
            filesize($xlsxPath) / 1024,
            basename($pdfPath),
            filesize($pdfPath) / 1024,
            PHP_EOL
        );
    }

    echo PHP_EOL.'ตัวอย่างทั้งหมดอยู่ที่ report-samples/ ค่ะ'.PHP_EOL;
} finally {
    // ♻️ ข้อมูลจำลองกลิ้งกลับทั้งหมด — DB คงสถานะเดิมเป๊ะ
    DB::rollBack();
}
