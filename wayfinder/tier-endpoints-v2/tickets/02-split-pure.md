---
label: wayfinder:task
type: AFK
title: "split-upgrade refactor → pure same-tier split (route เดิม)"
status: closed *(2026-11-11 — refactor 1cae64a — review ผ่าน)*
assignee: antigravity (agent-hub, session เดียวกับ ticket 01)
blocked-by: []
---

# 02: Split refactor — pure same-tier

## Question

Refactor `FrontDeskController::splitUpgrade` (:627+ บน dev) เป็น **pure split same-tier** ตาม [map](../map.md) มติ v2 ข้อ 5, 6 — route เดิม `POST /front-desk/{bookingId}/rooms/{bookingRoomId}/split-upgrade` เปลี่ยน semantics (owner ตัดสินแล้ว)

## Contract (ใหม่)

```
POST /api/v1/front-desk/{bookingId}/rooms/{bookingRoomId}/split-upgrade
Roles: admin + staff (เดิม) · ไม่มี throttle (เดิม)

Request:
{
  "split_date":  "2026-11-15",  // required — (check_in, check_out) เคร่งครัด + ≥ วันนี้ (tz Bangkok) — เดิม
  "room_id":     "uuid|null",   // optional — pin ห้อง BR-2 (ต้อง type เดียวกับ BR-1 + ≠ ห้อง BR-1 ถ้า BR-1 มีห้อง)
  "notes":       "string|null"
}
// ตัดออก: room_type_id, price_per_night — ส่งมา → 422 (ตรวจก่อน validate อื่น)

Response (สำเร็จ): envelope เดิม + split_summary (br2 ไม่มี room_type_id/price_per_night — room_id จริงที่จัด) · surcharge_amount = 0 เสมอ
```

## Recipe (แก้จากโค้ด shipped — คงโครง transaction/lock/audit เดิมทั้งหมด)

1. Gate สถานะ/บล็อกส่วนลด/split_date/nights normalize: **คงเดิมทุกข้อ** จาก shipped code (มติ #1-#4, #6 map เดิม + scrutinize)
2. **ตัด** upgrade-only validation, staff pre-flight, computed rate path, guests-cap-vs-tier — tier เดียวกันไม่มีปัญหาพวกนี้
3. ราคา: `br1 = intdiv(a₀ × n₁, n₀)` + `br2 = a₀ − br1` (remainder ให้ BR-2) → override map **pin ทุก BR** (BR อื่น freeze — เดิม) → **guard invariant: total หลัง split == total ก่อนเป๊ะ (surcharge 0)** — ถ้าไม่เท่า = bug ข้างใน ให้ throw 500-style rollback (กัน drift อนาคต)
4. **ห้อง BR-2 ตามมติ v2 ข้อ 5:** BR-1 มี room → BR-2 ต้องได้เลขใหม่ต่างเดิม: pin (validate room.room_type_id == BR-1 room_type + room_id ≠ BR-1 room_id — ซ้ำ/ผิด type 422 ก่อนเช็คอื่น) หรือ auto-allocate `RoomAllocator` **exclude ห้อง BR-1** (เติม exclusion ใน pool ก่อนเรียก หรือกรองหลัง — เลือกแบบแตะ allocator น้อยสุด) · BR-1 ไม่มี room + ไม่ pin → BR-2 `room_id = null` · BR-1 ไม่มี room + pin → validate pin ปกติแล้วใช้
5. BR-2 สร้าง draft + transitionStatus ceremony, addon re-slice (breakfast คัดลอก, extra_beds_by_night แบ่ง + validate max_extra_beds — **tier เดิม**, late/early), audit machine row + note rows 2 แถว (note อัปเดต: เล่าการแบ่งช่วง same-tier + เลขห้องเก่า→ใหม่ ถ้ามี), catch `\Throwable`, envelope เดิม — **คงเดิมทุกอย่าง** จาก shipped
6. คง convention: Thai+emoji, tz Bangkok, money int, pint --dirty, commit บน branch ปัจจุบัน · ห้ามแตะ tests/

## Edge cases คงเดิมจาก suite shipped (เดิมผ่าน — อย่าพัง)

split_date อดีต/ขอบ/นอกช่วง · บิล paid/pending/verify_error/complete → 422 · บิล draft BR-2 mirror draft · discount v1 block · repeat split บน BR-2 · deferred ผ่าน · multi-room freeze (override pin) · allocate fail rollback ครบ · float: ไม่มีแล้ว (total คงที่) — **เคส float semantics เดิม (มติ #9) ไม่ apply กับ split อีก** (total ไม่เปลี่ยน) แต่ยังจริงกับ tier-change family (ticket 01)

## Definition of done

endpoint semantics ใหม่ครบ + guard invariant total คงที่ · pint สะอาด · full suite เดิมผ่าน — suite เดิม 30 เคสของ split จะ **fail ทั้งก้อนที่ assert ของใหม่** (room_type_id ฯลฯ) — นับเป็น expected: **หยุดรายงาน** อย่าแก้ tests เอง (ticket 03 re-target) · เคส suite เดิมที่ครอบพฤติกรรมคงเดิม (สถานะ/ส่วนลด/rollback/audit) ต้องยังผ่าน

## Resolution (2026-11-11)

Implement โดย antigravity (session เดียวกับ ticket 01) — commit **`1cae64a`**:
- ตัด room_type_id + price_per_night — ตรวจ `$request->has()` **ก่อน validate อื่น** → 422 ชี้ PUT โหมด 2 / change-room
- สูตร `br1 = intdiv(a₀ × n₁, n₀)` + `br2 = a₀ − br1` + **guard invariant** total เพี้ยน → throw rollback
- BR-2 room: pin ตรวจ ≠ ห้อง BR-1 (ก่อน type) → type → status → maintenance → overlap ตามลำดับ · auto-allocate exclude ห้อง BR-1 ผ่าน **param ใหม่ `allocate(..., array $excludeRoomIds = [])`** (backward-compatible — `RoomAllocator::loadRoomPool` เติม `whereNotIn`)
- BR-1 ไม่มี room: pin validate ปกติ / ไม่ pin → room_id null
- คงเดิม: gate สถานะ, split_date window + tz, บล็อกส่วนลด, addon re-slice (late capture), ceremony BR-2, audit note rows (เล่า same-tier + เลขห้องเก่า→ใหม่), catch \Throwable, envelope (surcharge_amount = 0)
- GlobalRate import ถอดสะอาด (ไม่เหลือผู้ใช้ในไฟล์)

Review (ZCode): ตรง มติ v2 ข้อ 5 ทุกจุด · pint สะอาด · suite 881 ผ่าน / 16 fail ของ split suite เดิมจำแนกครบว่าล้วนส่ง payload เก่า → superseded (ticket 03 re-target) · ไม่มี impl bug
