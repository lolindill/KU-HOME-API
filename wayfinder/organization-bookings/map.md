# 🗺️ Wayfinder Map — Organization Bookings (admin จองแทน user / จองให้องค์กร)

- **label:** `wayfinder:map`
- **status:** open
- **tracker:** local-markdown — tickets อยู่ใน `tickets/` ของ directory นี้ (blocking ระบุใน field `blocked-by` ของแต่ละ ticket)
- **charted:** 2026-09-24

## Destination

Admin สร้าง booking ได้ 2 โหมดบน `POST /bookings` เดิม: **(1) จองแทน user ที่มี account** — booking ผูกกับ user ปลายทางที่ระบุ ไม่ใช่ admin ผู้สร้าง และ **(2) จองให้องค์กร** — booking ผูกกับตาราง `organizations` ใหม่ (`erp` + `name`) แทนการผูก user พร้อม snapshot `customer_name` / `customer_phone` / `customer_email` — โดยตาราง `organizations` เป็น **stopgap ที่ออกแบบให้เปลี่ยนไปใช้ organization-data API ได้ทีหลัง** ถ้ารุ่นพี่หาทางดึงข้อมูลองค์กรได้ · จบเมื่อ decision ล็อกครบ + implement จบ + suite เขียว

## Notes

- Domain: booking ของ KU HOME API — อ่าน `AGENTS.md` ก่อนทุก session (state machines ห้าม bypass, room assignment ผ่าน `RoomAllocator` เท่านั้น, money = integer baht, `PgBoolean` บน boolean column, UUID PKs, error shape ไทย+emoji)
- **Facts จากโค้ด ณ วัน chart (2026-09-24)** — ปูพื้นให้ทุก ticket ไม่ต้องขุดซ้ำ:
  - `bookings.user_id` เป็น **nullable FK + nullOnDelete อยู่แล้ว** (migration `2026_03_24_064203`) — ไม่ต้อง migrate เพื่อ nullable
  - `bookings.source` มีค่า `online | admin | line` อยู่แล้ว (validate ใน `StoreBookingRequest`) — มี marker รอใช้
  - bookings **ไม่มี** column `created_by` — ตอนนี้ไม่มีที่เก็บ "ใครสร้างบิลนี้"
  - `BookingController::createBooking` ล็อก `user_id = คนล็อกอิน (sanctum)` เสมอ + เช็ค **1 draft ต่อ user** + ตั้ง `payment_deadline` 24 ชม.
  - ⚠️ **ราคา daily_ku ดูจาก role ของ "คนล็อกอิน"** (`GlobalRate::getEffectiveDailyRate`) — admin จองแทนจะโดนเรทตาม role admin ไม่ใช่ user ปลายทาง = จุดเสี่ยงหลักของโหมดจองแทน
  - ownership ทุกจุดเป็น "เจ้าของ booking หรือ admin" — booking ไร้ user (`user_id = null`) เหลือแต่ admin
- Related maps: `booking-create-rules` (✅ COMPLETED — 4 write paths ของ createBooking), `excel-reports` (open), `reserved-room-pool` (open), `booking-payment-types` (open 2026-09-24 — payment_type เต็มจำนวน/มัดจำ/ค้างชำระ — **ticket 04 ของแมปนี้รอ design แมปนั้นปิดก่อน**)
- **Standing decision ระดับ effort (จาก owner, 2026-09-24):** ตาราง `organizations` เป็น **stopgap** — ทุก design ที่เสนอต้องตอบได้ว่า "ถ้าเทตารางนี้ทิ้งไปใช้ organization-data API แทน booking เดิมยังอ่านความหมายถูกไหม"
- ก่อนเขียนโค้ดจริง: จด design decision ลง `cline.md` ตาม protocol ใน AGENTS.md
- ทำงาน ticket ละ session — เริ่มจาก frontier (ticket open, blocked-by ปลดครบ, ยังไม่มี assignee)
- 🎯 **Frontier ปัจจุบัน:** ticket 01 (contract ของ organizations) + ticket 02 (admin จองแทน user) — grilling คู่กับ owner ได้ทันที

## Decisions so far

- *(ยังว่าง — แมปเพิ่ง charted 2026-09-24)*

## Not yet specified

- **ดู/รายงาน booking ตามองค์กร** — filter ใน admin booking index, การซ้อนทับกับแมป `excel-reports` — รอ schema ล็อกก่อน (ticket 01/03) จึง spec ไม่ได้ตอนนี้
- **ข้อมูลชุดแรกของ `organizations` มาจากไหน** (seed มือ / import / sync จาก ERP) — รอ ticket 01 ตัดสินเรื่อง CRUD ก่อน
- **รูปร่างจริงของ organization-data API ของรุ่นพี่** — ยังไม่เกิด จึงผิดที่จะ ticket; map นี้เตรียมแค่ replaceability contract (ticket 01)
- **การจองแทนคนนอก / `source='line'`** — มี flow ไลน์อยู่ใน validation แต่ยังไม่มีใครถามว่าโหมดจองแทนของ admin ครอบ flow นั้นไหม — รอหน้าที่ใช้จริง

## Out of scope

- **Integrate organization-data API ของรุ่นพี่จริง** — รอปลายทางจากฝ่ายอื่น; effort นี้แค่ทำให้ "เปลี่ยนได้" (owner, 2026-09-24)
- แก้ state machine ของ Booking/BookingRoom หรือแตะ `RoomAllocator` — org booking ใช้ flow เดิมทั้งหมด
- งานฝั่ง React frontend (`ku-home`) — ฝั่ง API เท่านั้น
- งาน roadmap อื่น: static dashboard / report templates / digital signature
