# 09: Implement — ปัดเศษขึ้นหลักสิบ + normalize คืนเต็ม (จาก ticket 07)

- **label:** `wayfinder:task`
- **type:** AFK
- **status:** closed
- **closed:** 2026-09-25
- **blocked-by:** [06-implement-payment-types](./06-implement-payment-types.md)
- **assignee:** kevii (session 2026-09-25 — zcode)
- **born:** 2026-09-25 — graduate จาก resolution ของ ticket 07 (ปัดเศษขึ้นหลักสิบ REQ-015/016)

## Question

ลงมือ implement ตาม decision ที่ปิดแล้วใน [ticket 07](./07-round-up-to-tens.md) — ห้ามเปลี่ยน decision:

- **Helper ปัดขึ้นหลักสิบ** `(int) ceil($amount / 10) * 10` ใช้ที่เดียวใน `DiscountService::reprice()` — ปัดที่ `booking_rooms.amount` **หลัง** (เรท×คืน) − ส่วนลด + addon แล้ว `total_amount = Σ ยอดที่ปัดแล้ว` (invariant Σ คงอยู่ — ห้ามปัดที่ total แยก)
- **Normalize คืนเต็มทุกจุดคำนวณ** — `startOfDay()` ทั้ง check_in/check_out ก่อน `diffInDays()`: `DiscountService::reprice()` (:166) + `BookingController` :341, :676, :938, :1248 (Carbon 3 `diffInDays` คืน float เมื่อ input เป็น datetime)
- **มัดจำ default** (`Booking::expected_amount` — `ceil(total×percent/100)`) ปัดขึ้นหลักสิบต่อ · `deposit_amount` ที่ admin ตั้งเองไม่ force ปัด · ชั้น A `booking_confirmations.amount` ไม่บังคับปัด (soft admin ตัดสินตาม ticket 04)
- **ส่วนลด percent `intdiv` คงเดิม** — ปัดท้ายครอบอยู่แล้ว
- ทดสอบ: สร้าง global rate ราคาไม่ใส่เลขสิบ (เช่น 1,255) → ยอดห้อง/total/expected ปัดสิบตัวงั้น · ส่วนลด percent ทำให้เศษ → ปัดท้ายจบ · datetime input มีเวลา → นับคืนเต็ม · regression: เรทร้อยตัวงั้นยอดเดิมไม่เปลี่ยน
- `php artisan test` เขียวทั้ง suite · อัปเดต `docs/api_guide.md` (กติกาปัดเศษ) + จด decision ลง `cline.md`

## Resolution

**(2026-09-25 — implement ตาม decision ticket 07 ทุกข้อ ไม่มีการเปลี่ยน decision)**

- **Helper:** `App\Support\RoundToTen::round()` = `(int) ceil($baht / 10) * 10` — ใช้ 2 จุด: (1) `DiscountService::reprice()` ปัดที่ `booking_rooms.amount` **หลัง** `(เรท×คืน) − ส่วนลด + addon` แล้ว `total_amount = Σ ยอดที่ปัดแล้ว` (invariant Σ คงอยู่ — `room_amount`/`discount_amount` เก็บค่าดิบ) (2) `Booking::getDepositAmountAttribute()` มัดจำ default ปัดสิบต่อ
- **ไม่ force ปัด:** `deposit_amount` ที่ admin ตั้งเอง (admin รับผิดชอบตัวเลขเอง) · ชั้น A `booking_confirmations.amount` (soft admin ตัดสินตาม ticket 04) · ส่วนลด `percent` `intdiv` คงเดิม
- **Normalize คืนเต็ม:** `startOfDay()` ทั้ง check_in/check_out ก่อน `diffInDays()` ครบ 5 จุด — `DiscountService::reprice()` + `BookingController` createBooking / addRooms / updateRoom / updateRooms batch (Carbon 3 float guard)
- **Tests:** `tests/Feature/BookingRoundingTest.php` 4 ใหม่ — เรท 1,255×3 คืน = 3,765 → 3,770 / ส่วนลด percent 7% เศษ 1,168 → 1,170 / datetime 14:00→11:00 นับ 2 คืนเต็ม (ไม่หลุด 1.875) / deposit default 33% 416 → 420 + admin-set 416 ไม่ force · **suite 544 เขียว** (เดิม 540 + ใหม่ 4) · Pint ผ่าน
- **Docs:** `docs/api_guide.md` หัวข้อ "💵 กติกาปัดเศษขึ้นหลักสิบ (REQ-015/016)" ใต้ Payment types + แก้คำ "ปัดขึ้น" เป็น "ปัดขึ้นหลักสิบ" ที่ POST /bookings · จดครบใน `cline.md` ("💵 ปัดเศษขึ้นหลักสิบ + normalize คืนเต็ม 2026-09-25")
