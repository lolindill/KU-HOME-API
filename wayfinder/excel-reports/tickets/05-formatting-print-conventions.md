---
label: wayfinder:grilling
type: HITL
title: Formatting & print conventions ของไฟล์ Excel
status: open
assignee:
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
