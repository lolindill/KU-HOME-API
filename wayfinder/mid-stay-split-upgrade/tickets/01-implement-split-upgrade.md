---
label: wayfinder:task
type: AFK
title: "implement POST /front-desk/{bookingId}/rooms/{bookingRoomId}/split-upgrade"
status: open
assignee:
blocked-by: []
---

# 01: Implement split-upgrade endpoint

## Question

สร้าง endpoint แบ่งช่วงพักครึ่งหนึ่งย้ายขึ้น tier สูงกว่า ตาม spec ล็อก 8 มติของ [map](../map.md) (อ่าน map ก่อนเสมอ) — ทำงานบน feature branch แล้ว merge เข้า `dev`

## Contract

```
POST /api/v1/front-desk/{bookingId}/rooms/{bookingRoomId}/split-upgrade
Roles: admin + staff (middleware `role:admin,staff` + defense-in-depth re-check ใน controller ตาม pattern FrontDeskController.php:157-164)
Throttle: ไม่ใส่ (ตรงตาม change-room)

Request:
{
  "split_date":     "2026-11-15",   // required date — ต้องอยู่ใน (check_in, check_out) เคร่งครัด ครึ่งละ ≥ 1 คืน + ≥ วันนี้ (ห้ามย้อนหลัง — มติ #2 รอบสอง)
  "room_type_id":   uuid,           // required — ต้องเป็น tier สูงกว่า (มติ #5)
  "price_per_night": int,           // optional — ได้เฉพาะ admin; ไม่ส่ง = computed
  "room_id":        uuid|null,      // optional — pin ห้อง BR-2; ไม่ส่ง = auto-allocate
  "notes":          string|null
}

Response (สำเร็จ): surcharge envelope แบบ updateRoom โหมด 3 (BookingController.php:966, 980-986)
+ split_summary { br1_id, br2_id, br1_new: {check_out, room_amount}, br2: {check_in, check_out, room_type_id, room_id, price_per_night, nights} }
+ previous_total_amount / total_amount / surcharge_amount / paid_amount / outstanding_amount / payment_type / deposit_amount
Error: envelope {"status":"error","message":...} + 422/403/404 ตามเคส (ห้าม leak $e->getMessage() บน 500)
```

## Recipe (ตามลำดับ — ภายใน `DB::transaction` เดียว + lock บิล/ห้องเก่า/ห้องใหม่แบบ changeRoom :389-399)

1. **Load + scope:** บิลจาก bookingId, BR จากบิลนั้นเท่านั้น (404 ถ้าข้ามบิล — pattern :175-176) · **container ต้อง `confirmed|draft`** (`paid`/`pending`/`verify_error`/`complete` → 422) · BR: ภายใต้บิล `confirmed` = `checked_in|confirmed` · ภายใต้บิล `draft` = `draft` (มติ #1 รอบสอง)
2. **บล็อก v1:** บิลมี `discount_code` หรือ `discount_redemptions` (held/used) ใด ๆ → 422 (มติ #6)
3. **Validate split_date:** ใน `(check_in, check_out)` เคร่งครัด (มติ #2)
4. **Validate upgrade-only (มติ #5):** `room_type_id` ใหม่ ≠ เดิม + เรท effective (`GlobalRate::getEffectiveDailyRate` — copy FrontDeskController.php:456-466) ต้อง **มากกว่า** ของเดิม · ถ้ากรอก `price_per_night`: ต้องเป็น admin + `price_per_night ≥ ceil(room_amount₀ / n₀)` (อัปเกรดจริงเทียบสัญญา; n₀ = คืนเดิมของ BR-1) · staff กรอกมา → 422
5. **Trim BR-1:** `check_out = split_date` · **ห้าม**เปลี่ยน status (มติ #3) · n₁ = คืนใหม่
6. **ราคา BR-1 สัญญาสัดส่วน:** `room_amount_1 = intdiv(room_amount₀ × n₁, n₀)` (มติ #5 — ไม่คำนวณจาก global_rates)
7. **สร้าง BR-2:** คัดลอก `guests`/`bed_preference` (ถ้า bed option ไม่มีใน tier ใหม่ → null) · `check_in = split_date`, `check_out = เดิม` · status = **mirror บริบท** (`confirmed` ภายใต้บิล confirmed / `draft` ภายใต้บิล draft — BR `confirmed` ใต้บิล draft เป็นสถานะที่ state machine เข้าไม่ถึง ห้ามสร้าง) · `room_type_id` ใหม่ · `room_id = null` ก่อน · validate `count(guests) ≤ max_guests` ของ tier ใหม่ (422 ถ้าเกิน — บอกให้ลดก่อน)
8. **Addon carry + re-slice (มติ #7):** ย้าย row addon ของ BR-1 → แยกเป็น 2 rows: BR-1 คง `breakfast_set_100/200` + `extra_beds_by_night` คีย์ `< split_date` + `early_hours` · BR-2 ได้ `breakfast_set_100/200` เดิม + คีย์ `≥ split_date` (validate qty ≤ `room_types.max_extra_beds` ของ tier ใหม่ → เกิน 422) + `late_hours` ย้ายมา BR-2 · early/late snapshot price ให้ reprice เขียนทับเอง
9. **จัดห้อง BR-2 (มติ #8):** ส่ง `room_id` → validate ตาม changeRoom (สถานะ `available|prep_checkin|dirty` :403 · ไม่ทับ `RoomStatePeriod` KIND_MAINTENANCE :412-421 · overlap `holdingSlot()` ยกเว้น BR ของบิลนี้หลัง trim :425-438) · ไม่ส่ง → `RoomAllocator::allocate([$br2])` · fail ทั้งสองทาง → **throw rollback ทั้ง transaction 422** (ไม่หลงเหลือ BR-2 ครึ่ง ๆ)
10. **Reprice ผ่าน override:** เรียก `DiscountService::reprice($booking, override)` — **แก้ `reprice()` รับ optional override map** (เช่น `[brId => ['room_amount' => int]])` หรือแยก method `repriceSplit()` ที่ใช้สูตรเดียวกัน — เลือกแบบที่แตะ chokepoint น้อยสุด) · **ค่า override ใช้กับ BR-1/BR-2 เท่านั้น**, BR อื่นของบิลคงสูตรเดิมได้ (เบิ้ม: BR อื่นจะถูกคำนวณจาก global_rates วันนี้ตามเดิม — ยอมรับพฤติกรรมเดียวกับ change-room ที่ reprice ทั้งบิล) · `RoundToTen`/addon totals/snapshot ทำงานปกติ
11. **Money guard airtight (มติ #4):** เทียบ **ส่วนค่าห้องรวมก่อนปัด**: `br1.room_amount_new + br2.room_amount ≥ room_amount₀` — exact integer (สูตร intdiv สัดส่วน + upgrade check ครอบฝั่ง BR-2 แล้ว) · **ไม่เทียบ total หลัง RoundToTen** (artifact ปัดสองครั้งเด้ง ±5 บาททำ 422 มั่ว — deviation จาก change-room ที่เทียบ total, อธิบายเหตุผลใน comment + cline.md) · ถ้า fail → rollback 422
12. **Audit:** เขียน `StatusChangeLog` 2 แถว from=to (pattern changeRoom :569-578): BR-1 note `split-upgrade: check_out trimmed to {date}, price {old}→{new}` · BR-2 note `split-upgrade: spawned from {br1_id}, tier {old_en}→{new_en}, {price_per_night หรือ computed} × {n2} nights` + causer = auth user
13. **ห้ามแตะสถานะห้องกายภาพ** (มติ #3): ห้องเก่าคงสถานะเดิม (occupied/อะไรก็ตาม) จน front desk เช็คเอาท์จริง · ห้องใหม่คงสถานะเดิมจนเช็คอินจริง — slot ป้องกันโดย holdingSlot + check-in overlap guard (FrontDeskController.php:230-245) ครอบอยู่แล้ว
14. คง convention ทั้งหมด: Thai error messages + emoji, `PgBoolean` ไม่เกี่ยว (ไม่มี bool), money integer baht, `vendor/bin/pint --dirty` ก่อน commit

## Edge cases ที่ต้องครอบ

- BR-1 `confirmed` ที่ `room_id = null` (ก่อน allocate): split ได้ — BR-2 ก็จัดห้องตามข้อ 9 ปกติ (BR-1 ยังไม่มีห้องไม่เป็นปัญหา)
- **บิล draft:** BR-2 ได้สถานะ `draft` (ยังแก้ผ่าน updateRoom โหมด 1 ได้หลัง split) · ระวัง: บิล draft มักมี discount_code อยู่แล้ว → โดนบล็อกมติ #6 บ่อย (ตั้งใจ) · `CleanupExpiredDrafts` (ทุก 5 นาที) อาจลบบิลเกิน deadline ระหว่างทาง — transaction + lock ครอบอยู่แล้ว race window แคบ ยอมรับได้
- split_date < วันนี้ → 422 (มติ #2 รอบสอง) · อย่าเพิ่ม date validation ใน checkIn/checkOutRoom (พิธีจริงยัง manual — มติ #3)
- split ซ้ำบน BR-2 ที่เคยเกิดจาก split (checked_in แล้ว): อนุญาต — เป็น feature เดียวกันธรรมดา
- บิล `payment_type = 'deferred'`: split ได้ปกติ (surcharge เข้า outstanding เก็บทีหลัง — ประตูเงินตอนเช็คเอาท์ยกเว้น deferred อยู่แล้ว :652-667)
- `nights` normalize startOfDay ทั้งคู่ก่อน diffInDays (pattern :199-202 — ห้ามปล่อย float คืน)
- บิล admin-path ที่จำนวน BR ข้าม 5/10 หลังเพิ่ม BR-2: ไม่เกี่ยวกับ BR-1/BR-2 (override) แต่ BR อื่นในบิลที่ reprice ตามสูตรเดิมอาจเปลี่ยนราคา — ถ้าทำ total รวมลดจน guard ตัวจริง (หลังรวม addon) ตก ให้ 422 fail-safe + message แสดงเลขก่อน/หลังให้ตรวจได้

## Definition of done

Endpoint ตาม contract ครบทุก validate · guard ผ่านทุก edge ข้างบน · audit 2 แถว · ไม่มี migration ใหม่ · pint สะอาด · full suite ไม่มี regression (suite ของ tickets/02 มาปิดทีหลัง — ticket นี้อย่างน้อยรัน suite เดิมผ่าน)
