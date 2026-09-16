---
label: ready-for-agent
title: "Booking create rules — จองล่วงหน้า ≥ 2 วัน + ลิมิต 4 ห้อง/booking (non-admin)"
status: open
assignee:
blocked-by:
---

# Spec: Booking create rules — advance notice + room-count cap

> Source: grill-me session (3 resolved questions) + code audit ของทั้ง 4 write endpoints, 2026-09-11
> นโยบาย (user-confirmed): นิยาม "2 วัน" = **calendar days** · exempt = **role admin เท่านั้น** ·
> ลิมิต 4 ห้อง = **ต่อ 1 booking** — ทุกอย่างตัดสินจาก role ของผู้ login เท่านั้น
> (field `source` `online|admin|line` เป็น client input ห้ามใช้ตัดสินสิทธิ์เด็ดขาด)

## Problem Statement

ผู้ใช้สามารถสร้างการจองที่เช็คอิน "วันนี้" หรือพรุ่งนี้ได้ทันที (validation เดิมแค่
not-in-past) ทำให้ฝ่ายปฏิบัติการไม่มีเวลาเตรียมห้อง/อนุมัติ และผู้ใช้คนเดียวสามารถยัดห้อง
ได้ไม่จำกัดใน booking เดียว (array ห้องที่ส่งมาไม่มีขอบเขต) — ทั้งที่โจทย์ธุรกิจคือ
การจองออนไลน์ต้องล่วงหน้าอย่างน้อย 2 วัน และการจองเกิน 4 ห้องต้องผ่านผู้ดูแล (admin) เท่านั้น

## Solution

บังคับ 2 กฎบน server ทุกจุดที่เขียนวันเช็คอิน/เพิ่มห้องได้:

1. **Advance notice:** non-admin ต้องมีวันเช็คอิน >= วันนี้ + 2 (calendar days, timezone
   Asia/Bangkok) — admin คงกฎเดิม (ห้ามย้อนหลัง แต่จองเช็คอินวันนี้ได้)
2. **Room cap:** non-admin จองได้ <= 4 ห้องต่อ 1 booking — บังคับตอนสร้าง และตอนเพิ่มห้อง
   ทีหลัง (นับห้องเดิมใน booking รวมกับห้องใหม่) — admin ไม่โดนจำกัด

## User Stories

1. As a regular user, I want the system to reject bookings with check-in sooner than 2 days out, so that I get a clear explanation instead of a reservation the hotel cannot prepare for.
2. As a regular user, I want to book exactly 4 rooms in one reservation, so that I can arrange a family/group trip without admin involvement.
3. As a regular user, I want a clear 422 message when my booking exceeds the room cap, so that I know to contact staff instead of guessing.
4. As a KU member, I want the same advance-notice and room-cap rules applied uniformly, so that my discount eligibility does not change the booking procedure.
5. As a user editing my draft, I want check-in changes re-checked against the advance rule, so that I cannot bypass the rule by editing after creation.
6. As a user adding rooms to my draft, I want the cap to count my existing rooms, so that I cannot exceed 4 rooms across multiple add-room calls.
7. As an admin, I want to create same-day bookings, so that I can register urgent/walk-in arrangements.
8. As an admin, I want to create bookings with more than 4 rooms, so that I can handle large groups on behalf of guests.
9. As an admin acting for a guest, I want my exemption decided by my login role, so that privilege cannot be spoofed via request fields.
10. As a front-end developer, I want both rules enforced server-side with consistent 422 responses and Thai messages, so that I only render the error the API returns.
11. As a front-end developer, I want the limits kept as configuration rather than magic numbers, so that future policy changes do not require a contract change.
12. As a hotel operator, I want availability math and state machines untouched, so that existing counting/allocation behavior stays exactly as proven.
13. As a maintainer, I want the date rule validated at the form-request layer and the cumulative room count at the controller layer, so that each rule lives where the data it needs is available.
14. As a maintainer, I want the admin check, minimum-date computation, and the room ceiling in one shared helper, so that the four write paths cannot drift apart.
15. As a maintainer, I want existing near-date tests updated without changing what they test, so that the suite still covers draft guards, ownership, and addon validation as before.
16. As a maintainer, I want the API guide and project memory updated in the same change, so that the documented contract matches the code.
17. As an API consumer, I want pre-existing drafts unaffected, so that in-flight bookings are not retroactively invalidated (dates are re-validated only on write).

## Implementation Decisions

- **นิยามเวลา:** "วันนี้" = วันที่ตามปฏิทิน Asia/Bangkok (ไม่แตะ global timezone ของแอป
  ที่เป็น UTC — ไม่งั้นช่วง 00:00–06:59 น. จะเพี้ยน 1 วัน) — กฎใหม่คือ `check_in >=
  วันนี้(Bangkok) + min_advance_days` สำหรับ non-admin
- **Exemption จาก role เท่านั้น:** ตรวจ `role === 'admin'` ของผู้ login ผ่าน sanctum guard —
  field `source` เป็น client input ใช้ตัดสินสิทธิ์ไม่ได้
- **จุดบังคับใช้ (4 write paths):** สร้าง booking · เพิ่มห้องเข้า draft · แก้ห้องรายห้อง ·
  แก้ห้องแบบ batch — กฎวันใช้กับทุกจุด (ไม่งั้นแก้ draft เป็นวันพรุ่งนี้แซงกฎได้) ·
  กฎจำนวนห้องใช้กับ สร้าง (นับจำนวนในอาร์เรย์) และ เพิ่มห้อง (ห้องเดิม + ห้องใหม่ —
  เช็คใน controller เพราะต้องรู้จำนวนปัจจุบัน) · batch edit ไม่เพิ่มจำนวนห้องจึงไม่เช็ค
- **Config-driven:** ค่า `min_advance_days = 2` และ `max_rooms_per_booking = 4` อยู่ใน
  booking config ไฟล์ใหม่ อ่านค่าจาก env ได้ (สไตล์เดียวกับ allocation config) — default
  ตรงโจทย์
- **Helper กลางหนึ่งตัว:** admin check + วันเช็คอินขั้นต่ำ + เพดานจำนวนห้อง รวมเป็น helper
  เดียวใน support namespace เดิม — ห้าม copy เงื่อนไขกระจาย 4 form requests
- **รูปแบบ error:** ผ่าน form-request validation → 422 มาตรฐาน + ข้อความไทย (สไตล์เดิม) ·
  กรณีเพิ่มห้องเกินเพดานใน controller → 422 business exception ตาม pattern เดิมของ
  add-rooms (ข้อความบอกชัดว่าจองได้สูงสุด 4 ห้องต่อการจอง หากต้องการมากกว่าให้ติดต่อผู้ดูแล)
- **Admin ยังโดนกฎเดิม:** ห้ามเช็คอินย้อนหลัง (กฎ not-in-past คงไว้ทุก role) — admin
  exempt เฉพาะ "2 วัน" กับ "4 ห้อง"
- **Grandfathering:** draft เดิมไม่ถูกตามล่า — วันที่ถูก validate ใหม่เมื่อมีการเขียนเท่านั้น
- **เอกสารไปด้วยกัน:** api guide + project memory (cline.md) อัปเดตใน change เดียวกัน

## Testing Decisions

- **Seam เดียว: HTTP API feature seam** — ยิง endpoint จริง (สร้าง / เพิ่มห้อง / แก้รายห้อง /
  แก้ batch) แล้ว assert status code, ข้อความ และแถวที่ถูกสร้าง — prior art: Booking feature
  test suite เดิม (SQLite in-memory, actingAs sanctum, helper สร้าง room type/room เป็น
  counter ต่อเลขห้อง)
- **ไม่มี unit seam แยก** ให้ helper (fewest-seams rule) — helper ถูกครอบผ่าน HTTP seam ทั้งหมด
- Test ที่ดี = assert พฤติกรรมภายนอก (422/201, ข้อความไทย, จำนวน booking_rooms ที่เกิดจริง)
  ไม่ assert internals ของ validation
- **ชุดกรณีใหม่:** non-admin +1 วัน → 422 · non-admin +2 วัน → 201 (boundary) · non-admin
  วันนี้ → 422 · non-admin 5 ห้อง → 422 · non-admin 4 ห้อง → 201 · admin +1 วัน → 201 ·
  admin 6 ห้อง → 201 · เพิ่มห้อง 3+2 → 422 · 3+1 → 201 · แก้วันเช็คอินเป็นพรุ่งนี้ (ทั้งรายห้อง
  และ batch) → 422
- **Test เดิมที่ถูกกระทบ (~17 test ใน Booking suite ที่ใช้ check-in พรุ่งนี้):** เลื่อนวันเป็น
  +2/+3 โดยรักษา "สิ่งที่ test นั้นตั้งใจทดสอบ" เดิมทุกประการ (เช่น test draft-guard ต้องยัง
  ไปโดน draft guard จริง ไม่ติดกับดัก validation ใหม่ก่อน — จุดพวกนี้รวมถึง test ที่คายัง
  403 ownership และ 404 unknown booking ด้วย เพราะ validation วิ่งก่อน controller)

## Out of Scope

- ลิมิตแบบรวมหลาย booking ต่อ user (นับเฉพาะต่อ booking เดียว — user มี active draft ได้
  1 ใบจาก existing guard อยู่แล้ว จึงใกล้เคียง global limit ในทางปฏิบัติ)
- staff/system ได้สิทธิ์พิเศษ (ตอบตามโจทย์: admin เท่านั้น — ขยายภายหลังได้ผ่าน config/role check)
- ซ่อนวัน < +2 ออกจาก availability/calendar endpoints (display concern ฝั่ง frontend)
- แก้ availability counting, state machine, throttle, routes
- flow ใหม่สำหรับ walk-in หรือ source=line
- แก้ repo frontend (ku-home)

## Further Notes

- Boundary สำคัญ: จองวันนี้ เช็คอินได้เร็วสุด = อีก 2 วันข้างหน้าตามปฏิทินไทย (ทดสอบด้วย
  +1 วัน = ต้อง reject · +2 วัน = ต้องผ่าน)
- env names ที่เสนอ: `BOOKING_MIN_ADVANCE_DAYS`, `BOOKING_MAX_ROOMS_PER_BOOKING`
- งานนี้เป็น validation-layer change ล้วน — ไม่มี migration ไม่มี schema change
- ระวัง uncommitted WIP บน branch (money-integer refactor) — ไฟล์ไม่ทับกัน แต่อย่า
  รื้อสิ่งที่คนอื่นทำค้างไว้
