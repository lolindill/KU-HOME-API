# 11: Implement — ชั้นบันทึกใบเสร็จต่อ booking + split (จาก ticket 10)

- **label:** `wayfinder:task`
- **type:** AFK
- **status:** open
- **blocked-by:** [10-receipt-issuance-grilling](./10-receipt-issuance-grilling.md)
- **assignee:** (ว่าง)
- **summary:** Implement ตาราง/endpoint ออกใบเสร็จ + split ต่อผู้รับตาม design ticket 10 + tests
- **phase:** 4

## Question

ลงมือ implement ตาม design ที่ปิดแล้วใน [ticket 10](./10-receipt-issuance-grilling.md) — ห้ามเปลี่ยน decision:

- Migration ตารางใหม่ตาม Resolution (อย่าแตะ `receipts` ❄️ ถ้า Resolution เลือกตารางใหม่) + model + เลข run ผ่าน atomic sequence ตาม precedent `Booking::generateUniqueConfirmation()`
- Endpoints: ออกใบเสร็จ (บันทึก split ต่อผู้รับ — validate ผลรวม) + ดูรายการ/รายละเอียดที่ออกแล้ว — role ตาม Resolution · throttle ตามธรรมเนียม
- ข้อมูลที่ frontend ต้องใช้ render PDF ต้องครบจาก endpoint เดียว (header: ชื่อห้อง/ช่วงเข้าพัก/booking ref · lines: ชื่อผู้รับ, ยอด, อ้าง ledger) — ไม่ต้องมี PDF endpoint
- อย่าลืม: response shape `{"status":"success",...}` · ห้าม leak `$e->getMessage()` บน 500 · audit เทียบเท่าธรรมเนียมเดิม
- Tests: ออกแบบ 1/2/3 คน + กรอกเอง · ผลรวมไม่ตรง → 422 · เงื่อนไขออกไม่ผ่าน → 422 · เลขไม่ซ้ำแม้ concurrent (SQLite caveat: ล็อกทำงานจริงบน pgsql) · roles
- `php artisan test` เขียวทั้ง suite · อัปเดต `docs/api_guide.md` + deviation ledger (REQ 3.8 เปลี่ยนจาก MISSING → บันทึกฝั่ง backend เสร็จ · render ฝั่ง frontend ตาม deviation) + จด `cline.md`

## Resolution

(ยังว่าง)
