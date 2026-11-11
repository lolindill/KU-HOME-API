---
label: wayfinder:task
type: AFK
title: "docs v2 — cline.md + api_guide + AGENTS.md สำหรับ tier-endpoints-v2"
status: open
assignee:
blocked-by: ["01-tier-change-family.md", "02-split-pure.md"]
---

# 04: เอกสาร v2

## Question

อัปเดตเอกสาร 3 จุดจากโค้ดจริงหลัง tickets 01+02 merge — ทำโดย orchestrator session (ZCode) ตาม flow ที่ owner กำหนด

## Checklist

- [ ] **cline.md** — แก้หัวข้อ "✂️ Split-upgrade" + เพิ่มหัวข้อ tier-change family v2: มติ v2 6 ข้อ (supersede มติ #5/#11 เดิม — จดเหตุผล owner 2026-11-11), downgrade-no-refund + syncIsPaid, staff manual price, coverage matrix, breaking change mode 2 (scope BR ไม่มีห้อง)
- [ ] **docs/api_guide.md** — split (contract ใหม่), PUT โหมด 2 (role/scope/เงินใหม่ + error matrix), change-room (gate ขยาย + price_per_night + ceremony แยก checked_in/confirmed), คำเตือน float (ยังจริงกับ tier-change)
- [ ] **AGENTS.md** — แก้ bullet ✂️ + เพิ่ม/ปรับ bullet tier-change family ชี้ cline.md

## Definition of done

เอกสารตรงโค้ดจริงบน dev ทุกจุด · commit ไปกับ branch ที่ทำงานอยู่
