# 09: Implement — ปัดเศษขึ้นหลักสิบ + normalize คืนเต็ม (จาก ticket 07)

- **label:** `wayfinder:task`
- **type:** AFK
- **status:** open
- **blocked-by:** [06-implement-payment-types](./06-implement-payment-types.md)
- **assignee:** (ว่าง)
- **born:** 2026-09-25 — graduate จาก resolution ของ ticket 07 (ปัดเศษขึ้นหลักสิบ REQ-015/016)

## Question

ลงมือ implement ตาม decision ที่ปิดแล้วใน [ticket 07](./07-round-up-to-tens.md) — ห้ามเปลี่ยน decision:

- **Helper ปัดขึ้นหลักสิบ** `(int) ceil($amount / 10) * 10` ใช้ที่เดียวใน `DiscountService::reprice()` — ปัดที่ `booking_rooms.amount` **หลัง** (เรท×คืน) − ส่วนลด + addon แล้ว `total_amount = Σ ยอดที่ปัดแล้ว` (invariant Σ คงอยู่ — ห้ามปัดที่ total แยก)
- **Normalize คืนเต็มทุกจุดคำนวณ** — `startOfDay()` ทั้ง check_in/check_out ก่อน `diffInDays()`: `DiscountService::reprice()` (:166) + `BookingController` :341, :676, :938, :1248 (Carbon 3 `diffInDays` คืน float เมื่อ input เป็น datetime)
- **มัดจำ default** (`Booking::expected_amount` — `ceil(total×percent/100)`) ปัดขึ้นหลักสิบต่อ · `deposit_amount` ที่ admin ตั้งเองไม่ force ปัด · ชั้น A `booking_confirmations.amount` ไม่บังคับปัด (soft admin ตัดสินตาม ticket 04)
- **ส่วนลด percent `intdiv` คงเดิม** — ปัดท้ายครอบอยู่แล้ว
- ทดสอบ: สร้าง global rate ราคาไม่ใส่เลขสิบ (เช่น 1,255) → ยอดห้อง/total/expected ปัดสิบตัวงั้น · ส่วนลด percent ทำให้เศษ → ปัดท้ายจบ · datetime input มีเวลา → นับคืนเต็ม · regression: เรทร้อยตัวงั้นยอดเดิมไม่เปลี่ยน
- `php artisan test` เขียวทั้ง suite · อัปเดต `docs/api_guide.md` (กติกาปัดเศษ) + จด decision ลง `cline.md`
