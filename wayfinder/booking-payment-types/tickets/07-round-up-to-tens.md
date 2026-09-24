# 07: ปัดเศษขึ้นหลักสิบ (round up to tens) — ใช้กับยอดไหน ตรงไหน (REQ-015/016 — SRS v2)

- **label:** `wayfinder:grilling`
- **type:** HITL
- **status:** open
- **blocked-by:** ["04-amounts-layer-a-and-b"]
- **assignee:** (ว่าง)
- **born:** 2026-09-24 — graduate จาก gap ตรวจ SRS v2 (srs_room_booking_v2.pdf) ตามคำสั่ง owner "9 add in still going map"

## Question

REQ-015/016: "ระบบคำนวณราคารวมอัตโนมัติ **หากมีเศษให้ปัดเศษขึ้นหลักสิบ**" (จองรายเดือน/แบบกลุ่ม)

ปัจจุบันทุกยอดเป็น integer บาทตรง ๆ (rate × nights + addon) — **ไม่มี rounding ที่ไหนเลย** และเงินของ SRS เป็นราคาหลักร้อย ก็ไม่ชัดว่าเศษเกิดตรงไหน (เศษจากอะไร: ส่วนลด percent? เรทรายชั่วโมง? หาร per-person ในอนาคต?)

- ปัด "ยอดไหน": ต่อ booking_room (`amount`)? ยอดรวม booking (`total_amount`)? ชั้น A (`confirmations.amount` ต่อครั้งส่งสลิป)?
- ปัดเฉพาะ long stay/กลุ่ม (stay_type monthly/block) หรือทุก booking?
- invariant Σ booking_rooms.amount == bookings.total_amount ต้องคงอยู่ — ปัดที่ห้องแล้วให้ยอดรวมไหลตาม หรือปัดที่ยอดรวม (ห้องเป็นเศษได้)?
- ถ้าโค้ดส่วนลด percent ทำให้มีเศษ ลำดับ (ลดก่อนแล้วปัด / ปัดก่อนแล้วลด) — grill คู่กับ ticket 04 (ยอดเงิน 2 ชั้น)

## Resolution

(ยังว่าง — รอ grilling คู่กับ owner)
