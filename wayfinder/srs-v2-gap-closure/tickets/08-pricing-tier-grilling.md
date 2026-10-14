# 08: Grill กติกา auto-apply เรท group/month — precedence, ฐานส่วนลด, จุดปัดเศษ (REQ-004/015/016)

- **label:** `wayfinder:grilling`
- **type:** HITL
- **status:** open
- **blocked-by:** (ไม่มี — frontier)
- **assignee:** (ว่าง)
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

## Resolution

(ยังว่าง — รอ grilling คู่กับ owner)
