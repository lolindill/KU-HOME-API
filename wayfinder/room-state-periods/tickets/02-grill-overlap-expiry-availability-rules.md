---
label: wayfinder:grilling
type: HITL
status: open
assignee:
blocked-by: ["01-grill-period-model-vs-flag"]
---

# 02: Grill กฎ overlap/expiry กับ booking และการนับ availability ระหว่าง period

> 📌 **ฐานล็อกแล้วจาก ticket 01 (2026-09-24):** ตารางเดียว `room_state_periods` (kind `reserved`|`maintenance`) · derived ตอน query ไม่มี sweep · สถานะ `maintenance` ถูกถอดออกจาก machine · reserved period admin override ผ่าน `include_reserved` ได้ / maintenance ตัดเด็ดขาด — คำถามด้านล่างคือ**กฎที่เหลือ** ไม่รวมสิ่งที่ล็อกไปแล้ว

## Question

เมื่อโมเดล period ล็อกแล้ว (ticket 01) — กฎปฏิสัมพันธ์กับระบบจองคืออะไร?

1. **Overlap กับ booking จริง:** admin เปิด period ทับห้องที่มี BR ค้างคืน (draft/confirmed/checked_in) ได้ไหม — บล็อก, เตือน, หรือบังคับย้ายห้อง? ใช้ overlap query แบบเดียวกับ `booking_rooms` (`check_in < end AND check_out > start`)?
2. **Availability ระหว่าง period บางวัน:** ห้องสำรอง period กลางเดือน 3 วัน — per-day/ranges endpoints นับรายวันจริงไหม หรือ pool ถือว่าห้องนี้ "ออกจากขาย" ทั้งช่วงแบบเดิม?
3. **Check-in ทับ period:** แขกที่จองไว้ก่อน period เข้าพักวันที่ตรงกับ period — อนุญาต (period สำหรับห้องว่างเท่านั้น) หรือไม่?
4. **งาน HousekeepingTask ระหว่าง maintenance period:** สร้าง/รับงานเก็บ-ซ่อมบนห้องที่ติด period ได้ไหม (เดิมงานซ่อมผูกกับสถานะ `maintenance` ซึ่งกำลังจะถูกถอด — flow ต้องอ้าง period แทน)? *(ส่วน walk-in/include_reserved ตอบแล้วใน ticket 01 ข้อ 4 — reserved override ได้, maintenance เด็ดขาด)*

**Blocked by:** ~~ticket 01~~ ✅ ปิดแล้ว (2026-09-24) — **frontier ตัวจริงตอนนี้**

- [ ] grill 4 คำถามกับ owner
- [ ] เขียน resolution + ปิด ticket + อัปเดต map Decisions so far
