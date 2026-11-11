---
label: wayfinder:task
type: AFK
title: "docs v2 — cline.md + api_guide + AGENTS.md สำหรับ tier-endpoints-v2"
status: closed *(2026-11-11 — เอกสาร 3 จุดอัปเดตจากโค้ดจริงครบ)*
assignee: ZCode (orchestrator session 2026-11-11)
blocked-by: ["01-tier-change-family.md", "02-split-pure.md"]
---

# 04: เอกสาร v2

## Question

อัปเดตเอกสาร 3 จุดจากโค้ดจริงหลัง tickets 01+02 merge — ทำโดย orchestrator session (ZCode) ตาม flow ที่ owner กำหนด

## Checklist

- [x] **cline.md** — หัวข้อ "✂️ Split-upgrade" เขียนใหม่เป็น pure same-tier (มติ v1 11 ข้อ + mark supersede ข้อ 4/5/8/9/11 ด้วยมติ v2) + หัวข้อใหม่ "🔀 Tier-change family v2" (มติ v2 7 ข้อรวม amendment ข้อ 7 draft gate, downgrade-no-refund + syncIsPaid, staff manual price, coverage matrix, breaking mode 2, float reprice ยังจริงกับ tier-change) + supersede note บนหัวข้อ 🚪 2026-11-04 เดิม
- [x] **docs/api_guide.md** — PUT BR (โหมด 2 ใหม่: role/scope/เงิน + Request Body price_per_night + error table) · change-room (gate ขยาย draft/confirmed/checked_in + ceremony แยก + price_per_night + เลิก upgrade-only + ตัดตัวอย่าง 422 downgrade เก่า) · split (contract pure + field ตัด 422 + invariant + response ใหม่ surcharge 0) · summary 💳 Surcharge → Change-tier + quick-ref move window
- [x] **AGENTS.md** — bullet ✂️ รวม tier-change 🔀 ต่อท้าย BookingRoom bullet ชี้ cline.md

## Definition of done

เอกสารตรงโค้ดจริงบน dev ทุกจุด · commit ไปกับ branch ที่ทำงานอยู่

## Resolution (2026-11-11)

ทำโดย ZCode orchestrator (ตาม flow ที่ owner สั่ง) อ้างจากโค้ดจริงบน branch (bd898af + 1cae64a + b8bf90d + de2e878) — ตรวจคำค้างพฤติกรรมเก่า ("upgrade-only", "ไม่อนุญาต downgrade", "ปลดห้อง") ทั้ง 3 ไฟล์แล้วแก้/mark supersede ครบ · commit ไปกับ branch นี้
