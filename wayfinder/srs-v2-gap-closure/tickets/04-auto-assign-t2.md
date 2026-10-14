# 04: Auto-assign เลขห้องล่วงหน้า 2 วันก่อน check-in (REQ-023)

- **label:** `wayfinder:task`
- **type:** AFK
- **status:** closed
- **blocked-by:** (ไม่มี — frontier)
- **assignee:** agent-hub:antigravity/gemini-3.8-flash (manager dispatch 2026-10-14)
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

- **สิ่งที่ทำ:**
  1. เพิ่มขั้นตอนการ Auto-assign สำหรับผู้เข้าพักล่วงหน้า 2 วัน (T-2, `check_in = today + 2 days`) ใน `app/Console/Commands/DailyRoomMaintenance.php` หลังจากการจัดสรรของวันนี้ (Today) เสมอ
  2. จัดสรรห้องพักผ่าน `RoomAllocator` (Hybrid+ v3 cluster algorithm) ร่วมกับการจัดลำดับความสำคัญของบุ๊กกิ้งด้วย `BookingPriority` ป้องกันการ bypass allocator และลดความเสี่ยง double-booking
  3. ออกแบบระบบ Fail-Safe สำหรับ T-2 โดยแยก Transaction ต่อบุ๊กกิ้ง หากห้องไม่พอจะไม่ throw crash แต่จะบันทึก Warning Log และคง `room_id` เป็น `null` เพื่อให้แอดมินจัดการด้วยตนเอง
  4. ยืนยันความสัมพันธ์กับขั้นตอน `prep_checkin`: ห้องที่ได้รับการ assign ล่วงหน้า T-2 จะถูกดึงไปเตรียมห้อง (status `prep_checkin`) และสร้างงาน `pre_checkin` อัตโนมัติเมื่อถึงวันก่อนเข้าพัก (Tomorrow) โดยไม่เกิดความซ้ำซ้อน
  5. เขียน Feature Test ครอบคลุมทุกกรณีใน `tests/Feature/DailyRoomMaintenanceAutoAssignTest.php` (5 tests)
  6. จัดทำเอกสาร handoff สำหรับอัปเดต `docs/api_guide.md` ใน `wayfinder/srs-v2-gap-closure/research/api-doc-handoff-04.md`

- **ไฟล์ที่แตะ:**
  - `app/Console/Commands/DailyRoomMaintenance.php`
  - `tests/Feature/DailyRoomMaintenanceAutoAssignTest.php`
  - `wayfinder/srs-v2-gap-closure/tickets/04-auto-assign-t2.md`
  - `wayfinder/srs-v2-gap-closure/research/api-doc-handoff-04.md`

- **ผล Test:**
  - `php artisan test`: ผ่านทั้งหมด 654 passed (2626 assertions) เขียว 100%
