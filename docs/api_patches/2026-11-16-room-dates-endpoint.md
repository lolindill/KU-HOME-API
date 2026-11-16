# 📢 API Patch — 2026-11-16 · Room dates endpoint (ย้ายชุดวันเข้าพัก — shift-only)

> **สถานะ:** additive — **ไม่มี breaking change** · **ผู้กระทบ:** admin/staff console ที่ต้องเลื่อนวันเข้าพักบนบิลที่จ่ายแล้ว
> **อ่านคู่:** [`api_guide.md`](../api_guide.md) — หัวข้อ `PUT /front-desk/{bookingId}/rooms/{bookingRoomId}/dates` · มติล็อกใน `cline.md` หัวข้อ "📅 Change dates (ย้ายชุดวันเข้าพัก — shift-only)"

---

## TL;DR

1. **Endpoint ใหม่** `PUT /api/v1/front-desk/{bookingId}/rooms/{bookingRoomId}/dates` — ย้าย `check_in`+`check_out` ไปชุดวันใหม่บนบิล `paid|confirmed` + BR `confirmed` · **admin+staff** เท่านั้น · ไม่มี throttle
2. **Shift-only:** จำนวนคืนชุดใหม่ต้องเท่าชุดเดิมเป๊ะ — อยากเปลี่ยนจำนวนคืน: BR **ไม่มี**เลขห้อง → ใช้ `PUT /bookings/{id}/rooms/{brId}` (โหมด post-payment เดิม) ได้อยู่แล้ว
3. **BR มีเลขห้อง = ปลดล็อกช่องว่างเดิม** — ก่อนหน้านี้บิล confirmed ที่จัดห้องแล้ว**ไม่มีทาง**ขยับวันเลย (PUT BR โหมด 2 ปิด BR มีห้อง · change-room เปลี่ยนเลขห้องไม่ขยับวันที่) — endpoint นี้ strict same-room: ห้องเดิมต้องว่างช่วงใหม่ ไม่ว่าง → `422` ชี้ change-room (**ไม่ auto เปลี่ยนห้องให้**)

---

## 🟢 Contract ใหม่

| | ค่า |
|---|---|
| Body | `check_in` + `check_out` **required ทั้งคู่** (target ชุดใหม่) · `notes` optional |
| Gate | container `paid\|confirmed` · BR `confirmed` · `room_id` **nullable** (มี/ไม่มีห้องก็ได้) |
| ห้ามส่ง | `room_id` / `room_type_id` / `price_per_night` → `422` (single-intent) |
| วันที่ | `check_in` ≥ วันนี้ (Asia/Bangkok) · ชุดใหม่ซ้ำชุดเดิม → `422` |
| เพดานคืน | staff ≥ 20 คืน → `422` 🌙 · ทุก role > 90 คืน → `422` 🗓️ (คงเขตเดิมแม้คืนไม่เปลี่ยน) |
| ห้อง (มีเลขห้อง) | strict: overlap `holdingSlot()` + maintenance period ช่วงใหม่ → `422` · **reserved ไม่บล็อก** · ไม่มีห้อง → ข้ามตรวจ |
| เงิน | reprice ทั้งบิลจากเรทวันนี้ (caveat ตระกูล tier-change — ตรวจ envelope `surcharge_amount` ทุกครั้ง) + `syncIsPaid()` · ส่วนต่างไหลเข้า `outstanding_amount` (ไม่มีคืนเงิน) |
| Audit | `StatusChangeLog` note row (from=to=BR status) — วันเดิม→ใหม่ + delta + ราคาเดิม→ใหม่ |
| สถานะ | **ไม่ transition สถานะใด** · addon `extra_beds_by_night` เลื่อน key ตาม delta คง qty เดิม |

## Response `200` (envelope)

```json
{
  "status": "success",
  "message": "ย้ายชุดวันเข้าพักเรียบร้อยแล้วค่ะนายท่าน 📅✨ (2026-11-16–2026-11-20 → 2026-11-20–2026-11-24) คงห้อง 401 เดิม",
  "booking_id": "…", "booking_room_id": "…",
  "room_number": "401",
  "old_check_in": "2026-11-16", "old_check_out": "2026-11-20",
  "check_in": "2026-11-20", "check_out": "2026-11-24",
  "nights": 4,
  "previous_total_amount": 4000, "total_amount": 4000,
  "surcharge_amount": 0, "paid_amount": 4000, "outstanding_amount": 0
}
```

## ข้อความ `422` ที่ต้อง expect

```json
{ "status": "error", "message": "ย้ายชุดวันเข้าพักต้องคงจำนวนคืนเดิมไว้ค่ะนายท่าน (เดิม 4 คืน · ชุดใหม่ 5 คืน) — หากต้องการเปลี่ยนจำนวนคืน กรุณาใช้ PUT /bookings/{id}/rooms/{brId} แทนนะคะ 📅" }
{ "status": "error", "message": "ย้ายวันเข้าพักไม่รองรับ room_id, room_type_id หรือ price_per_night ค่ะนายท่าน — เปลี่ยนห้องใช้ change-room · เปลี่ยนประเภทห้อง/ราคาใช้ PUT โหมด 2 แทนนะคะ 📅" }
{ "status": "error", "message": "ห้องหมายเลข 401 มีการจองอื่นทับช่วงวันใหม่ (…–…) ไม่สามารถย้ายวันเข้าได้ค่ะนายท่าน หากต้องการชุดวันนี้ กรุณาใช้ change-room ย้ายไปห้องอื่นนะคะ" }
```

> อย่า hard-code parse ข้อความ — ใช้ HTTP code + `status` field

## 🔗 อ้างอิง

- Implement: `RoomMoveController::changeDates()` + route `PUT /front-desk/{bookingId}/rooms/{bookingRoomId}/dates`
- Tests: `tests/Feature/RoomDatesChangeTest.php` — 19 cases (401/403/404, gates, shift-only, monthly caps, overlap/maintenance, happy admin+staff, monthly pricing, addon delta-shift) · suite **958 passed (4,030 assertions)** + `vendor/bin/pint --dirty` PASS
- มติ: endpoint เฉพาะย้ายวันทั้งชุด คืนคงเดิม · BR confirmed room_id nullable · strict same-room ไม่ auto-allocate · reserved ไม่บล็อก — ล็อกใน `cline.md` หัวข้อ "📅 Change dates"
