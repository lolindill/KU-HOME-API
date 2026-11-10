---
label: wayfinder:task
type: AFK
title: "docs — cline.md + api_guide + AGENTS.md สำหรับ split-upgrade"
status: open
assignee:
blocked-by: ["01-implement-split-upgrade.md"]
---

# 03: เอกสารประกอบ split-upgrade

## Question

ผูก spec ที่ implement จริง (อ้างจากโค้ดจริง ไม่ใช่แผน) เข้าเอกสาร 3 จุดของโปรเจกต์ — ทำ**หลัง** tickets/01 merge เท่านั้น (เขียนจากโค้ดจริง ไม่ใช่จากแผน)

## Checklist

- [ ] **`cline.md`** — หัวข้อใหม่ "✂️ Split-upgrade (แบ่งครึ่งช่วงพักอัปเกรด tier)" บันทึก: มติ 8 ข้อจาก [map](../map.md) (ย้ายมาเป็นข้อยุติถาวร ห้าม re-litigate) · deviation 2 จุดจาก precedent: (1) money guard เทียบส่วนค่าห้องก่อนปัด ไม่ใช่ total หลัง RoundToTen + เหตุผล artifact ±5 บาท (2) reprice รับ override map — chokepoint ยังเขียน amount ทางเดียว · bug index เลขถัดไปถ้ามี
- [ ] **`docs/api_guide.md`** — endpoint ใหม่: contract ตามที่ implement จริง, request/response, error matrix, state note (split ไม่ transition สถานะใด ๆ — พิธี manual), ตัวอย่าง payload
- [ ] **`AGENTS.md`** — เพิ่ม bullet สั้นใน Critical Conventions (หรือ State Machines section) ชี้ไป cline.md ประโยคเดียวแบบ feature อื่น

## Definition of done

เอกสาร 3 ไฟล์อัปเดต · ตัวเลข/ชื่อ field/สถานะทุกจุดตรงกับโค้ดจริงบน `dev` · commit ไปกับ branch ที่ทำงานอยู่
