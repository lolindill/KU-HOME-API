---
label: wayfinder:grilling
type: HITL
title: Formatting & print conventions ของไฟล์ Excel
status: closed (2026-09-22 — grilling)
assignee: maid
blocked-by: []
---

# 05: Formatting & print conventions ของไฟล์ Excel

## Question

ค่าที่ user เห็นในไฟล์ Excel เป็นอย่างไร? (คุยกับ owner ตรง ๆ — เป็นความชอบส่วนตัว ไม่มีใน template)

- วันที่: แสดง **พ.ศ. หรือ ค.ศ.** (ต้นทางชีตแสดง พ.ศ. 2569) — format (`d/m/YYYY`?)
- เงิน (`money_baht` = integer บาท): number format — `#,##0` หรือมีทศนิยม `.00` (ทั้งที่ storage ไม่มีทศนิยม)
- Section header (แถวกลุ่ม เช่น `Fully Paid` / `deposit/ยังไม่ได้ชำระ` ใน check-in-report): merged cell แบบต้นทาง — สไตล์สี/bold ตามไหน
- Summary (`summary` ใน template): แถวรวมท้ายตาราง หรือ block สรุปแยก
- Print setup: ขนาดกระดาษ/แนว (A4 landscape?), freeze panes / repeat header row ตอนพิมพ์, ชื่อชีต (ภาษาไทย?)
- หัวกระดาษ: ชื่อรายงาน + ช่วงวันที่ filter พิมพ์บนหัวไฟล์ไหม (ต้นทางมีธรรมเนียมนี้ — เช่น deposit-report `name_en` = "ข้อมูลวันที่ 14 กันยายน 2569")

## Resolution

(2026-09-22 — grilling กับ owner ตรง · AskUserQuestion)

- **วันที่:** แสดง **พ.ศ. + `d/m/YYYY`** (เช่น 14/09/2569) — DB เก็บ ISO-8601 ตามเดิม, format ตอน render เท่านั้น
- **เงิน (`money_baht`):** number format **`#,##0`** ไม่มีทศนิยม — ตรงกับ storage integer บาท
- **สไตล์ตาราง: Minimal flat table** — ⚠️ owner เลือก **ไม่ mirror ชีตต้นทาง** (ทับ preference ชีต): ไม่ merged cell ไม่ fill สี · header column bold ขาวดำ · section header (เช่น Fully Paid) = แถว bold ธรรมดาไม่ merge · summary = **แถวรวมท้ายตาราง bold** (ไม่ทำ block สรุปแยก) — ขาวดำทั้งไฟล์ เพื่อ filter ใน Excel ได้
- **Print setup:** ครบตามธรรมเนียมชีต — **A4 landscape** · freeze header row + repeat print title rows ทุกหน้า · หัวไฟล์ (แถวบนสุดก่อน header) = **ชื่อรายงานภาษาไทย + "ข้อมูลวันที่ …" ตามช่วง filter** · ชื่อชีต (tab) = ชื่อรายงานภาษาไทย

→ ลง spec หัวข้อ "Formatting & print policy" (ticket 07)
