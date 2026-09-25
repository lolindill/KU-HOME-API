# 08: ยกเลิก / no_show ของ booking มัดจำ·ค้างชำระ — เงินที่จ่ายไปแล้วเป็นอย่างไร

- **label:** `wayfinder:grilling`
- **type:** HITL
- **status:** closed
- **closed:** 2026-09-25
- **blocked-by:** [04-amounts-layer-a-and-b](./04-amounts-layer-a-and-b.md)
- **assignee:** kevii (grilling session 2026-09-25 — zcode, ต่อจาก session ก่อนที่ mark closed โดยยังไม่บันทึก Resolution — owner สั่ง "ask me again")
- **born:** 2026-09-25 — graduate จาก fog "ยกเลิก/คืนมัดจำ" เมื่อ [ticket 02](./02-deposit-semantics.md) ล็อก flow หลักแล้ว

## Question

Flow หลักล็อกแล้ว (ticket 02): มัดจำ verify ผ่าน → `paid` (is_paid ยัง false) → `confirmed` → เก็บยอดค้างตอน check-in แบบไม่บังคับ — แต่ยังไม่มีใครตัดสินทางออกด้านตรงข้าม:

- **ยกเลิกหลังจ่ายมัดจำ:** booking `draft|pending|verify_error` ยกเลิกได้ไหม (ปัจจุบัน machine ไม่มี `cancelled` — draft หมดอายุโดน hard-delete, `verify_error` ค้างรอ) — ถ้าจ่ายมัดจำแล้ว verify ผ่านและแขกยกเลิกก่อนเข้าพัก เงินตามไปอย่างไร
- **no_show / ยกเลิกหลัง confirmed:** booking_room มี `no_show` — booking container เงินมัดจำที่ verify ไปแล้วคืน/หัก/ตัดเป็นค่าปรับ — กติกาอะไร
- **ช่องทางคืนเงิน:** ระบบคืนสลิป/โอนคืนไม่ได้ (slip-based, ไม่มี gateway) — บันทึกการคืนเงินเป็น row อะไร (payments เขียนลบ? ตารางใหม่?) หรือออกนอกระบบ (บันทึกออฟไลน์ ระบบแค่ mark)
- **ผลกับยอดชั้น B:** ยอดจ่ายแล้ว/ค้างบน bookings (ticket 04) ต้องรองรับ "จ่ายแล้วแต่ถูกยกเลิก" ต่างจาก "จ่ายแล้วใช้จริง" ยังไง

**Precondition:** อ่าน resolution [ticket 02](./02-deposit-semantics.md) + [ticket 04](./04-amounts-layer-a-and-b.md) (ยอด 2 ชั้น) ก่อน — ticket นี้ตัดสินบนโครงยอดเงินที่ ticket 04 ล็อกแล้ว

## Resolution

**(2026-09-25 — grilling กับ owner ผ่าน AskUserQuestion, 2 รอบรวม 7 คำถาม)**

**Decision (owner ตัดสิน) — หลักใหญ่: ไม่มี "ยกเลิก" ในระบบเลย:**

1. **ห้ามยกเลิกหลังจ่าย** — booking ที่มีเงินเข้า ledger แล้ว (paid/confirmed จาก verify หรือเงินสด) **ไม่มีทางยกเลิกทุกช่องทาง** — ไม่เพิ่มสถานะ `cancelled` ใน machine เด็ดขาด
2. **ก่อนจ่าย = กลไกเดิมพอแล้ว** — ยกเลิก/ลบได้เฉพาะก่อนมีเงินเข้า: draft (DELETE /bookings/{id} + CleanupExpiredDrafts 15 นาที) และ verify_error (ค้างรอส่งสลิปใหม่) · guard **"มี payments row = ห้ามลบ"** (ticket 06 ใส่ไว้แล้วทั้ง cleanup + destroyBooking) คือเส้นแบ่งเดียวที่ต้องการ — ไม่เพิ่มช่องยกเลิกให้ user ใช้เอง
3. **no_show = ยึดมัดจำทั้งหมดเป็นค่าปรับ** — มัดจำที่ verify ไปแล้วถือเป็นรายได้ตายแล้ว (ledger คงอยู่ ไม่มีการเขียนย้อน) · ระบบ**ไม่ต้องทำอะไรเพิ่ม** — no_show เป็นสถานะ BR-level ที่มีอยู่, complete ได้ทั้งที่ยอดค้าง (ticket 03) คงเดิม
4. **ยอดค้างส่วนที่เหลือ (total − มัดจำ) หลัง no_show = admin ตัดสินรายกรณี** — จะไล่เก็บต่อผ่าน recordPayment ถึงตอน complete ก็ได้ จะปล่อยปิดบัญชีโดยไม่เก็บก็ได้ — ระบบไม่ enforce ทั้งสองทาง (เช่นเดียวกับ policy ไม่มี hard guard เรื่องเงินของ ticket 02)
5. **ไม่มีการคืนเงินในระบบ** — ไม่มี refund row/refund flow/ตารางใหม่ · การคืนเงินจริง (ถ้าเกิด) เป็นกระบวนการนอกระบบล้วน — ledger `payments` เขียนแค่เงินเข้าเท่านั้น (คง 3 จุดเขียนเดิม)
6. **ยอดชั้น B ไม่ต้องแตะ** — ไม่มี cancel → ไม่มีเคส "จ่ายแล้วแต่ถูกยกเลิก" → `paid_amount`/`outstanding_amount` derive เดิมใช้ได้ต่อ ไม่เพิ่ม field
7. **กรณีเปลี่ยน room type หลังจ่ายแล้ว ราคาเพิ่ม → ปรับราคาให้จ่ายเพิ่มได้** (owner: "มีกรณีเปลี่ยน room type แล้วราคาเพิ่ม ที่ทำได้ ปรับราคาให้จ่ายเพิ่มด้วย") — ระบบปัจจุบันแก้ booking_room ได้เฉพาะ draft → graduate เป็น ticket implement ใหม่: [ticket 10](./10-implement-surcharge-after-payment.md)

**ส่งมอบ:** surcharge หลังจ่าย = [ticket 10](./10-implement-surcharge-after-payment.md) (task — ปลดล็อกทันที, blocked-by ปลดครบเพราะ 04–09 ปิดหมด)
