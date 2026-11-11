# 📢 API Patch — 2026-11-11 · Tier endpoints v2 (split pure same-tier + change-tier family)

> **Merge:** `6317413` (feature/tier-endpoints-v2 → dev) · **ผู้กระทบ:** ทุก client ที่เรียก split-upgrade / PUT booking room / change-room
> **อ่านคู่:** [`api_guide.md`](../api_guide.md) (contract เต็มฉบับปัจจุบัน) · มติ v2 ทั้งหมดล็อกใน `cline.md` หัวข้อ "🔀 Tier-change family v2"

---

## TL;DR

1. **`split-upgrade` เปลี่ยนเป็นแบ่งช่วงพัก same-tier ล้วน** — tier เดิมเท่านั้น **total ทั้งบิลคงเดิมเป๊ะ (surcharge = 0)** · ส่ง `room_type_id` / `price_per_night` → `422` ทันที
2. **การเปลี่ยน tier ย้ายไป 2 endpoint เดิม:** ไม่มีเลขห้อง → `PUT /bookings/{id}/rooms/{brId}` (โหมด change-tier) · มีเลขห้อง → `POST /front-desk/.../change-room`
3. **Downgrade (ปรับลด tier) อนุญาตทุกทาง — ระบบไม่คืนเงิน** — ยอดส่วนเกินกลายเป็นเครดิตคงค้างของบิล (`is_paid` กลับ `true` ได้)

---

## 🔴 Breaking changes

### 1. `POST /front-desk/{bookingId}/rooms/{bookingRoomId}/split-upgrade` — contract ใหม่ทั้งหมด

| | เดิม (v1 — shipped 2026-11-11 เช้า) | ใหม่ (v2) |
|---|---|---|
| `room_type_id` | required — tier ใหม่ต้องแพงกว่า | ❌ **ถูกตัด — ส่งมา → `422`** (ตรวจก่อน validate อื่น) |
| `price_per_night` | nullable (admin, ≥ เรทสัญญาเฉลี่ย) | ❌ **ถูกตัด — ส่งมา → `422`** |
| `split_date` | required (คงเดิม) | required (คงเดิม) |
| `room_id` (pin) | type ต้องตรง tier ใหม่ | type ต้องตรง tier เดิม **+ ≠ ห้อง BR-1** (BR-1 มีห้อง) |
| ผลลัพธ์เงิน | surcharge เข้า outstanding | **`surcharge_amount` = 0 เสมอ · `total_amount` = ก่อน split เป๊ะ** (มี invariant guard — เพี้ยน = rollback) |
| `split_summary.br2` | มี `room_type_id` + `price_per_night` | **ไม่มี field ทั้งสอง** (เหลือ `check_in/check_out/room_id/nights`) |

**ข้อความ 422 ที่ต้อง expect:**
```json
{ "status": "error", "message": "การแบ่งช่วงพักเป็นแบบประเภทห้องเดิม (same-tier) เท่านั้น ไม่รองรับ room_type_id หรือ price_per_night แล้วค่ะนายท่าน — หากต้องการเปลี่ยนประเภทห้อง กรุณาใช้ PUT โหมด 2 หรือ change-room แทนนะคะ 🚪" }
```

**🔄 Migration ของ flow เดิม "split ขึ้น tier ครึ่งหลัง" (เช่น 4 คืน Standard, 2 คืนหลังขึ้น Deluxe):** ทำ **2 ขั้น**
1. `split-upgrade` (same-tier) แบ่งสองช่วงก่อน
2. เปลี่ยน tier ช่วงที่ต้องการผ่าน `change-room` บน BR-2 หรือ BR-1 (ดูข้อ 3)

### 2. `PUT /bookings/{bookingId}/rooms/{bookingRoomId}` — โหมด change-tier (บิล `paid|confirmed`)

- 🔴 **scope เฉพาะ BR ที่ `room_id = null`** — BR ที่ถูกจัดเลขห้องแล้ว → `422` "…กรุณาใช้ change-room ค่ะ 🚪" (**เดิม**: upgrade ได้ด้วยการปลดห้อง — capability นี้ถูกย้ายไป change-room)
- 🔴 ส่ง param `room_id` → `422` ("ไม่สามารถระบุเลขห้องผ่านการแก้ไขห้องได้…")
- 🔴 **เลิกกติกา upgrade-only + ledger-guard** — `room_type_id` **ลงได้** · ลดยอดต่ำกว่าเงินที่จ่าย **ไม่ 422 แล้ว** (ดู §เงิน)
- 🟢 role: `admin` → **`admin` + `staff`**
- 🟢 field ใหม่: `price_per_night` (nullable int baht — admin+staff) · ไม่กรอก = คิดจาก `GlobalRate::getEffectiveDailyRate` ของเจ้าของบิล
- 🟢 guests cap ข้อความใหม่แนะทางแก้ (ปรับลดแขกในคำขอเดียวกันได้) · `extra_beds_by_night` รายคืน ≤ `max_extra_beds` tier ใหม่ → `422`
- Audit note row ใหม่: `change-tier (upgrade|downgrade|same-tier) · tier X → Y · price A → B บาท`

### 3. `POST /front-desk/{bookingId}/rooms/{bookingRoomId}/change-room`

- 🔄 **gate ขยาย:** BR `checked_in` (บิล `confirmed`) **หรือ BR `confirmed`/`draft` ที่มี `room_id`** (บิล `paid|confirmed` — ครอบกรณี T-2 auto-assign จัดห้องแล้ว BR ยัง `draft`)
- 🔴 **เลิก upgrade-only:** type ใหม่แพง/ถูกกว่าได้ — ตัวอย่าง response 422 "เปลี่ยนประเภทห้องได้เฉพาะประเภทที่แพงกว่า…" **ไม่มีแล้ว อย่า hard-code parse**
- 🟢 field ใหม่: `price_per_night` (nullable int baht, admin+staff)
- 🔄 **ceremony แยกตามสถานะ:** `checked_in` = ห้องเก่า `dirty` + task แม่บ้าน + ห้องใหม่ `occupied` (เดิม) · **`confirmed`/`draft` = re-point `room_id`/`room_type_id` ล้วน — สถานะห้องกายภาพเก่า/ใหม่ไม่เปลี่ยน ไม่มี task** → `housekeeping_task_id` = `null` ใน response กรณีนี้
- Audit note row: prefix "ย้ายห้องก่อนเช็คอิน (pre-checkin move)" เมื่อ BR ไม่ใช่ `checked_in` + direction + ราคาเดิม→ใหม่

---

## 🟡 เงิน semantics ใหม่ (ทั้ง change-tier และ change-room)

```
outstanding = max(0, total + charges − paid)   ← ลดยอดจนต่ำกว่าเงินที่จ่าย = clamp 0 (ไม่คืนเงิน)
is_paid       = recompute จาก paid ≥ total + charges  ผ่าน Booking::syncIsPaid()
                → ลด tier แล้วจ่ายครบพอดี: is_paid กลับ true ได้
surcharge_amount ติดลบได้ (ค่าลดยอด — เดิม guard 422 บล็อกทางนี้ทั้งหมด)
```

⚠️ **reprice คิดจาก global_rates วันนี้ "ทั้งบิล"** — manual `price_per_night` pin เฉพาะ BR ที่แก้เท่านั้น ยอดรวมอาจเปลี่ยนแม้แตะห้องเดียว (บิลที่เคย split: ราคาสัญญาสัดส่วนตอน split **ไม่รอด** reprice ครั้งถัดไป) — **front desk ต้องตรวจ surcharge envelope หลัง tier-change ทุกครั้ง**

---

## Error matrix ที่เพิ่ม/เปลี่ยน

| Endpoint | Case | HTTP | ข้อความ (ย่อ) |
|---|---|---|---|
| split-upgrade | ส่ง `room_type_id`/`price_per_night` | 422 | "…same-tier เท่านั้น ไม่รองรับ… ใช้ PUT โหมด 2 หรือ change-room 🚪" |
| split-upgrade | pin ห้องซ้ำห้อง BR-1 | 422 | "…เป็นห้องเดิมของช่วงแรก กรุณาเลือกห้องอื่น 🚪" |
| split-upgrade | pin ผิด type (ตรวจก่อน status/maintenance/overlap) | 422 | "…ไม่ตรงกับประเภทห้องเดิม…" |
| PUT โหมด 2 | BR มี `room_id` | 422 | "…มีเลขห้องจัดไว้แล้ว… ใช้ change-room 🚪" |
| PUT โหมด 2 | ส่ง param `room_id` | 422 | "…ไม่สามารถระบุเลขห้องผ่านการแก้ไขห้องได้… 🚪" |
| PUT โหมด 2 | guests เกิน tier ใหม่ | 422 | "…ปรับลดผู้เข้าพักในคำขอแก้ไขนี้ หรือเลือกประเภทห้องอื่นได้ค่ะ 👥" |
| PUT โหมด 2 | extra_beds คืนใดเกิน cap | 422 | "…เกินขีดสุดของประเภทห้อง… 🛏️" |
| PUT โหมด 2 | staff (role ใหม่) | 200 | — (เดิม 403) |
| change-room | BR `draft` **ไม่มี**ห้อง | 422 | "…ยังไม่มีเลขห้องจริงที่จัดไว้…" (ไป PUT โหมด 2) |
| change-room | BR `no_show`/`checked_out` | 422 | gate message ระบุสถานะ 🚪 |
| ~~ทั้งสอง~~ | ~~ลดยอดต่ำกว่า paid~~ | ~~422~~ | ❌ ถูกถอด — ผ่านตาม §เงิน |

---

## ตัวอย่าง (โค้ดจริง)

**Split same-tier — Standard 4 คืน 4,000 → ครึ่งละ 2 คืน 2,000:**
```json
// POST /front-desk/{bookingId}/rooms/{br1Id}/split-upgrade
{ "split_date": "2026-11-15" }
// → 200: split_summary.br1_new.room_amount = 2000 · br2.room_amount = 2000
//   previous_total_amount = 4000 · total_amount = 4000 · surcharge_amount = 0
```

**Change-tier (BR ไม่มีห้อง — staff ยิงได้):**
```json
// PUT /bookings/{bookingId}/rooms/{brId}
{ "room_type_id": "deluxe-uuid", "price_per_night": 1500 }
// → 200: surcharge_amount ติดลบได้ · outstanding clamp · is_paid sync แล้ว
```

**Change-room ก่อนเช็คอิน (BR draft หลัง T-2 จัดห้อง):**
```json
// POST /front-desk/{bookingId}/rooms/{brId}/change-room
{ "room_id": "new-room-uuid", "price_per_night": 1500 }
// → 200: BR ยัง draft · สถานะห้องเก่า/ใหม่ไม่เปลี่ยน · housekeeping_task_id = null
//   message prefix "ย้ายห้องก่อนเช็คอิน (pre-checkin move)"
```

---

## สำหรับฝั่ง backend (internal note)

- `RoomAllocator::allocate()` เพิ่ม param ที่ 3 optional: `array $excludeRoomIds = []` — caller เดิมไม่กระทบ
- Suite v2: **917 tests / 3,861 assertions** — re-target ครบ 24 เคสพฤติกรรมเก่า (`FrontDeskSplitUpgradeTest` + `ChangeRoomTest` + `BookingSurchargeAfterPaymentTest` + ใหม่ `TierChangeFamilyTest` 12 เคส)
