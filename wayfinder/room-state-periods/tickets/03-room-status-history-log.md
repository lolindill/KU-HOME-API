# 03: ประวัติสถานะห้องย้อนหลัง 1 ปี (REQ-039 — SRS v2)

- **label:** `wayfinder:task`
- **type:** AFK
- **status:** open
- **blocked-by:** ["01-grill-period-model-vs-flag", "02-grill-overlap-expiry-availability-rules"]
- **assignee:** (ว่าง)
- **born:** 2026-09-24 — graduate จาก gap ตรวจ SRS v2 (srs_room_booking_v2.pdf) ตามคำสั่ง owner "8 add in still going map"

## Question / What to build

REQ-039: "ระบบสามารถเก็บสถานะห้องพัก โดยมีระยะเวลาเก็บ 1 ปีย้อนหลัง"

ปัจจุบัน `Room::transitionStatusTo()` **ไม่เขียน audit log** — `status_change_logs` ครอบเฉพาะ Booking/BookingRoom (polymorphic entity_type) และ `rooms.status_updated_at/by` เก็บแค่ภาพล่าสุด ไม่ใช่ประวัติ

- เพิ่มการบันทึกการเปลี่ยนสถานะห้องทุกครั้ง (entity_type `room` บน `status_change_logs` หรือตารางเอง — ตัดสินตอนทำ)
- retention 1 ปีย้อนหลัง — เก็บมากกว่าได้ไหมหรือตัดจริงที่ 1 ปี (sweep ตอนไหน — จับคู่ `app:cleanup-images` pattern)
- **ต้องรอ grill 01/02 ก่อน:** ถ้าโมเดล period (ห้องสำรอง/ซ่อมแซมเป็นช่วงวันที่) ผ่าน สถานะบางอย่างอาจ derived จาก period แทนการ log ตรง — รูปร่างของ "ประวัติ" เปลี่ยนตามผล grill
- API อ่านย้อนหลัง (admin) — endpoint ใหม่หรือต่อ `GET /rooms/{id}` ก็ได้ · เทียบ precedent `GET /bookings/{id}/status-logs`
