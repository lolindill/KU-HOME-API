# 08: Grill กติกา auto-apply เรท group/month — precedence, ฐานส่วนลด, จุดปัดเศษ (REQ-004/015/016)

- **label:** `wayfinder:grilling`
- **type:** HITL
- **status:** closed
- **blocked-by:** (ไม่มี — frontier)
- **assignee:** zcode session (grilling กับ owner 2026-10-14 · ต่อ grilling สูตร hybrid 2026-10-20)
- **summary:** Grill owner 4 edge ของการ auto-apply เรท group/month ก่อน implement ใน reprice
- **phase:** 2

## Question

Owner ล็อกแล้ว (2026-10-14): **auto-apply ฝั่ง server** — ≥30 คืน → เรท `month` · ≥5 ห้อง → `group` min_5 · ≥10 ห้อง → min_10 แทน `daily` ใน `DiscountService::reprice` ปิด REQ-004/015/016 โดยไม่เปลี่ยน contract แต่เหลือ edge 4 ข้อที่ต้อง owner ตัดสินก่อนลงมือ:

1. **Precedence กับ `daily_ku`:** ku_member จอง long-stay ≥30 คืน — ใช้ `month` หรือ `daily_ku`? (และถ้ามี `month` สำหรับ KU แยกหรือใช้ตัวเดียว) · group ≥5 ห้องโดย ku_member ล่ะ?
2. **ฐานโค้ดส่วนลด:** โค้ดส่วนลดคิดจาก `room_amount` (rate × คืน) — หลังแทน rate เป็น group/month ฐานจะเปลี่ยน · `percent`/`fixed`/`set_room_price` ยังแปลถูกไหม และ `set_room_price` ทับเรทกลุ่มอย่างไร (ใครชนะใคร ตามลำดับคำนวณเดิมหรือแก้)
3. **จุดปัดเศษหลักสิบ:** ปัดที่ `booking_rooms.amount` หลังรวม addon ตามเดิม (precedent ticket 07 ของ `booking-payment-types`) — ยืนยันว่าการแทน rate ไม่ต้องย้ายจุดปัด
4. **ขอบเขตผู้ใช้:** REQ-006 จำกัด user จองเอง 4 ห้อง → เรท group ≥5 ห้องจึงเกิดได้เฉพาะ path admin/org-booking — ยืนยันว่าใช่ (auto-apply ไม่ต้องเช็ค role เพราะ cap กันอยู่แล้ว หรือต้องเช็ค)

**บริบทใน repo:** `GlobalRate` rate_type = `daily | daily_ku | group (min_5_rooms/min_10_rooms) | month` · chokepoint คำนวณเดียว = `DiscountService::reprice()` (:166-208) + `getEffectiveDailyRate()` (:126-138) · ปัดเศษ = `App\Support\RoundToTen` · ราคายาวปัจจุบัน = daily × คืน เส้นตรง (`BookingLongStayTest` จดไว้)

**Done เมื่อ:** Resolution ตอบครบ 4 ข้อ พร้อมตัวอย่างตัวเลข 1 เคสต่อคำตอบ (เช่น Deluxe 10 คืน × ku_member) — ticket 09 ห้ามเริ่มก่อน ticket นี้ปิด

## Amendment (2026-10-20 — owner เสนอสูตร hybrid สำหรับ month)

Owner ให้หลักฐานใหม่: กติกา `month` **ไม่ใช่ "แทน rate ต่อคืนทั้งชุด"** แต่เป็น **นับบล็อกเดือน + เศษคิดรายวัน** (สูตรที่ owner พิมพ์เอง):

```
x = จำนวนคืน          a = floor(x / 30)   → บล็อกเดือนเต็ม
y = x % 30            b = floor(y / 20)   → เศษ ≥ 20 คืน ยกเป็นบล็อกเดือนเพิ่ม 1
                      c = y % 20          → เศษที่เหลือ
total = (a + b) × monthly_rate + c × (daily|group rate)
```

ตัวอย่าง (daily 1,000 · month 15,000): x=45 → 15,000 + 15,000 = 30,000 · x=30 → 15,000 · x=29 → 15,000 + 9,000 = 24,000 (แพงกว่า 30 คืน!) · x=20 → 15,000 (cliff: 19 คืน daily = 19,000)

**สูตรนี้ supersede การอ่าน "≥30 คืน → เรท `month` แทน `daily`" แบบ flat** (หาก owner ยืนยันใน resolution) — edge เพิ่มที่ต้อง grill ต่อจากเดิม 4 ข้อ:

5. **ขอบเขต x** — ใช้สูตรทุกจำนวนคืน หรือ gate ที่ x ≥ 30 (ต่ำกว่านั้น daily เส้นตรง)? ยอมรับ cliff ที่ 20/29 คืนหรือไม่
6. **เกณฑ์ของ y** — สูตรใช้ 20 แต่ config มี `booking.block_min_nights = 21` อยู่แล้ว — ยกมาใช้ร่วม หรือค่าใหม่ 20 ล้วน?
7. **เรทของส่วน c** — `daily` เสมอ หรือ `daily|group` ตามจำนวนห้อง (ตามสูตรที่พิมพ์) — แล้ว `daily_ku` (ku_member) มีสิทธิ์ในส่วน c หรือไม่ (ผูกกับ edge ข้อ 1 เดิม)
8. **ระดับการนับ x** — ต่อ `booking_room` (แต่ละห้องมี check_in/out ของตัวเองแล้วในระบบนี้) หรือรวมคืนทุกห้องระดับ booking?

ข้อ 2–4 เดิม (ฐานส่วนลด / จุดปัดเศษ / ขอบเขตผู้ใช้) คงต้องตอบใน Resolution ให้ครบ

## Resolution

*(ล็อกกับ owner 2026-10-20 — ครบทั้ง edge เดิม 1–4 และ edge เพิ่ม 5–8 จาก Amendment)*

### กติกา pricing ใหม่ (supersede การอ่าน "แทน rate ต่อคืน" แบบ flat)

**กติกาสุดท้าย** *(2026-10-20 — รวบ amendments โดยมีการแก้คำผิดรอบสาม: "monthly user can book >= 20" → **"i mean cant"** — ฉบับนี้ใช้ implement จริง)*

**A. เส้นทาง user (creator ≠ admin — user/guest/ku_member/staff จองตัวเอง):**

- **x < 20 คืน** → daily เส้นตรง `amount = x × effective_daily_rate` (ku_member → `daily_ku` ตามมติ 2026-09-07)
- **ห้องไหน x ≥ 20 คืน → 422 reject ทั้งบิล** — เขตราคาเดือนเริ่มที่ 20 เป็นของ admin *(amendment รอบสามแก้คำผิด: owner "monthly user can book >= 20 … i mean cant" + "hit cap are 20, last cap are 30 like in formula that /30 then /20" — user ชน cap ที่ 20 · ตัวเลข 30 ในข้อความ = บล็อกเดือนของสูตรฝั่ง admin ไม่ใช่ประตูของ user)*
- **≥5 ห้อง → 422 reject ผ่าน cap เดิม** (`BookingRule` non-admin = `booking.max_rooms_per_booking` = 4) — ไม่ต้องสร้าง guard ใหม่ · guard ทั้งหมดต้องครอบทุก write path ที่จำนวนคืนเปลี่ยนได้: create + batch edit + single edit (draft)

**B. เส้นทาง admin (creator = admin — mode A จองแทน / mode B เฮดเปล่า / org booking):**

- **ชั้นจำนวนห้อง (นับ booking_rooms ทั้งบิล):** ≥10 → `group min_10_rooms` · ≥5 → `min_5_rooms` (แถว rate ของ room_type นั้น ๆ) · ต่ำกว่า 5 → เรทรายวัน · เรท group ทับ daily/daily_ku ทั้งบิลสั้นและส่วน c ของ hybrid (owner: "if it daily use ku but if เข้าสิท group use group") · org booking (userless) ส่วน daily → `daily`
- **ชั้นจำนวนคืน (ต่อ booking_room):** **x ≥ 30** → hybrid `a = floor(x/30) · y = x%30 · b = floor(y/20) · c = y%20` → `amount = (a+b) × month_rate + c × เรทรายวันที่ชั้นห้องตัดสิน` · x < 30 → เส้นตรง `x × เรท (group|daily)` *(gate 30 ตามมติรอบแรก — ไม่เคยถูกเปลี่ยน · ข้อความ "last cap are 30" ยืนยันตัวเลขนี้)*

### ค่า config

- `booking.block_min_nights` **เปลี่ยน default 21 → 20** (owner สั่ง "change that 21 to 20") — ผลต่อ derived `stay_type='block'` (จองเหมา ≥20 คืน) ด้วย · **ประตู hybrid ใช้ค่าเดียวกับ `block_min_nights` (20)** · `monthly_min_nights` คง 30 เป็นแค่ป้าย `stay_type='monthly'` (ไม่เกี่ยว pricing)

### Edge เดิมข้อ 1–4

1. **Precedence กับ daily_ku:** ku_member ได้ `daily_ku` เฉพาะส่วนที่คิดรายวัน (บิลสั้น / ส่วน c) · ส่วนบล็อกเดือนใช้ `month` ล้วน · group ทับ daily_ku เมื่อครบเงื่อนไขห้อง (เส้นทาง admin เท่านั้น)
2. **ฐานโค้ดส่วนลด:** ตาม precedent — `percent`/`fixed` คิดจากยอด hybrid ใหม่ตามปกติ · **`set_room_price` ชนะเด็ดขาด** = `set_price × x` ตรง ๆ **ข้าม hybrid ทั้งชุด** (ราคาสัญญาชัดเจน ไม่ปนบล็อกเดือน)
3. **จุดปัดเศษ:** ยืนยันคง `RoundToTen` ที่ `booking_rooms.amount` หลังรวม addon — การเปลี่ยนสูตรไม่ย้ายจุดปัด (precedent ticket 07 ของ booking-payment-types)
4. **ขอบเขตผู้ใช้:** ยืนยัน — group ≥5 ห้องเกิดได้เฉพาะเส้นทาง admin (REQ-006 cap user 4 ห้อง) · auto-apply ตัดสินจาก creator role ไม่ต้องเช็คเพิ่ม เพราะ cap กันอยู่แล้ว

### ตัวอย่างตัวเลข (Deluxe: daily 1,000 · daily_ku 800 · group min_5 750 · month 15,000)

| เคส | คำนวณ | ยอด |
|---|---|---|
| user จองเอง 19 คืน | ต่ำกว่า cap 20 → daily เส้นตรง | 19 × 1,000 = **19,000** |
| user จองเอง 25 คืน / 100 คืน | ห้อง x ≥ 20 → **422 reject** (เขต monthly ของ admin) | — |
| user จอง 6 ห้อง | โดน cap `max_rooms_per_booking` = 4 → 422 อยู่แล้ว | — |
| ku_member จองเอง 10 คืน | daily_ku เส้นตรง | 10 × 800 = **8,000** |
| admin 1 ห้อง 45 คืน | a=1, y=15, b=0, c=15 | 15,000 + 15×1,000 = **30,000** |
| admin 1 ห้อง 33 คืน (target ku_member) | a=1, y=3, c=3 × daily_ku 800 | 15,000 + 2,400 = **17,400** |
| admin 6 ห้อง ห้องละ 33 คืน | group min_5: a=1, y=3, c=3 → 15,000+2,250 → ปัดขึ้นหลักสิบ | **17,300/ห้อง** |
| admin 6 ห้อง ห้องละ 35 คืน | group min_5: a=1, y=5, c=5 | (15,000 + 5×750) = **18,750/ห้อง** |
| admin 33 คืน + set_room_price 900 | ชนะเด็ดขาด ข้าม hybrid | 900 × 33 = **29,700** |

Cliff ที่ยอมรับ (เส้นทาง admin): 29 คืน = 24,000 แพงกว่า 30 คืน = 15,000 · 49 คืน (เศษ 19) แพงกว่า 50 คืน (เศษ 20 → บล็อกเดือน)

**Done:** ticket 09 ปลดล็อก — implement ใน `DiscountService::reprice` ตาม Resolution นี้เท่านั้น (ห้ามเปลี่ยน) · อัปเดต `docs/api_guide.md` + `cline.md` + tests ตาม ticket 09
