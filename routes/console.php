<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule; // เพิ่มการ Import Facade ตรงนี้ค่ะ

// แก้ไขการปิดท้ายคำสั่งด้วย ;
Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// เรียกใช้ Schedule ผ่าน Facade ให้ถูกต้อง
Schedule::command('app:daily-room-maintenance')->daily();
// ⏱️ (2026-09-24, REQ-008): draft หมดอายุ 15 นาที — sweep ทุก 5 นาทีเพื่อเก็บกวาดใกล้เคียงกัน
//    (slot ถูกปลดล็อกทันทีตอน query ผ่าน scope holdingSlot — command นี้เป็นการลบ row จริง)
Schedule::command('app:cleanup-expired-drafts')->everyFiveMinutes();
Schedule::command('app:cleanup-images')->dailyAt('02:30');
// 📝 (2026-09-24, REQ-039): ประวัติสถานะห้องเก็บ 1 ปีย้อนหลัง — ตัดเกิน retention รายวัน
//    (ลดเฉพาะ entity_type='room' — log ของ booking/booking_room ไม่ถูกแตะ)
Schedule::command('app:cleanup-status-logs')->dailyAt('02:45');
