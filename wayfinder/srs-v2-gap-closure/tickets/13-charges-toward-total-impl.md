# 13: Implement — charges ต่อห้อง/ต่อ booking บวกยอดจริง (จาก ticket 12)

- **label:** `wayfinder:task`
- **type:** AFK
- **status:** closed *(2026-10-15 — implement เสร็จ · suite 737 เขียว · REQ-042/044 ปิดใน deviation ledger)*
- **blocked-by:** [12-charges-toward-total-grilling](./12-charges-toward-total-grilling.md)
- **assignee:** zcode (session 2026-10-15)
- **summary:** Implement charges กรอกเองต่อห้อง/ต่อ booking บวกยอดตาม design ticket 12 + tests + reports
- **phase:** 4

## Question

ลงมือ implement ตาม design ที่ปิดแล้วใน [ticket 12](./12-charges-toward-total-grilling.md) — ห้ามเปลี่ยน decision:

- Schema ตาม Resolution (polymorphic หรือตารางใหม่) + FormRequest (item, ราคา, จำนวน — integer baht) + endpoints ให้ admin จัดการ (สร้าง/แก้/ลบ ก่อนมีการชำระตามเงื่อนไขที่ล็อก)
- กลไกยอดตาม Resolution ข้อ 1 — แตะเฉพาะ accessors/fields ที่ Resolution อนุญาต (`total_amount`/`outstanding_amount`/`is_paid`) · จุดชำระตาม Resolution ข้อ 4
- **รายงานต้องยังตรง:** ปรับ `AdditionalChargesReportData`, `DailyFinancialReportData`, `ManagerReportData` ให้สะท้อนยอดใหม่ได้ถูกหมวด (เงินเข้า/ค้างชำระ) — suite excel reports เขียวต่อ
- Tests: charge ต่อห้อง + ต่อ booking · ยอดรวม/ค้างชำระเปลี่ยนตาม design · ชำระตาม flow ที่ล็อก · สิทธิ์ · regression is_paid/deposit เดิมไม่เปลี่ยนเมื่อไม่มี charge
- `php artisan test` เขียวทั้ง suite · อัปเดต `docs/api_guide.md` + deviation ledger (REQ-042/044 ปิด) + จด `cline.md`

## Resolution

**✅ Implement เสร็จตาม design ticket 12 ทุกข้อ (2026-10-15) — ไม่มีการเปลี่ยน decision · suite เต็ม 737 passed (3,044 assertions) · Pint เขียว**

- **Schema:** migration `2026_10_15_090000_add_booking_room_id_to_additional_charges_table` — `booking_room_id` nullable UUID FK → `booking_rooms` (restrict + index) · `charge_type` เป็น `string(20)` เดิมจึงเพิ่มค่า `'addon'` ได้ไม่ต้องแก้ schema
- **ยอด (accessors ใน `Booking`):** append **`charges_total`** = Σ `additional_charges.price` (ออกทุก booking response อัตโนมัติ) · **`outstanding_amount` = max(0, total + charges − paid)** · `total_amount`/deposit/invariant Σ booking_rooms.amount **ไม่ถูกแตะ**
- **จุด recompute ที่ ticket 12 ฝากมา:** `Booking::syncIsPaid()` = จุดเดียว recompute `is_paid` ตามนิยาม paid ≥ total + charges — เรียกจาก `AdditionalChargeController` ทั้ง create/update/delete (update ย้ายข้าม booking → recompute ทั้งสองฝั่ง) · **ไม่แตะ state machine** (สถานะเดินด้วยเหตุการณ์เงินเท่านั้น)
- **จุดเปรียบเทียบ is_paid รวม charges ครบ 3 จุด:** `BookingConfirmationController::verify` (สลิปรวม charge → is_paid true) · `FrontDeskController::recordPayment` (ครบ = total + charges) · `BookingController` surcharge guard (downgrade guard + reset false — charges หนุนยอดได้)
- **Validation:** `booking_room_id` nullable + Rule::exists + closure ตรวจลูก-พ่อ (**422** ทั้ง store/update — update ไม่ส่ง booking_id มาเทียบกับ booking ปัจจุบันของ charge) · `charge_type` = damage|rental|**addon**
- **API:** CRUD เดิมคงสิทธิ์ admin+staff (+defense-in-depth) · **ใหม่ `GET /api/v1/bookings/{bookingId}/additional-charges`** (admin+staff — 404 ไม่พบ booking, 403 role อื่น) · response ทุก mutation + list แนบ **snapshot เงิน booking** (`total_amount`/`charges_total`/`paid_amount`/`outstanding_amount`/`booking_is_paid`/`booking_status`) · ไม่มี endpoint ชำระใหม่ — ใช้ `recordPayment` เดิม
- **สถานะ:** ไม่ guard — บันทึก charge ได้ทุกสถานะ (มติ 4a) · ไม่มีรูปหลักฐาน (มติข้อ 5 — interpretation จด ledger ข้อ 9 ใน `cline.md` แล้ว)
- **รายงาน:** `AdditionalChargesReportData` + template JSON เพิ่ม filter **บริการเสริม (addon)** · `DailyFinancialReportData` ไม่แก้ (เงินเก็บ charge ไหลเข้าเป็นแถว payments เงินสดเอง — มี test ยืนยัน) · `ManagerReportData` occupancy ล้วนไม่เกี่ยวเงิน — suite excel reports เขียวต่อ
- **Tests:** `tests/Feature/AdditionalChargeBookingTotalTest.php` 12 เคส — per-room/per-booking · 422 ลูก-พ่อ · recompute ตอนแก้/ลบ (is_paid คืน true เมื่อลบแล้วเงินพอ โดยสถานะคงเดิม) · deposit ไม่ขยับ · staff เก็บผ่าน recordPayment · verify สลิปรวม charge (ยอดไม่ครบ → is_paid false, outstanding = charge) · GET + สิทธิ์ + 404 · addon filter รายงาน · daily-financial · regression ไม่มี charge = ยอดเดิม
- **เอกสาร:** `docs/api_guide.md` (หัวข้อ Additional Charges ใหม่หมด — ตัดคำ "report-only/ไม่มี GET" · ตาราง report filter เพิ่มบริการเสริม) · `cline.md` (หัวข้อ "💸 Additional charges บวกยอดจริง" + Gap Register ข้อ 1 อัปเดต + ledger: interpretation ข้อ 9 + แถว REQ-042/044 ในตาราง Implementation) · `AGENTS.md` (bullet Critical Conventions)
- **REQ-042/044 ปิด** — REQ-010 (QR/HMAC) ยัง pending-external และ NFR 500 concurrent ยังใน fog ของ map (ดู Not yet specified)
