# 08: ยกเลิก / no_show ของ booking มัดจำ·ค้างชำระ — เงินที่จ่ายไปแล้วเป็นอย่างไร

- **label:** `wayfinder:grilling`
- **type:** HITL
- **status:** open
- **blocked-by:** [04-amounts-layer-a-and-b](./04-amounts-layer-a-and-b.md)
- **assignee:** (ว่าง)
- **born:** 2026-09-25 — graduate จาก fog "ยกเลิก/คืนมัดจำ" เมื่อ [ticket 02](./02-deposit-semantics.md) ล็อก flow หลักแล้ว

## Question

Flow หลักล็อกแล้ว (ticket 02): มัดจำ verify ผ่าน → `paid` (is_paid ยัง false) → `confirmed` → เก็บยอดค้างตอน check-in แบบไม่บังคับ — แต่ยังไม่มีใครตัดสินทางออกด้านตรงข้าม:

- **ยกเลิกหลังจ่ายมัดจำ:** booking `draft|pending|verify_error` ยกเลิกได้ไหม (ปัจจุบัน machine ไม่มี `cancelled` — draft หมดอายุโดน hard-delete, `verify_error` ค้างรอ) — ถ้าจ่ายมัดจำแล้ว verify ผ่านและแขกยกเลิกก่อนเข้าพัก เงินตามไปอย่างไร
- **no_show / ยกเลิกหลัง confirmed:** booking_room มี `no_show` — booking container เงินมัดจำที่ verify ไปแล้วคืน/หัก/ตัดเป็นค่าปรับ — กติกาอะไร
- **ช่องทางคืนเงิน:** ระบบคืนสลิป/โอนคืนไม่ได้ (slip-based, ไม่มี gateway) — บันทึกการคืนเงินเป็น row อะไร (payments เขียนลบ? ตารางใหม่?) หรือออกนอกระบบ (บันทึกออฟไลน์ ระบบแค่ mark)
- **ผลกับยอดชั้น B:** ยอดจ่ายแล้ว/ค้างบน bookings (ticket 04) ต้องรองรับ "จ่ายแล้วแต่ถูกยกเลิก" ต่างจาก "จ่ายแล้วใช้จริง" ยังไง

**Precondition:** อ่าน resolution [ticket 02](./02-deposit-semantics.md) + [ticket 04](./04-amounts-layer-a-and-b.md) (ยอด 2 ชั้น) ก่อน — ticket นี้ตัดสินบนโครงยอดเงินที่ ticket 04 ล็อกแล้ว
