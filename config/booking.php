<?php

/**
 * 🏨 Booking Configuration — KU HOME API
 *
 *    Config สำหรับกฎการสร้างและแก้ไขการจอง (advance notice, room cap)
 *    ปรับค่าได้ผ่าน .env โดยไม่ต้องแก้โค้ด หรือใช้ config:cache ใน production
 *
 *    อ้างอิง: wayfinder/booking-create-rules/spec.md
 */
return [

    // =========================================================
    // 📅 Advance Notice (จองล่วงหน้า)
    //    จำนวนวันล่วงหน้าขั้นต่ำตามปฏิทิน (Asia/Bangkok) สำหรับ non-admin
    //    0 = วันนี้, 1 = พรุ่งนี้, 2 = วันมะรืน (+2 calendar days)
    // =========================================================
    'min_advance_days' => (int) env('BOOKING_MIN_ADVANCE_DAYS', 2),

    // =========================================================
    // 🚪 Room Cap (เพดานจำนวนห้องต่อ 1 booking)
    //    จำนวนห้องสูงสุดที่ non-admin จองได้ต่อ 1 booking (create + add-rooms)
    //    (สำหรับ ticket 02 — ใส่เตรียมไว้ตาม spec)
    // =========================================================
    'max_rooms_per_booking' => (int) env('BOOKING_MAX_ROOMS_PER_BOOKING', 4),

];
