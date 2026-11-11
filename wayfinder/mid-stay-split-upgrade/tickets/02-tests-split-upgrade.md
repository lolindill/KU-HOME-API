---
label: wayfinder:task
type: AFK
title: "HTTP feature suite — split-upgrade (ตาม seam ของ room-auto-assign-test)"
status: closed *(2026-11-11 — suite 30 tests/127 assertions ผ่านครบ + fix implementation bug 3 จุด, full suite 904 ไม่มี regression)*
assignee: antigravity (agent-hub, dispatch โดย session 2026-11-11)
blocked-by: ["01-implement-split-upgrade.md"]
---

# 02: Feature test suite สำหรับ split-upgrade

## Question

สร้าง `tests/Feature/FrontDeskSplitUpgradeTest.php` ครอบ contract ของ [tickets/01](01-implement-split-upgrade.md) บน SQLite :memory: — seam แบบ HTTP feature เดียวตาม decision ของ map `room-auto-assign-test` (2026-09-11) ไม่เพิ่ม unit seam

## Checklist เคส (ทั้ง 200 และ 4xx)

**Auth/scope**
- [ ] 401 ไม่ล็อกอิน · 403 role อื่น (user/guest/housekeeping — รวม owner ของบิล)
- [ ] 404 bookingRoomId ของบิลอื่น (scope ผ่านบิลเสมอ)

**Validation 422**
- [ ] container ไม่ใช่ `confirmed|draft` (`paid`/`pending`/`verify_error`/`complete`) → 422 (มติ #1 รอบสอง)
- [ ] split_date < วันนี้ → 422 (ห้ามย้อนหลัง — มติ #2 รอบสอง)
- [ ] split_date ออกนอก `(check_in, check_out)` ทั้งสองขอบ + เท่ากับ check_in/check_out เป๊ะ
- [ ] room_type_id = เดิม (ไม่ใช่ upgrade) / tier ต่ำกว่า / ไม่มีจริง
- [ ] `price_per_night` โดน staff ยิง → 422 · admin ยิงแต่ต่ำกว่า `ceil(room_amount₀/n₀)` → 422
- [ ] **(scrutinize)** pin `room_id` ที่ `room_type_id` ไม่ตรงกับ `room_type_id` ที่ส่งมา → 422 (validate ก่อนเช็คอื่น)
- [ ] **(scrutinize)** staff computed path ที่เรท tier ใหม่ × n₂ < ส่วนที่ขาดของสัญญา → 422 พร้อมข้อความแนะ admin ใส่ `price_per_night` (ไม่ใช่ข้อความ money guard ทั่วไป)
- [ ] บิลมี discount_code/redemption → 422 ข้อความบอกข้อจำกัด v1
- [ ] guests เกิน `max_guests` ของ tier ใหม่
- [ ] extra_beds_by_night คีย์ครึ่งหลังเกิน `max_extra_beds` tier ใหม่
- [ ] allocate fail (pool tier ใหม่ว่าง / pin room_id สถานะ maintenance) → 422 + **rollback ตรวจว่า BR-1 ยังมี check_out/room_amount เดิม + ไม่มี BR-2 เกิด + addon row ไม่ถูกแยก**

**Happy path (admin + staff แยกเคส)**
- [ ] checked_in วันนี้: BR-1 trim + BR-2 `confirmed` · BR-1 คง `checked_in` (ไม่มี auto ceremony — มติ #3) · ห้องกายภาพทั้งสองสถานะเดิม
- [ ] draft container: BR-2 ได้ `draft` mirror + BR-1 ยังแก้ผ่าน updateRoom โหมด 1 ได้หลัง split · และเคส draft ที่มี discount_code → 422 ตามมติ #6
- [ ] future: BR-2 confirmed + room_id ถูกยึด — ยิง check-in ห้องเดียวกันด้วย BR อื่น (บิลมือสาม) ทับช่วง → โดน overlap guard 422
- [ ] admin กรอก price_per_night: total = intdiv(room_amount₀×n₁,n₀) + price×n₂ + addon → surcharge envelope ตรงสูตร + outstanding/max(0,…) ถูก
- [ ] staff ไม่กรอก price: BR-2 ใช้ computed `getEffectiveDailyRate` ของ tier ใหม่
- [ ] addon re-slice: extra_beds_by_night แบ่งถูกครึ่ง + breakfast ยอด = sets × rate × คืนของแต่ละ BR (รวมเท่าเดิมเมื่อ rate ไม่เปลี่ยน) + early_hours คง BR-1 + late_hours ย้าย BR-2
- [ ] audit: ภายใต้บิล confirmed มี machine row `draft→confirmed` ของ BR-2 (จาก transitionStatus) + note rows ของ BR-1/BR-2 พร้อม note + causer (ภายใต้บิล draft = note rows อย่างเดียว)
- [ ] invariant `Σ booking_rooms.amount == total_amount` + booking_type mirror ไม่พัง
- [ ] บิล multi-room: BR อื่นในบิลไม่ถูกแตะ room_id · availability ของบิลนับถูกหลัง split (holdingSlot ทั้งคู่)
- [ ] split ซ้ำบน BR-2 (ที่ checked_in แล้ว) สำเร็จ
- [ ] deferred bill: split ผ่าน + outstanding เพิ่มตาม surcharge

**Money guard (มติ #10 scrutinize — pin ทุก BR)**
- [ ] **pin-freeze invariant:** บิล multi-room ที่จำนวน BR ข้าม 5/10 หลังเพิ่ม BR-2 → `room_amount` ของ BR อื่น **คงเดิมทุกตัว** (group flip ไม่กระทบยอด — ตัด test "group flip → 422" เดิมทิ้ง)
- [ ] pair guard: admin manual price ที่ถูกสูตร → ผ่าน · สร้างสถานการณ์ fail (manual < ceil(a₀/n₀) กัน validator ไม่จับ — เช่นยิง staff computed ที่เรทต่ำ) → 422 + rollback ครบ
- [ ] **ledger guard + is_paid:** บิล paid ครบ (is_paid=true) → split บวกยอด → `is_paid` reset false + outstanding เพิ่มถูกตัว (pattern :920-934)
- [ ] **บิล overpay** (paid > total+charges): split ผ่าน ไม่ 422 — ledger guard ไม่ false-fire + outstanding clamp 0 คงอยู่
- [ ] **(มติ #9 scrutinize — float semantics)** split แล้วเปลี่ยน global_rates ของ tier เก่า/ใหม่ แล้วเรียก updateRoom โหมด 3 เพิ่ม addon บน BR-1 → `room_amount` ของ BR-1/BR-2 **ถูกคิดใหม่จากเรทใหม่** (ราคาตกลงตอน split ไม่รอด — ยืนยันพฤติกรรมตามที่จดในเอกสาร ไม่ใช่ bug)

## Definition of done

suite ใหม่ผ่านครบ + `composer run test` (full suite) ไม่มี regression · รายงานจำนวนเคส/assertions ไว้ใน resolution ตามธรรมเนียม map

## Resolution (2026-11-11)

Suite สร้างโดย antigravity (`tests/Feature/FrontDeskSplitUpgradeTest.php` — 30 tests / 127 assertions ผ่านครบ) · session ของมันหยุดกลางทาง (รายงาน "fallback problem") เพราะเจอ implementation bug — หยุดถูกต้องตามกติกา "อย่าแก้ app/ เอง" · orchestrator (ZCode) รับช่วงตรวจ + fix + verify:

**Bugs ฝั่ง implementation ที่พิสูจน์ด้วย test แล้วแก้ (commit `ff4be74`):**
1. **`allocate(collect([$br2]))` TypeError** — RoomAllocator type-hint `Eloquent\Collection` แต่ `collect()` ให้ `Support\Collection` · TypeError ไม่ใช่ \Exception → หลุด catch → transaction ค้างเปิด → test ถัด ๆ ไป cascade fail "already an active transaction" · แก้เป็น `new \Illuminate\Database\Eloquent\Collection([$br2])` (ครอบ instance จริง — hydrate ไม่เวิร์ก ทำสำเนา detached attribute หลุด) + เปลี่ยน catch เป็น `\Throwable` (rollback กันทุกกรณี)
2. **late_hours ได้ 0 เสมอ** — โค้ด `$addon->update(['late_hours' => 0])` ก่อนแล้วอ่าน `$addon->late_hours` ไปใส่ BR-2 (instance โดน mutate แล้ว) · แก้: capture ค่าก่อน update
3. **ledger guard false-fire บิล overpay** — precedent literal `new+charges < paid → 422` โดนบิลที่ overpay อยู่ก่อนแล้ว · แก้ overpay-safe: ยิงเฉพาะเมื่อสมดุลหลัง split แย่ลงกว่าก่อน split (`new+charges < paid && previous+charges >= paid`) — ตรง edge "บิล overpay ผ่าน ไม่ 422" ของ spec

**Bugs ฝั่ง test ที่แก้:** assertSame บน UUID ไม่ cast `(string)` (gotcha AGENTS.md) ที่ happy path

**ผล:** suite ใหม่ 30 tests / 127 assertions ผ่านครบ · full suite **904 tests / 3,797 assertions** (874 → 904) ไม่มี regression · pint สะอาด

**ครอบคลุม checklist:** auth/scope (401/403/404), validation 422 ทุกข้อ (container/split_date อดีต+ขอบ/room_type/price staff+admin/pin type ก่อนเช็คอื่น/computed pre-flight/discount/guests cap/extra beds cap/allocate fail + rollback ตรวจ BR-1 คงเดิม), happy path (admin checked_in/draft+discount/future holdingSlot/manual price envelope/staff computed/addon reslice/audit 2 แถว+machine row/invariant Σamount/multi-room freeze/repeat split/deferred), money guard (pin-freeze ข้าม 5-10/pair guard fail rollback/ledger guard+is_paid reset/overpay clamp 0/float semantics มติ #9)
