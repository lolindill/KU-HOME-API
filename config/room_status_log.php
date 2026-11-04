<?php

/**
 * 📝 Room Status Log Configuration — KU HOME API
 *
 *    Config สำหรับประวัติสถานะห้อง (audit log entity_type='room' บน status_change_logs)
 *    ปรับค่าได้ผ่าน .env โดยไม่ต้องแก้โค้ด หรือใช้ config:cache ใน production
 *
 *    อ้างอิง: REQ-039 (SRS v2) — wayfinder/room-state-periods ticket 03
 */
return [

    // =========================================================
    // ⏳ Retention (เก็บประวัติสถานะห้องย้อนหลัง N วัน — REQ-039)
    //    ตัดจริงที่ 1 ปี (default 365) โดย app:cleanup-status-logs (schedule รายวัน 02:45)
    //    ลดเฉพาะ entity_type='room' — log ของ booking/booking_room ไม่ถูกแตะ
    // =========================================================
    'retention_days' => (int) env('ROOM_STATUS_LOG_RETENTION_DAYS', 365),
];
