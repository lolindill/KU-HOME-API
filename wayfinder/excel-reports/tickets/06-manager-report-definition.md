---
label: wayfinder:grilling
type: HITL
title: นิยาม manager-report — template ไม่มี columns เลย
status: closed (2026-10-05 — grilling)
assignee: kevii
blocked-by: ["02-data-coverage-audit"]
---

# 06: นิยาม manager-report

## Question

`manager-report.json` ไม่มี `columns` (audit ยืนยันแล้ว) — layout จริงคือ `metrics(20 แถว) × periods(6 คอลัมน์: Day/MTD/YTD + LY equivalents)` มี filters `date`/`mode` (Complete/Week/Month)/`date_range` — รายงานผู้จัดการนิยามจริงคืออะไร?

- ต้นทาง Google Sheets หน้า "Status" มีอะไรบ้าง (owner เปิดชีตต้นทางได้: sheets id `1v8euwEZZMnsSHmJuV5ZDu3bRE5NI0TTplAW8o_1m3bw`)
- Metric 20 ตัวใน audit §3.15 — ตัวที่ derive ได้แล้ว (occupancy, arrivals/departures, no_show, OOO) ตรงตามชีตหรือไม่
- **`provisional`** ไม่มีสถานะตรงเครื่อง (ใกล้สุด `pending`/`draft`) — นิยามใหม่เป็นอะไร หรือตัดทิ้ง *(ส่วน storage ถ้าต้องมี flag → [ticket 09](./09-erp-agency-inter-unit-booking-attributes.md))*
- `mode` (Complete/Week/Month) หมายความว่าอะไร และ LY (last year) คำนวณยังไงเมื่อข้อมูลยังใหม่
- ถ้า owner ยังนิยามไม่ได้ → ปรับเป็น fog ของ map หรือตัดออกจาก v1 (จดเหตุผลใน Resolution + อัปเดต map)

**Precondition:** อ่าน audit (`../research/data-coverage-audit.md` §3.15) ก่อน — เห็น metric list + สิ่งที่ DB มีให้ใช้

## Resolution

(2026-10-05 — grilling กับ owner ตรง · AskUserQuestion 2 รอบ 7 คำถาม · เทียบชีตจริงจาก [truth snapshot](../research/truth-snapshot/README.md) แท็บ "ตย.รายงานผู้จัดการ")

**โครงรายงาน:** คง layout ตามชีตทุกแถว — matrix **20 metrics (แถว) × 6 คอลัมน์ช่วงเวลา** · แถว 9–14 อ่านเป็น **breakdown ต่อคืนของวันที่ filter:** Total Rooms = Room Occupied + Comfirmed + Provisional + Unsold (แถว % และ OOO-family derive ต่อ) · Day = คืน/วันของวันที่ filter (ไม่สะสม)

**คำตอบรายข้อ (owner เลือกทุกข้อ):**

- **`provisional` = "ยังไม่ยืนยันทั้งหมด"** — booking สถานะ `draft` + `pending` + `verify_error` ที่ครอบคลุมคืนวันที่ filter (**ไม่เพิ่ม storage** จับจาก state machine ได้เลย) · ส่วน Comfirmed = `paid` + `confirmed` · Room Occupied = booking_room `checked_in`/`checked_out` ที่ครอบคลุมคืนนั้น · Unsold = Total − สามกลุ่มแรก (derive)
- **Complimentary Room** = ต้องมี flag จริง — เพิ่ม flag `is_complimentary` บน `bookings` · รายละเอียด design (ใครตั้ง/เงื่อนไข/ผลต่อยอดเงิน) → ย้ายไปตัดสินใน [ticket 09](./09-erp-agency-inter-unit-booking-attributes.md) (อัปเดต bullet แล้ว)
- **mode ตามชีตทั้ง 3 โหมด:** **Complete** = คอลัมน์เต็ม Day/MTD/YTD + LY ครบ 6 · **Week** = แทน MTD ด้วย Week-to-Date (**สัปดาห์เริ่มวันจันทร์**) · **Month** = แทน MTD ด้วยช่วง From-TO ที่เลือก — คอลัมน์ LY คงอยู่ทุกโหมด
- **ค่ารวมช่วง (MTD/YTD/LY):** แถวตัวนับเหตุการณ์ (Total Persons, Arrival/Depart/No Show Rooms+Persons) = **ผลรวมทั้งช่วง** · แถวสถานะห้อง (Occupied/Comfirmed/Provisional/Unsold/OOO-family) = **ค่าเฉลี่ยต่อคืน** · %Occupied = เฉลี่ยรายวันทั้งช่วง — มาตรฐาน hotel manager report
- **LY ตอนไม่มีข้อมูลปีก่อน:** แสดง **0** ไปก่อน — คอลัมน์คงที่ 6 คอลัมน์ตามชีต ระบบรันข้ามปีแล้วตัวเลขเด้งเองโดยไม่แก้ template/renderer
- **OOO ย้อนหลัง (MTD/YTD/LY):** ต้องมี room status history ซึ่งยังไม่มี → **ผูกกับ [ticket 11](./11-room-domain-new-tables.md)** (ตาราง history เป็นพี่เลี้ยง — เพิ่ม cross-ref ให้แล้ว) · v1: คอลัมน์ Day แม่นด้วยสถานะปัจจุบันได้ทันที, คอลัมน์ประวัติของกลุ่ม OOO (4 แถว) ครบเมื่อ history พร้อม

→ ลง spec หัวข้อ "manager-report definition" (ticket 07)
