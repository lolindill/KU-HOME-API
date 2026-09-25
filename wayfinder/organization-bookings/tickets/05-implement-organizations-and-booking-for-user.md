# Implement: ตาราง organizations + admin จองแทน user

- **label:** `wayfinder:task`
- **type:** AFK
- **status:** closed
- **blocked-by:** [01-organization-table-erp-and-replaceability](./01-organization-table-erp-and-replaceability.md), [02-admin-booking-for-user-target-identity](./02-admin-booking-for-user-target-identity.md)
- **assignee:** kevii (session 2026-09-25)

## Question

ลงมือเขียนโค้ดตาม resolution ของ ticket 01 + 02 — หนึ่ง session จบ:

- migration + model `Organization` (UUID PK, ผูก `PgBoolean` ถ้ามี boolean column) ตาม contract ที่ ticket 01 ล็อก
- endpoint จัดการ organizations ตามที่ ticket 01 ตัดสิน (ใต้ `role:admin` + `CheckRole` + re-check in-controller ตาม layer rules)
- write path ของ admin booking ใน `createBooking`/`StoreBookingRequest` ตาม resolution ของ ticket 02 (2 โหมด + เฮดเปล่า · `user` = UUID · **ไม่เพิ่ม `created_by`** — ticket 02 ตัดสินแล้ว) + จุดแก้บังคับทั้งหมดที่ ticket 02 ไล่ไว้ (draft-dedup นับที่ target, room cap, ราคา daily_ku ที่ `BookingController.php:1331` ต้องเปลี่ยนเป็น target user, ownership)
- tests ครอบทุกเคสที่ตัดสินไว้ + จด design decision ลง `cline.md` ก่อนเขียนตาม protocol · ปิด ticket เมื่อ `php artisan test` เขียว

## Resolution (2026-09-25 — implemented, suite 576 เขียว)

ขอบเขต session นี้ = ticket 01 + 02 เท่านั้น — **org fields บน bookings (`organization_id` + snapshot) ยังไม่รวม** รอ ticket 03 ปิด (implement ต่อใน ticket 06)

**ตาราง organizations (ticket 01):**
- migration `2026_09_25_110000_create_organizations_table` — `id` UUID · `erp` string unique nullable · `name` · `is_active` boolean default true (**PgBoolean**) · timestamps · ไม่มี SoftDeletes/hard delete
- model `app/Models/Organization.php` (HasUuids + PgBoolean)
- controller `app/Http/Controllers/Api/V1/OrganizationController.php` — index (`search` ค้น name/erp, `is_active` filter) · show · store · update · `PATCH .../toggle` — ทั้งหมดใต้ `role:admin` (routes/api.php) + re-check in-controller (defense-in-depth) · duplicate `erp` ตรวจ closure (trim, ไม่ force case) · **ไม่มี DELETE** (405) ตาม precedent discounts

**Admin booking 2 โหมด (ticket 02):**
- `POST /bookings` ตัดสินโหมดจาก **sanctum role เท่านั้น** (ไม่ใช้ field `source` ตามธรรมเนียม BookingRule):
  - **โหมด A:** field `user` = UUID → `bookings.user_id` = target · draft-dedup นับที่ target · room cap ใช้ role ของ target (StoreBookingRequest resolve target ก่อนคำนวณ `roomCapRule`) · **ราคาแก้ chokepoint แล้ว** — `createBooking` เปลี่ยน `getEffectiveDailyRate($roomType, $request->user('sanctum'))` → `(..., $booking->user)` (target) — addRooms/updateRoom ใช้ `$booking->user` อยู่แล้วจึงถูกต้องโดยอัตโนมัติ · non-admin ส่ง `user` = 403 · UUID ไม่เจอ = 422
  - **โหมด B:** ไม่ส่ง `user` → `user_id = null` (เฮดเปล่า) · ไม่มี draft-dedup (org จองซ้อนหลายบิลได้) · ราคา daily เสมอ · ไม่โดน room cap · ownership เหลือแต่ admin
  - flow เดิม non-admin regression 0% · ไม่เพิ่ม `created_by` · advance-notice ยัง exempt ตาม sanctum admin
- design decision จดใน `cline.md` หัวข้อ "🏛️ Organizations + Admin จองแทน user" แล้ว

**Tests:** `tests/Feature/OrganizationTest.php` (11) + `tests/Feature/AdminBookingForUserTest.php` (11 — link target + daily_ku ตาม target + เฮดเปล่า + ไม่มี dedup + dedup นับที่ target + draft admin ไม่ขวาง + cap ตาม role target + โหมด B ไม่มี cap + 403 non-admin + 422 UUID มั่ว + exempt advance-notice) — **suite เต็ม 576 passed (2151 assertions)** · Pint ผ่าน
