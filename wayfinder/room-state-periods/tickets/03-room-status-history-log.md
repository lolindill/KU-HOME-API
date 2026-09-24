# 03: ประวัติสถานะห้องย้อนหลัง 1 ปี (REQ-039 — SRS v2)

- **label:** `wayfinder:task`
- **type:** AFK
- **status:** closed
- **blocked-by:** ["01-grill-period-model-vs-flag", "02-grill-overlap-expiry-availability-rules"]
- **assignee:** kevii (session 2026-09-24 — ทำเลย เพราะ 01+02 ปิดครบแล้ว)
- **born:** 2026-09-24 — graduate จาก gap ตรวจ SRS v2 (srs_room_booking_v2.pdf) ตามคำสั่ง owner "8 add in still going map"

## Question / What to build

REQ-039: "ระบบสามารถเก็บสถานะห้องพัก โดยมีระยะเวลาเก็บ 1 ปีย้อนหลัง"

ปัจจุบัน `Room::transitionStatusTo()` **ไม่เขียน audit log** — `status_change_logs` ครอบเฉพาะ Booking/BookingRoom (polymorphic entity_type) และ `rooms.status_updated_at/by` เก็บแค่ภาพล่าสุด ไม่ใช่ประวัติ

- เพิ่มการบันทึกการเปลี่ยนสถานะห้องทุกครั้ง (entity_type `room` บน `status_change_logs` หรือตารางเอง — ตัดสินตอนทำ)
- retention 1 ปีย้อนหลัง — เก็บมากกว่าได้ไหมหรือตัดจริงที่ 1 ปี (sweep ตอนไหน — จับคู่ `app:cleanup-images` pattern)
- **ต้องรอ grill 01/02 ก่อน:** ถ้าโมเดล period (ห้องสำรอง/ซ่อมแซมเป็นช่วงวันที่) ผ่าน สถานะบางอย่างอาจ derived จาก period แทนการ log ตรง — รูปร่างของ "ประวัติ" เปลี่ยนตามผล grill
- **📌 Amend 2026-09-24 (ผล ticket 01):** โมเดล period **ผ่านแล้ว** — reserved/maintenance กลายเป็น period-derived (ไม่มี status transition ให้ log 2 สถานะนี้อีก) · scope ของ audit log จึงเหลือสถานะ lifecycle อื่น (available/dirty/occupied/prep_checkin/checkout_makeup) · ตาราง `room_state_periods` เองก็คือประวัติชั้นหนึ่งของ 2 เหตุนี้ (มี created_by/วันที่) · blocked-by 01 ปิดแล้ว — รอเหลือแค่ 02
- API อ่านย้อนหลัง (admin) — endpoint ใหม่หรือต่อ `GET /rooms/{id}` ก็ได้ · เทียบ precedent `GET /bookings/{id}/status-logs`

## Resolution

**CLOSED 2026-09-24 — build เสร็จ (AFK) · suite 472 เขียว + 11 test ใหม่ (`RoomStatusLogTest`):**

1. **เก็บบน `status_change_logs` polymorphic (entity_type `room`)** — ไม่สร้างตารางใหม่ (model docblock เตรียมค่านี้ไว้แล้ว) · เขียนใน `Room::transitionStatusTo()` chokepoint เดียว — transition ผิด/no-op ไม่เขียน · `causer_id` = ผู้ที่สั่ง (param หรือ Auth::id(), null สำหรับ cron) · `role` = role ของ causer (lookup, fallback `system`)
2. **Retention ตัดจริงที่ 1 ปี** — command `app:cleanup-status-logs` (schedule รายวัน 02:45 คู่ `app:cleanup-images`, มี `--dry-run`) ลดเฉพาะ `entity_type='room'` เก่ากว่า `config/room_status_log.php` `retention_days` (env `ROOM_STATUS_LOG_RETENTION_DAYS`, default 365) — **log ของ booking/booking_room ห้ามแตะ** (audit การเงิน)
3. **Endpoint ใหม่ `GET /rooms/{id}/status-logs` (admin, role:admin)** — รูปร่างเดียวกับ precedent ของ booking · 404 ถ้าไม่พบห้อง
4. **ประสานกับโมเดล period (amend ข้อ ข้างบน):** ตามที่คาด — เมื่อ spec (ticket 06) ถอดสถานะ `maintenance` ออก transition หายไปเอง log นี้ไม่ต้องแก้รูปร่าง · ประวัติ reserved/maintenance อยู่ที่ตาราง `room_state_periods` (created_by/วันที่) อยู่แล้ว

**Files:** `app/Models/Room.php` · `app/Console/Commands/CleanupStatusLogs.php` (ใหม่) · `config/room_status_log.php` (ใหม่) · `routes/console.php` · `routes/api.php` · `app/Http/Controllers/Api/V1/RoomController.php` · `tests/Feature/RoomStatusLogTest.php` (ใหม่ · 11 test) · `docs/api_guide.md`
