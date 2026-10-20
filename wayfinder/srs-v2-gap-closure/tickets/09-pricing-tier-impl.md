# 09: Implement — auto-apply เรท group/month ใน reprice (จาก ticket 08)

- **label:** `wayfinder:task`
- **type:** AFK
- **status:** open
- **blocked-by:** [08-pricing-tier-grilling](./08-pricing-tier-grilling.md)
- **assignee:** (ว่าง)
- **summary:** Implement การแทน daily ด้วย group/month ใน reprice ตามกติกาที่ ticket 08 ล็อก + tests
- **phase:** 2

## Question

ลงมือ implement ตาม decision ที่ปิดแล้วใน [ticket 08](./08-pricing-tier-grilling.md) *(Resolution 2026-10-20 — สูตร hybrid ฉบับสมบูรณ์)* — ห้ามเปลี่ยน decision:

- **ตัดสิน 2 เส้นทางด้วย role ผู้สร้างบิล (sanctum user):** ไม่ใช่ admin → daily เส้นตรงทุกคืน (ku_member → daily_ku, ไม่มี group/month) · admin → ชั้นจำนวนห้อง (≥10 → min_10 · ≥5 → min_5 · ต่ำกว่า → เรทรายวัน) + ชั้นคืนต่อ `booking_room`: x ≥ 30 → hybrid `(a+b)×month + c×เรทรายวันที่ชั้นห้องตัดสิน` โดย `a=floor(x/30) · b=floor(y/20) · c=y%20` · x < 30 → เส้นตรง · `set_room_price` ชนะเด็ดขาด ข้าม hybrid — รายละเอียด + ตารางตัวอย่างที่ Resolution ของ ticket 08
- แทนค่าใน `DiscountService::reprice()` — ระวัง: ฐานส่วนลด, จุดปัด `RoundToTen` คงเดิม, invariant `total_amount = Σ booking_rooms.amount` (ตาม precedent ticket 07 ของ `booking-payment-types`)
- **ราคาที่แสดงต้องตรงกันทั้งระบบ:** `RoomType::rates` wire object, calendar endpoints, availability — ราคา "ต่อคืนที่จะโดนเก็บ" เมื่อเงื่อนไข long-stay/group เป็นจริง ต้องคำนวณจากแหล่งเดียวกัน (อย่าให้ frontend เห็น daily แต่โดนเก็บ month)
- Migration ไม่จำเป็น (เรทมีอยู่แล้วใน `global_rates`) — แต่ **config `booking.block_min_nights` default 21 → 20** (ตามมติ — กระทบ derived `stay_type='block'` ด้วย แก้ test ที่อ้าง 21) · seeder ตัวอย่าง group/month ต้องมีค่าทดสอบได้ (ตรวจ `GlobalRateSeeder`)
- Tests: admin x≥30 → hybrid (เศษ y≥20 ยกบล็อกเดือน · เศษ y<20 คิดรายวัน) · 5/10 ห้อง → group ทั้งบิลสั้นและส่วน c · **user path 100 คืนยัง daily เส้นตรง (ไม่มีสิทธิ์ hybrid)** · ku_member ได้ daily_ku เฉพาะส่วนรายวัน · group ทับ daily_ku · set_room_price ข้าม hybrid · ขอบ 29 คืน admin = 15,000+9×daily · ปัดเศษหลักสิบคงทำงาน · regression ปกติเท่าเดิม
- `php artisan test` เขียวทั้ง suite · อัปเดต `docs/api_guide.md` (กติกาเลือกเรท 2 เส้นทาง + สูตร hybrid) + จดลง `cline.md`

## Resolution

(ยังว่าง)
