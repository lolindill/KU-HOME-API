---
label: wayfinder:map
title: "tier-endpoints-v2 — split pure same-tier + ต่อยอด PUT โหมด 2/change-room (downgrade-no-refund + staff manual price)"
status: open
---

# Wayfinder Map — Tier endpoints v2

## Destination

ครอบครัว endpoint จัดการ tier/ช่วงพัก v2 บน dev: **PUT BR โหมด 2 ต่อยอด** (change-tier สำหรับ BR ไม่มีห้อง — staff, manual price, downgrade-no-refund) + **change-room ขยาย gate** (ทุก BR ที่มี room_id — confirmed|checked_in, price_per_night, downgrade-no-refund, housekeeping ceremony เฉพาะ checked_in) + **split refactor** เป็น pure same-tier (route เดิม) · suite v2 ผ่านครบไม่มี regression · docs อัปเดตครบ

## Notes (บริบทจาก map เดิม mid-stay-split-upgrade — ปิดแล้ว 2026-11-11, ไฟล์อยู่บนเครื่อง dev)

- โค้ด shipped ที่ reuse ได้: `FrontDeskController::splitUpgrade` (:627+ — recipe/audit/holdingSlot/addon re-slice/transitionStatus ceremony), `DiscountService::reprice($booking, $roomAmountOverrides = [])` (override map backward-compatible), `changeRoom` (:346+ — validate ห้อง :403, maintenance :412-421, overlap :425-438, guests :447, effective rate :456-466, ledger guard :545-570, audit :569-578), `BookingController::updateRoom` (:660+ — mode gate :682 area, allow-list โหมด 3 :732-738, ledger+is_paid :920-934), `Booking::syncIsPaid()` (recompute is_paid ตามนิยาม — precedent 💸 additional charges)
- กายภาพสำคัญ: เลขห้องผูก room_type ตายตัว → **เปลี่ยน tier บน BR ที่มีห้อง = ต้องย้ายห้องเสมอ** · BR `confirmed` อาจได้ห้องแล้วจาก auto-assign T-2 วัน (srs2-t04) · check-in รับเฉพาะ BR มีห้อง (FrontDeskController.php:230-245)
- test suite เดิม `tests/Feature/FrontDeskSplitUpgradeTest.php` 30 เคสจะ re-target ทั้งไฟล์ตาม semantics ใหม่

## Locked decisions — owner 2026-11-11 (มติ v2 6 ข้อ — ห้าม re-litigate โดยไม่มีเหตุผลใหม่)

1. **Downgrade อนุญาต + ไม่คืนเงิน** (ใช้กับ mode 2 และ change-room): total ลดได้ · `outstanding = max(0, total + charges − paid)` clamp 0 · **`is_paid` recompute ตามนิยาม** (`paid ≥ total + charges` → true — pattern `Booking::syncIsPaid()`) · ระบบไม่สร้างเงินออก (no refund ยังจริง) · ledger-guard 422 ย่อย "ลดยอดต่ำกว่า paid" **ถอดออกจากทาง tier-change ทั้งสอง** (guard เดียวกันใน path อื่นคงเดิม) · `surcharge_amount` ใน envelope ติดลบได้ (แสดงยอดที่ลดตามจริง)
2. **Manual `price_per_night`: admin + staff กรอกได้** บน mode 2 และ change-room (supersede มติ #5 เดิม admin-only) · ไม่กรอก = computed `GlobalRate::getEffectiveDailyRate` ของเจ้าของบิล (context `$booking->user`)
3. **change-tier scope = BR ไม่มี `room_id` เท่านั้น** — ทำผ่าน **PUT BR โหมด 2 ต่อยอด** (ไม่เพิ่ม endpoint) · gate: บิล `paid|confirmed` (เดิม) + BR `confirmed` + `room_id = null` · BR มี room_id ยิง mode 2 → 422 พร้อมข้อความชี้ change-room (**breaking** กับ mode 2 เดิมที่ releaseRoom ได้ — capability ย้ายไป change-room ตามข้อ 4) · `room_id` param ไม่รับใน mode 2 อีก (ส่งมา → 422)
4. **change-room gate ขยาย: ทุก BR ที่มี `room_id`** (เดิม `checked_in` เท่านั้น) — บิล `confirmed` · ทำได้ทั้ง **ย้ายเลขห้องล้วน (same type)** และ **เปลี่ยน type ควบ** · **housekeeping ceremony เฉพาะ BR `checked_in`** (ห้องเก่า → checkout_makeup + task, ห้องใหม่ → occupied — เดิมครบ) · BR `confirmed` = **re-point room_id/room_type ล้วน ไม่แตะสถานะห้องกายภาพ** ไม่สร้าง task (มติ #3 manual ceremony ของ map เดิม — slot คุมด้วย holdingSlot อยู่แล้ว) · validate ห้องใหม่คงเดิม (สถานะ available|prep_checkin|dirty, ไม่ทับ maintenance, holdingSlot overlap, type-match pin)
5. **split refactor → pure same-tier บน route เดิม** `POST /front-desk/{bookingId}/rooms/{bookingRoomId}/split-upgrade`: **ตัด** `room_type_id` + `price_per_night` + upgrade-only validation + staff pre-flight + guests-cap-vs-tier จาก request/logic · ราคา: `br1 = intdiv(room_amount₀ × n₁, n₀)`, `br2 = a₀ − br1` → **total คงเดิมเป๊ะ surcharge = 0** (invariant ต้อง guard: total หลัง split == ก่อน) · **BR-1 มี room → BR-2 ต้องได้เลขห้องใหม่ต่างเดิม**: pin (ต้อง ≠ ห้อง BR-1 + room.room_type_id == BR-1 type — 422 ถ้าซ้ำ/ผิด type) หรือ auto-allocate (**exclude ห้องของ BR-1**) · **BR-1 ไม่มี room → BR-2 `room_id = null`** ไม่ allocate (pin ล่วงหน้าได้ — validate เหมือนกัน) · คงเดิมทุกอย่างอื่น: สถานะ gate (บิล confirmed|draft / BR checked_in|confirmed / draft), split_date (window เคร่งครัด + ≥ วันนี้ tz Bangkok), บล็อกส่วนลด v1, BR-2 transitionStatus ceremony, addon re-slice (max_extra_beds เทียบ tier เดิม — tier เดียวกัน), audit machine row + note rows, `catch (\Throwable)` rollback, tz/normalize
6. **Coverage matrix (owner เพิ่มรอบสอง):** ทั้ง 3 การกระทำต้องใช้ได้บน BR `confirmed` **และ** `checked_in` — routing: split ทั้งสองสถานะ (คงเดิม) · เปลี่ยน tier: confirmed-ไม่มีห้อง → mode 2 / confirmed-มีห้อง + checked_in → change-room · ย้ายห้อง: confirmed-มีห้อง + checked_in → change-room · บิล draft คงทางเดิม (PUT โหมด 1 แก้อิสระ)
7. **change-room gate รวม BR `draft` ที่มี `room_id` ด้วย** (amendment หลัง review 01 — owner 2026-11-11): โครงสร้างจริงของระบบ = BR ก่อนเช็คอินเป็น `draft` **เสมอ** (draft→confirmed เกิดแบบ transient ตอน check-in/mark-no-show เท่านั้น — T-2 auto-assign จัดห้องแล้ว BR ยัง draft) จึงปิด dead-end: BR draft+room บนบิล paid|confirmed → change-room ได้ **พฤติกรรมเหมือน BR confirmed ทุกอย่าง** (re-point room_id/room_type ล้วน ไม่แตะสถานะห้องกายภาพ ไม่สร้าง task — isInStay=false path เดิม, money pipeline มติ ข้อ 1+2 ใช้ร่วม, audit "pre-checkin move" เดิม) · BR draft ที่**ไม่มี** room_id ยังตี change-room เหมือนเดิม (→ mode 2) · จำแนก 7 fail ของ suite เดิมใน review 01 ล้วน superseded — ticket 03 re-target

## Decisions so far

- (2026-11-11) owner sketch "split same tier + change tier อีกทาง" + grill 5 คำถาม (downgrade-no-refund / staff manual price / change-tier scope / refactor route เดิม / ต่อยอด PUT โหมด 2 ไม่เพิ่ม endpoint) + เพิ่มมติ 6 coverage matrix — มติ v2 supersede มติ #5/#11 ของ map เดิมบางส่วน (ล็อกในข้อ 2-5)
- (2026-11-11 หลัง review 01) orchestrator พบ dead-end (BR ก่อนเช็คอิน = draft เสมอ ไม่มี path ไป confirmed ก่อน check-in) → owner มติ ข้อ 7: เพิ่ม draft(+room_id) เข้า change-room gate แก้เลยใน branch นี้ — งานแนบท้าย ticket 01 (Amendment)
- (2026-11-11) tickets 01-03 ปิดครบ: implement `bd898af` + split `1cae64a` + amendment `b8bf90d` + tests `de2e878` — full suite **917 passed / 0 failed** · superseded 24 ตัว re-target ครบ · เหลือ ticket 04 (docs โดย orchestrator)

## Not yet specified

- (ว่าง — ถ้า implement เจอเคสที่ spec มืด ให้ append ที่นี่แล้วเปิด ticket ใหม่)

## Out of scope

- Refund จริง (คืนเงินออกจากระบบ) — downgrade = ยอดลด + เงินส่วนเกินคงค้างฝั่งโรงแรม ไม่มี credit/refund mechanism ใหม่
- split ตัดเป็น >2 ช่วง / split หลาย BR พร้อมกัน (ทำซ้ำ per-BR ได้)
- auto-ceremony เช็คอิน/เอาท์แทนคน (มติ #3 map เดิมยังยืน)
