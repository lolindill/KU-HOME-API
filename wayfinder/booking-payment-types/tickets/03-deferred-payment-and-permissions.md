# 03: ค้างชำระ (deferred) + สิทธิ์การใช้ — path บน state machine, deadline, เก็บเงินปลายทาง

- **label:** `wayfinder:grilling`
- **type:** HITL
- **status:** open
- **blocked-by:** [01-payment-type-enum-and-schema](./01-payment-type-enum-and-schema.md)
- **assignee:** (ว่าง)

## Question

Booking แบบค้างชำระ — ไม่ส่งสลิปตอนจอง อนุมัติไปก่อนแล้วเก็บเงินทีหลัง (owner: **org booking ใช้ type นี้เสมอ** — คำตอบใบนี้จะส่งมอบให้แมป `organization-bookings` ticket 04 ด้วย):

- **สิทธิ์:** ตั้ง `deferred` ได้ใคร — admin only (บน `POST /bookings` โหมด admin ของแมป organization-bookings) หรือ user ทั่วไปเลือกได้ด้วย — ถ้า admin only ต้อง guard ยังไง
- **State path:** อนุมัติแล้ว booking วิ่งยังไง — `draft → confirmed` ข้าม `paid` เลย (เหมือน walk-in เดิม)? หรือ `draft → paid` เพราะ "ผ่านการอนุมัติเชิงเอกสาร" — `is_paid` ตอนนี้เป็น true หรือ false
- **`payment_deadline` 24 ชม. / `CleanupExpiredDrafts`:** draft แบบค้างชำระโดนลบใน 24 ชม.ไหม — องค์กรรออนุมัติเอกสารอาจไม่ทัน (ประเด็นเดียวกับ org ticket 04 — ตัดสินที่นี่ทีเดียว)
- **เก็บเงินปลายทาง:** ใคร mark ว่าจ่ายครบแล้ว ผ่านอะไร — path เงินสดเดิม `FrontDeskController::recordPayment`? endpoint ใหม่? แล้ว transition ไปไหนเมื่อจ่ายครบ
- **เงื่อนไขปิด booking:** `complete` ได้ทั้งที่ยังค้างชำระไหม — หรือต้องจ่ายครบก่อนถึงปิดได้

⚠️ ยอดเงิน (ชั้น A/B) ตัดสินใน [ticket 04](./04-amounts-layer-a-and-b.md) — ticket นี้ล็อกกลไก/สิทธิ์/เงื่อนไข
