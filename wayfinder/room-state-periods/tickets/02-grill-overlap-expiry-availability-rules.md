---
label: wayfinder:grilling
type: HITL
status: open
assignee:
blocked-by: ["01-grill-period-model-vs-flag"]
---

# 02: Grill กฎ overlap/expiry กับ booking และการนับ availability ระหว่าง period

## Question

เมื่อโมเดล period ถูกเลือกแล้ว (ticket 01) — กฎปฏิสัมพันธ์กับระบบจองคืออะไร?

1. **Overlap กับ booking จริง:** admin เปิด period ทับห้องที่มี BR ค้างคืน (draft/confirmed/checked_in) ได้ไหม — บล็อก, เตือน, หรือบังคับย้ายห้อง? ใช้ overlap query แบบเดียวกับ `booking_rooms` (`check_in < end AND check_out > start`)?
2. **Availability ระหว่าง period บางวัน:** ห้องสำรอง period กลางเดือน 3 วัน — per-day/ranges endpoints นับรายวันจริงไหม หรือ pool ถือว่าห้องนี้ "ออกจากขาย" ทั้งช่วงแบบเดิม?
3. **Check-in ทับ period:** แขกที่จองไว้ก่อน period เข้าพักวันที่ตรงกับ period — อนุญาต (period สำหรับห้องว่างเท่านั้น) หรือไม่?
4. **Walk-in / ซ่อมแซมจริง:** period `maintenance` ระหว่างทาง housekeeping ยังสร้างงานได้ไหม และ walk-in เข้าห้อง reserved period ผ่าน include_reserved ได้เหมือนเดิมไหม?

**Blocked by:** ticket 01 (โมเดลต้อง lock ก่อน กฎถึงมีฐานยืน)

- [ ] grill 4 คำถามกับ owner
- [ ] เขียน resolution + ปิด ticket + อัปเดต map Decisions so far
