# 🗺️ Wayfinder Map — Organization Bookings (admin จองแทน user / จองให้องค์กร)

- **label:** `wayfinder:map`
- **status:** closed
- **tracker:** local-markdown — tickets อยู่ใน `tickets/` ของ directory นี้ (blocking ระบุใน field `blocked-by` ของแต่ละ ticket)
- **charted:** 2026-09-24

## Destination ✅ COMPLETED (2026-09-25)

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
- 🎯 **Frontier ปัจจุบัน (อัปเดต 2026-09-25 — แมปปิดสมบูรณ์):** ไม่มี ticket เปิดเหลือแล้ว — commit implement ของ ticket 07 ลงแล้ว (`0aa7c6c` — suite 604 เขียว) · ~~ticket 08~~ (✅ grilling + implement ปิดแล้ว 2026-09-25 — filter `organization_id` + term ค้น customer_name · admin เห็น object organization) · ~~ticket 07~~ (✅ grilling + implement ปิดแล้ว 2026-09-25 — attach = ขยาย `PUT /bookings/{id}` guard แยก field-group · reprice เฉพาะ draft) · ~~ticket 06~~ (✅ implement 03+04 ปิดแล้ว 2026-09-25 — suite 588 เขียว) · ~~ticket 04~~ (✅ ปิดแล้ว 2026-09-25) · ~~ticket 05~~ (✅ implement 01+02 ปิดแล้ว 2026-09-25 — suite 576 เขียว)

## Decisions so far

- [ตาราง organizations — contract ของ erp, CRUD และกติกา replaceability](tickets/01-organization-table-erp-and-replaceability.md): `erp` = string unique nullable (logical FK ภายนอก ไม่มี DB constraint) · booking อ้าง org ด้วย nullable FK `organization_id` (restrict) + snapshot name/phone/email บน booking เป็นชั้น replaceability แรก (erp = key map ไป API ตอนเปลี่ยน) · CRUD เต็มใต้ `role:admin` ไม่มี DELETE · เลิกใช้ = `is_active` + toggle (PgBoolean) ปิดแล้ว booking ใหม่โดน 422, เก่าไม่กระทบ
- [Admin จองแทน user — identity ของ user ปลายทาง และ audit ฝั่งผู้สร้าง](tickets/02-admin-booking-for-user-target-identity.md): 2 โหมด + เฮดเปล่า — โหมด A link user account ด้วย **UUID** (`user` field) · โหมด B เฮดเปล่า/องค์กรไม่ link account ใช้ string `customer_name` · สร้างเฮดเปล่าได้เต็มรูป (attach ทีหลัง = ticket 07 ใหม่) · **ไม่เพิ่ม `created_by`** · กฎผลพวงแยกโหมด (dedup/cap/ราคา/ownership) · เฉพาะ `role:admin` + `source='admin'` marker
- [Booking ให้องค์กรบน POST /bookings เดิม — field set และ consumer ของ user_id = null](tickets/03-org-booking-shape-on-post-bookings.md): `organize` = **erp code string** → lookup เป็น FK `organization_id` (ไม่เจอ/inactive → 422) · `user` กับ `organize` **mutually exclusive** (422) · ส่ง `organize` ต้องมี `customer_name` (phone/email nullable) · snapshot = **3 columns ใหม่บน bookings** · กฎผลพวง user_id=null สืบทอดโหมด B ของ ticket 02 ครบ (ไม่ dedup · ราคา daily · ownership admin) · ชื่อ default ใช้ `customer_name` ทั้ง `getPrimaryGuestName` fallback และ default guests ของห้อง
- [Implement: ตาราง organizations + admin จองแทน user](tickets/05-implement-organizations-and-booking-for-user.md): implement 01+02 จบ — ตาราง organizations (`erp` unique nullable + `is_active` PgBoolean) + CRUD 5 endpoint ใต้ `role:admin` ไม่มี DELETE · POST /bookings 2 โหมด (ส่ง `user` UUID → target / ไม่ส่ง → `user_id` null เฮดเปล่า) + แก้ chokepoint ราคา daily_ku เป็น `$booking->user` · suite **576 เขียว** · org fields บน bookings ยังไม่รวม — implement ต่อใน ticket 06
- [ชีวิตหลังจองของ booking ไร้ user — payment, deadline และ front desk](tickets/04-userless-booking-payment-and-front-desk.md): org booking = `deferred` เสมอ (สลิป block · อนุมัติ `draft → confirmed` · เก็บเงิน `recordPayment` — ตัดสินที่แมป `booking-payment-types` แล้ว) · ส่วน grilling ของ ticket นี้: admin ส่งสลิปแทนบิลไร้ user (non-deferred) ได้ผ่าน guard เดิม · เฮดเปล่าโดน cleanup 15 นาทีตามเดิม ขยายผ่าน `payment_deadline` บน `PUT /bookings/{id}` · front desk ใช้ term search เดิม — org filter ไปตัดสิน ticket 08 · `customer_phone`/`email` เก็บติดต่อล้วน ไม่ผูก flow
- [Implement: org booking fields + consumer ที่รองรับ user_id = null](tickets/06-implement-org-booking-fields.md): implement 03+04 จบ — migration `organization_id` (FK restrict) + snapshot `customer_name`/`customer_phone`/`customer_email` บน bookings · `organize` = erp lookup (ไม่เจอ/inactive 422) · user×organize mutually exclusive + บังคับ `customer_name` + `source='admin'` · flow หลังจองไม่ต้องเขียนใหม่ (deferred design ของแมป payment-types) · suite **588 เขียว**
- [Empty-head booking — การ attach user/organization ทีหลัง](tickets/07-empty-head-attach-later.md): attach/เปลี่ยน/ถอด = **ขยาย `PUT /bookings/{id}` เดิม** (guard แยก field-group: payment = draft-only เดิม, identity = จนก่อน `complete`/`no_show`) · reprice เฉพาะตอน draft ผ่าน `DiscountService::reprice()` (ku_member → daily_ku อัตโนมัติ) · ส่ง identity ใหม่ = แทนที่ทั้งชุด, ส่ง null = ถอดเฮดเปล่า · validation ชุดเดียวกับ POST · ไม่ re-run draft-dedup ตอน attach
- [ดู/รายงาน booking ตามองค์กร](tickets/08-admin-view-report-by-org.md): filter = **ขยาย `GET /bookings` เดิม** — param `organization_id` (UUID ตรง, admin เท่านั้น, non-UUID 422) + `term` ค้น snapshot `customer_name` เพิ่ม · ขอบเขต "รายงาน" = filter + fields เท่านั้น (aggregation เป็นของแมป `excel-reports`) · admin เห็น object `organization` eager-load ทั้ง index และ showById · suite **602 เขียว** — implement จบใน session เดียวกับ grilling

## Not yet specified

- **รูปร่างจริงของ organization-data API ของรุ่นพี่** — ยังไม่เกิด จึงผิดที่จะ ticket; map นี้เตรียมแค่ replaceability contract (ticket 01 ✅ ปิดแล้ว)
- **การจองแทนคนนอก / `source='line'`** — มี flow ไลน์อยู่ใน validation แต่ยังไม่มีใครถามว่าโหมดจองแทนของ admin ครอบ flow นั้นไหม — รอหน้าที่ใช้จริง

## Out of scope

- **Integrate organization-data API ของรุ่นพี่จริง** — รอปลายทางจากฝ่ายอื่น; effort นี้แค่ทำให้ "เปลี่ยนได้" (owner, 2026-09-24)
- แก้ state machine ของ Booking/BookingRoom หรือแตะ `RoomAllocator` — org booking ใช้ flow เดิมทั้งหมด
- งานฝั่ง React frontend (`ku-home`) — ฝั่ง API เท่านั้น
- งาน roadmap อื่น: static dashboard / report templates / digital signature
