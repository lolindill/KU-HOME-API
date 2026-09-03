---
label: wayfinder:task
type: task
title: Shared satang→baht formatting helper (prefactor)
status: closed
assignee: maid
blocked-by: []
---

# 01: Shared satang→baht formatting helper (prefactor)

**What to build:** helper กลางตัวเดียวที่รับ integer satang แล้วคืน string บาท 2 ตำแหน่ง (เช่น `120000` → `"1200.00"`, `0` → `"0.00"`) — ทำให้ "เปลี่ยนให้ง่ายก่อน แล้วค่อยทำสิ่งที่ง่าย" ก่อนที่ ticket ถัดไปจะเอาไปใช้ทั่วทั้ง rates object และ `extra_bed_price` ที่ขอบ API ยังไม่มีอะไร user-visible เปลี่ยนในใบนี้นอกจาก helper + test ของมันเอง

**Blocked by:** None (can start immediately)

**Status:** closed

- [x] helper คืน 2-dp string เสมอ (ไม่มี comma, ไม่มี float — กัน 0.1+0.2 artifacts)
- [x] edge cases: 0, ค่าลบ (ถ้ารองรับ ระบุชัด), จำนวนเต็ม satang ใด ๆ → format ถูกต้อง
- [x] test ครอบ helper โดยตรง (หน่วยเล็กสุด เพราะเป็น prefactor ที่เหลือทั้งหมดพึ่งพา)
- [x] ไม่แตะ behavior ของ endpoint เดิมใด ๆ (CI เขียวเท่าเดิม)

## Resolution

สร้าง `App\Support\Money::satangToBaht(int $satang): string` โดยใช้ pure integer arithmetic (`intdiv` และ `%`) ไม่มี float precision artifacts พร้อม unit test `Tests\Unit\Support\MoneyTest` ครอบคลุมทั้ง zero, single/double digit satang, standard amounts, large amounts และ negative amounts ผ่านฉลุย 100%
