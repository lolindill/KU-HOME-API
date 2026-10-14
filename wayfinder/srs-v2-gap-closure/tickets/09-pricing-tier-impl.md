# 09: Implement — auto-apply เรท group/month ใน reprice (จาก ticket 08)

- **label:** `wayfinder:task`
- **type:** AFK
- **status:** open
- **blocked-by:** [08-pricing-tier-grilling](./08-pricing-tier-grilling.md)
- **assignee:** (ว่าง)
- **summary:** Implement การแทน daily ด้วย group/month ใน reprice ตามกติกาที่ ticket 08 ล็อก + tests
- **phase:** 2

## Question

ลงมือ implement ตาม decision ที่ปิดแล้วใน [ticket 08](./08-pricing-tier-grilling.md) — ห้ามเปลี่ยน decision:

- เลือก rate ที่ถูกต้องตามกติกาที่ล็อก (stay_type/จำนวนห้อง → `month`/`group` min_5/min_10, precedence กับ `daily_ku` ตาม Resolution) — จุดเลือกควรรวมอยู่ใกล้ `GlobalRate::getEffectiveDailyRate()` เพื่อ chokepoint เดียว
- แทนค่าใน `DiscountService::reprice()` — ระวัง: ฐานส่วนลด, จุดปัด `RoundToTen` คงเดิม, invariant `total_amount = Σ booking_rooms.amount` (ตาม precedent ticket 07 ของ `booking-payment-types`)
- **ราคาที่แสดงต้องตรงกันทั้งระบบ:** `RoomType::rates` wire object, calendar endpoints, availability — ราคา "ต่อคืนที่จะโดนเก็บ" เมื่อเงื่อนไข long-stay/group เป็นจริง ต้องคำนวณจากแหล่งเดียวกัน (อย่าให้ frontend เห็น daily แต่โดนเก็บ month)
- Migration ไม่จำเป็น (เรทมีอยู่แล้วใน `global_rates`) — แต่ seeder ตัวอย่าง group/month ต้องมีค่าทดสอบได้ (ตรวจ `GlobalRateSeeder`)
- Tests: ≥30 คืน → month rate · 5/10 ห้อง → group · ขอบ 29 คืน / 4 ห้อง ยัง daily · โค้ดส่วนลดบนฐานใหม่ · ปัดเศษหลักสิบคงทำงาน · regression ปกติเท่าเดิม
- `php artisan test` เขียวทั้ง suite · อัปเดต `docs/api_guide.md` (กติกาเลือกเรท) + จดลง `cline.md`

## Resolution

(ยังว่าง)
