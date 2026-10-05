<?php

/**
 * 📊 Reporting Configuration — KU HOME API (excel-reports, 2026-10-05)
 *
 *    Config สำหรับชั้นรายงาน Excel (wayfinder/excel-reports spec §2.7)
 *    ปรับค่าได้ผ่าน .env โดยไม่ต้องแก้โค้ด
 */
return [

    // =========================================================
    // 📅 เพดานช่วงวันที่ export ต่อครั้ง (spec §4 — ticket 04)
    //    รายงานทุกใบ validate ช่วงวันที่ห่างกันได้ไม่เกินค่านี้ (วัน)
    //    366 = รวมปีอธิกสุรทิน (ช่วง ≤ 1 ปีพอดี)
    // =========================================================
    'max_export_range_days' => (int) env('REPORTING_MAX_EXPORT_RANGE_DAYS', 366),

    // =========================================================
    // 🛏️ Extra-bed fleet (spec §2.7 — ticket 10)
    //    จำนวนเตียงเสริมเคลื่อนที่ (fleet) ทั้งระบบ — summary total_inventory
    //    ของ extra-bed-report อ่านจากที่นี่
    //    ⚠️ ไม่ derive จาก rooms.builtin_extra_beds — เตียงในห้อง ≠ fleet เคลื่อนที่
    // =========================================================
    'extra_bed_fleet_size' => (int) env('REPORTING_EXTRA_BED_FLEET_SIZE', 35),

    // =========================================================
    // 🧹 Checklist แม่บ้าน (spec §2.4 — ticket 10)
    //    หัวข้อ label ของ cleaning_check_1/2/3 บน housekeeping_tasks
    //    · รายงาน v2 ใช้เป็นหัวคอลัมน์ (ชีตต้นทาง G/H/I ไม่มีหัวข้อ —
    //      owner ยืนยันให้ระบบตั้งเอง = ข้อยกเว้น sheet-wins §6.2)
    //    · แก้ได้ไม่ต้อง migrate (index ผูกกับ column cleaning_check_N)
    // =========================================================
    'cleaning_checks' => [
        'ทำความสะอาดห้องน้ำ',
        'เครื่องนอน/ผ้า',
        'พื้น + ขยะ',
    ],

];
