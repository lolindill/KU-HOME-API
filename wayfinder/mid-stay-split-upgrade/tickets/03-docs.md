---
label: wayfinder:task
type: AFK
title: "docs — cline.md + api_guide + AGENTS.md สำหรับ split-upgrade"
status: closed *(2026-11-11 — เอกสาร 3 จุดอัปเดตจากโค้ดจริงบน dev ครบ)*
assignee: ZCode (orchestrator session 2026-11-11)
blocked-by: ["01-implement-split-upgrade.md"]
---

# 03: เอกสารประกอบ split-upgrade

## Question

ผูก spec ที่ implement จริง (อ้างจากโค้ดจริง ไม่ใช่แผน) เข้าเอกสาร 3 จุดของโปรเจกต์ — ทำ**หลัง** tickets/01 merge เท่านั้น (เขียนจากโค้ดจริง ไม่ใช่จากแผน)

## Checklist

- [ ] **`cline.md`** — หัวข้อใหม่ "✂️ Split-upgrade (แบ่งครึ่งช่วงพักอัปเกรด tier)" บันทึก: มติ 11 ข้อจาก [map](../map.md) (8 grill + 3 scrutinize 2026-11-11 — ย้ายมาเป็นข้อยุติถาวร ห้าม re-litigate) · deviation 4 จุดจาก precedent: (1) money guard เทียบส่วนค่าห้องก่อนปัด ไม่ใช่ total หลัง RoundToTen + เหตุผล (2) reprice รับ override map **แบบ pin ทุก BR** (freeze BR อื่น — ต่างจาก change-room ที่ reprice ทั้งบิล) (3) **⚠️ ราคา split เป็น one-shot (มติ float)** — reprice ครั้งถัดไปคิด BR-1/BR-2 ใหม่จาก global_rates วันนี้ ราคาตกลงตอน split ไม่รอด — front desk ต้องตรวจ surcharge envelope หลังแตะ addon/change-room บนบิล split ทุกครั้ง (4) ledger guard + is_paid reset copy จาก updateRoom/changeRoom · bug index เลขถัดไปถ้ามี
- [ ] **`docs/api_guide.md`** — endpoint ใหม่: contract ตามที่ implement จริง, request/response, error matrix, state note (split ไม่ transition สถานะใด ๆ — พิธี manual), **คำเตือนราคา one-shot (มติ float)** ให้ front desk อ่านชัด ๆ, ตัวอย่าง payload
- [ ] **`AGENTS.md`** — เพิ่ม bullet สั้นใน Critical Conventions (หรือ State Machines section) ชี้ไป cline.md ประโยคเดียวแบบ feature อื่น

## Definition of done

เอกสาร 3 ไฟล์อัปเดต · ตัวเลข/ชื่อ field/สถานะทุกจุดตรงกับโค้ดจริงบน `dev` · commit ไปกับ branch ที่ทำงานอยู่

## Resolution (2026-11-11)

ทำโดย ZCode orchestrator (ตาม flow ที่ owner สั่ง — ticket 03 เป็นงานของ session นี้) หลัง tickets 01+02 ปิด อ้างจากโค้ดจริงบน branch (implement `ee4fd5e` + tests/fixes `ff4be74`):

- **cline.md** — หัวข้อใหม่ "✂️ Split-upgrade (แบ่งครึ่งช่วงพักอัปเกรด tier)": context + มติล็อก 11 ข้อ (ห้าม re-litigate) + deviation 4 จุด (money guard ก่อนปัด / pin ทุก BR / one-shot float / ledger guard overpay-safe + is_paid reset) + pre-merge bug fixes 3 จุด (Eloquent Collection / late_hours capture / overpay-safe — test-driven ก่อน merge จึงไม่เข้า bug index) + test record
- **docs/api_guide.md** — endpoint section แทรกหลัง change-room: เงื่อนไขครบ, กติกาเรื่องราคา (manual/computed/pre-flight), ⚠️ คำเตือนราคา one-shot ให้ front desk อ่านชัด, ผลลัพธ์ใน transaction, request/response + field table, error matrix 403/404/422, หมายเหตุ deferred + draft
- **AGENTS.md** — bullet ✂️ (11/11/26) ต่อท้าย BookingRoom bullet ใน State Machines ชี้ cline.md (สรุป endpoint + ไม่ transition สถานะ + ราคา one-shot + มติ 11 ข้อ)
