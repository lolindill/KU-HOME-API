# 04: Auto-assign เลขห้องล่วงหน้า 2 วันก่อน check-in (REQ-023)

- **label:** `wayfinder:task`
- **type:** AFK
- **status:** open
- **blocked-by:** (ไม่มี — frontier)
- **assignee:** (ว่าง)
- **summary:** ต่อ DailyRoomMaintenance ให้ assign เลขห้องอัตโนมัติล่วงหน้า 2 วันก่อนวันเข้าพัก
- **phase:** 1

## Question

SRS REQ-023: "ระบบเลือกหมายเลขห้องล่วงหน้า **2 วันก่อนวันเข้าพัก** หรือปัจจุบัน และแอดมินสามารถเปลี่ยน/ย้ายห้องได้" — ปัจจุบัน `DailyRoomMaintenance` auto-assign ผ่าน RoomAllocator **เฉพาะวันเข้าพัก** (`whereDate('check_in', $today)`, :42-44) ส่วนพรุ่งนี้ทำแค่ housekeeping `prep_checkin` (:101-152) — แก้/ย้ายมือได้อยู่แล้ว (assign-rooms + swap ตอน check-in)

**Decision ที่ล็อกแล้ว (owner 2026-10-14):** ต่อ schedule ให้ assign เลขห้อง T-2 วันก่อน check-in — ไม่แตะทางมือ/ย้ายห้อง

**จุดแตะ:**

- `app/Console/Commands/DailyRoomMaintenance.php` — เพิ่มการ assign ห้องสำหรับ arrivals ของวันพรุ่งนี้+1 (T-2) ผ่าน RoomAllocator เดิม — **อย่า bypass allocator** (double-book risk ตาม AGENTS.md)
- ลำดับความสำคัญ: arrivals วันนี้ก่อนเสมอ → แล้ว T-2 — กรณีห้องไม่พอ T-2 ต้อง fail-safe (ทิ้งไว้ไม่ assign + log ให้ admin จัดมือ ไม่ crash ทั้ง run)
- ความสัมพันธ์กับ `prep_checkin` ของพรุ่งนี้ — ตรวจว่าห้องที่ assign แล้วยังโดน sweep เตรียมห้องถูกต้อง
- Tests: booking เข้าพักอีก 2 วัน → ถูก assign เลขห้องอัตโนมัติ · วันนี้ยังทำงานเหมือนเดิม (regression) · admin ย้ายทีหลังได้
- `php artisan test` เขียว + อัปเดต `docs/api_guide.md`

## Resolution

(ยังว่าง)
